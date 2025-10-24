<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\CartItem;
use App\Models\CartTransaction;
use Carbon\Carbon;

class CreateCartTransactionsForBookingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates cart_transaction and cart_item records for bookings
     * that don't have associated cart transactions or cart items. This fixes
     * data inconsistencies where bookings were created directly without going
     * through the cart flow, or where cart items were not properly created.
     *
     * Handles two scenarios:
     * 1. Bookings without cart_transaction_id → creates both transaction and cart_item
     * 2. Bookings with cart_transaction_id but missing cart_item → creates cart_item only
     *
     * @return void
     */
    public function run()
    {
        $this->command->info('Starting to create cart transactions and cart items for bookings...');
        $this->command->newLine();

        // Scenario 1: Find bookings that have no associated cart transaction
        $bookingsWithoutTransaction = Booking::whereNull('cart_transaction_id')
            ->with(['user', 'court', 'sport'])
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->get();

        // Scenario 2: Find bookings that have cart_transaction but no corresponding cart_item
        $bookingsWithoutCartItem = Booking::whereNotNull('cart_transaction_id')
            ->whereDoesntHave('cartTransaction.cartItems', function ($query) {
                // This checks if there's no cart_item for this specific booking
                // We need to ensure cart_items exist for the transaction
            })
            ->with(['user', 'court', 'sport', 'cartTransaction'])
            ->orderBy('cart_transaction_id')
            ->get()
            ->filter(function ($booking) {
                // Additional filter: check if a cart_item exists matching this booking's details
                return !CartItem::where('cart_transaction_id', $booking->cart_transaction_id)
                    ->where('court_id', $booking->court_id)
                    ->where('booking_date', Carbon::parse($booking->start_time)->toDateString())
                    ->where('start_time', Carbon::parse($booking->start_time)->toTimeString())
                    ->where('end_time', Carbon::parse($booking->end_time)->toTimeString())
                    ->exists();
            });

        $totalBookings = $bookingsWithoutTransaction->count() + $bookingsWithoutCartItem->count();

        if ($totalBookings === 0) {
            $this->command->info('✓ No bookings found that need cart transactions or cart items.');
            return;
        }

        $this->command->info("Found {$bookingsWithoutTransaction->count()} booking(s) without cart transactions.");
        $this->command->info("Found {$bookingsWithoutCartItem->count()} booking(s) without cart items.");
        $this->command->newLine();

        $createdTransactions = 0;
        $createdCartItems = 0;
        $errorCount = 0;
        $processedBookings = [];

        // SCENARIO 1: Process bookings without cart transactions
        // Group bookings by user and approximate time to potentially batch them together
        // This creates more realistic cart transactions as users typically book multiple slots at once
        if ($bookingsWithoutTransaction->isNotEmpty()) {
            $this->command->info('=== SCENARIO 1: Creating cart transactions and cart items ===');
            $this->command->newLine();

            $groupedBookings = $this->groupBookingsByUserAndTime($bookingsWithoutTransaction);

            foreach ($groupedBookings as $groupKey => $bookings) {
                $firstBooking = $bookings->first();
                $this->command->info("Processing group: User ID {$firstBooking->user_id} with {$bookings->count()} booking(s)");

                DB::beginTransaction();
                try {
                    // Calculate total price for all bookings in this group
                    $totalPrice = $bookings->sum('total_price');

                    // Create cart transaction using the first booking's details
                    $cartTransaction = CartTransaction::create([
                        'user_id' => $firstBooking->user_id,
                        'booking_for_user_id' => $firstBooking->booking_for_user_id,
                        'booking_for_user_name' => $firstBooking->booking_for_user_name,
                        'booking_waitlist_id' => $firstBooking->booking_waitlist_id,
                        'total_price' => $totalPrice,
                        'status' => $this->determineTransactionStatus($firstBooking),
                        'approval_status' => $this->determineApprovalStatus($firstBooking),
                        'approved_by' => null, // Can't determine this from booking alone
                        'approved_at' => $this->determineApprovedAt($firstBooking),
                        'payment_method' => $firstBooking->payment_method ?? 'pending',
                        'payment_status' => $firstBooking->payment_status ?? 'unpaid',
                        'proof_of_payment' => $firstBooking->proof_of_payment,
                        'paid_at' => $firstBooking->paid_at,
                        'qr_code' => $firstBooking->qr_code,
                        'attendance_status' => $firstBooking->attendance_status,
                        'created_at' => $firstBooking->created_at,
                        'updated_at' => $firstBooking->updated_at,
                    ]);

                    $createdTransactions++;
                    $this->command->info("  ✓ Created Cart Transaction ID {$cartTransaction->id}");

                    // Create cart items and update bookings
                    foreach ($bookings as $booking) {
                        // Extract date and time from booking's datetime fields
                        $bookingDate = Carbon::parse($booking->start_time)->toDateString();
                        $startTime = Carbon::parse($booking->start_time)->toTimeString();
                        $endTime = Carbon::parse($booking->end_time)->toTimeString();

                        // Create cart item
                        $cartItem = CartItem::create([
                            'user_id' => $booking->user_id,
                            'booking_for_user_id' => $booking->booking_for_user_id,
                            'booking_for_user_name' => $booking->booking_for_user_name,
                            'cart_transaction_id' => $cartTransaction->id,
                            'booking_waitlist_id' => $booking->booking_waitlist_id,
                            'court_id' => $booking->court_id,
                            'sport_id' => $booking->sport_id,
                            'booking_date' => $bookingDate,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'price' => $booking->total_price,
                            'number_of_players' => $booking->number_of_players ?? 1,
                            'notes' => $booking->notes,
                            'admin_notes' => $booking->admin_notes,
                            'status' => 'booked', // Cart item is already converted to booking
                            'session_id' => null,
                            'created_at' => $booking->created_at,
                            'updated_at' => $booking->updated_at,
                        ]);

                        $createdCartItems++;
                        $this->command->info("  ✓ Created Cart Item ID {$cartItem->id} for Booking ID {$booking->id}");

                        // Update booking with cart_transaction_id
                        $booking->cart_transaction_id = $cartTransaction->id;
                        $booking->save();

                        $processedBookings[] = [
                            'booking_id' => $booking->id,
                            'transaction_id' => $cartTransaction->id,
                            'cart_item_id' => $cartItem->id,
                            'court' => $booking->court->name ?? "Court {$booking->court_id,
                            'start_time' => $booking->start_time,
                            'end_time' => $booking->end_time,
                            'price' => $booking->total_price,
                            'status' => $booking->status,
                            'user' => $booking->user->name ?? 'Unknown',
                            'scenario' => 1,
                        ];

                        $this->command->info("  ✓ Updated Booking ID {$booking->id} with cart_transaction_id");
                    }

                    DB::commit();
                    $this->command->info("  Group completed successfully.");
                    $this->command->newLine();

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->command->error("  Error processing group: " . $e->getMessage());
                    $this->command->error("  Stack trace: " . $e->getTraceAsString());
                    $errorCount++;
                }
            }

            $this->command->info("Scenario 1 processing complete.");
            $this->command->newLine();
        }

        // SCENARIO 2: Process bookings that have cart_transaction but missing cart_item
        if ($bookingsWithoutCartItem->isNotEmpty()) {
            $this->command->info('=== SCENARIO 2: Creating cart items for existing transactions ===');
            $this->command->newLine();

            foreach ($bookingsWithoutCartItem as $booking) {
                $this->command->info("Processing Booking ID {$booking->id} (Transaction ID: {$booking->cart_transaction_id})");

                DB::beginTransaction();
                try {
                    // Extract date and time from booking's datetime fields
                    $bookingDate = Carbon::parse($booking->start_time)->toDateString();
                    $startTime = Carbon::parse($booking->start_time)->toTimeString();
                    $endTime = Carbon::parse($booking->end_time)->toTimeString();

                    // Create cart item for the existing transaction
                    $cartItem = CartItem::create([
                        'user_id' => $booking->user_id,
                        'booking_for_user_id' => $booking->booking_for_user_id,
                        'booking_for_user_name' => $booking->booking_for_user_name,
                        'cart_transaction_id' => $booking->cart_transaction_id,
                        'booking_waitlist_id' => $booking->booking_waitlist_id,
                        'court_id' => $booking->court_id,
                        'sport_id' => $booking->sport_id,
                        'booking_date' => $bookingDate,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'price' => $booking->total_price,
                        'number_of_players' => $booking->number_of_players ?? 1,
                        'notes' => $booking->notes,
                        'admin_notes' => $booking->admin_notes,
                        'status' => 'booked', // Cart item is already converted to booking
                        'session_id' => null,
                        'created_at' => $booking->created_at,
                        'updated_at' => $booking->updated_at,
                    ]);

                    $createdCartItems++;
                    $this->command->info("  ✓ Created Cart Item ID {$cartItem->id}");

                    // Update transaction total_price if needed
                    $transaction = $booking->cartTransaction;
                    $currentTotalFromItems = $transaction->cartItems()->sum('price');
                    if ($currentTotalFromItems != $transaction->total_price) {
                        $transaction->total_price = $currentTotalFromItems;
                        $transaction->save();
                        $this->command->info("  ✓ Updated Transaction total_price to ₱" . number_format($currentTotalFromItems, 2));
                    }

                    $processedBookings[] = [
                        'booking_id' => $booking->id,
                        'transaction_id' => $booking->cart_transaction_id,
                        'cart_item_id' => $cartItem->id,
                        'court' => $booking->court_id,
                        'start_time' => $booking->start_time,
                        'end_time' => $booking->end_time,
                        'price' => $booking->total_price,
                        'status' => $booking->status,
                        'user' => $booking->user->name ?? 'Unknown',
                        'scenario' => 2,
                    ];

                    DB::commit();
                    $this->command->info("  Booking processed successfully.");
                    $this->command->newLine();

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->command->error("  Error processing Booking ID {$booking->id}: " . $e->getMessage());
                    $this->command->error("  Stack trace: " . $e->getTraceAsString());
                    $errorCount++;
                }
            }

            $this->command->info("Scenario 2 processing complete.");
            $this->command->newLine();
        }

        // Summary
        $this->command->newLine();
        $this->command->info('=================================');
        $this->command->info('Seeding Complete!');
        $this->command->info('=================================');
        $scenario1Count = collect($processedBookings)->where('scenario', 1)->count();
        $scenario2Count = collect($processedBookings)->where('scenario', 2)->count();

        $this->command->info("Bookings without cart_transaction processed (Scenario 1): {$scenario1Count}");
        $this->command->info("Bookings without cart_item processed (Scenario 2): {$scenario2Count}");
        $this->command->info("Total bookings processed: " . count($processedBookings));
        $this->command->info("Cart transactions created: {$createdTransactions}");
        $this->command->info("Cart items created: {$createdCartItems}");
        $this->command->info("Errors encountered: {$errorCount}");
        $this->command->newLine();

        // Display processed bookings table
        if (!empty($processedBookings)) {
            $this->command->info('Details of processed bookings:');
            $this->command->table(
                ['Scenario', 'Booking ID', 'Transaction ID', 'Cart Item ID', 'Court', 'Start Time', 'End Time', 'Price', 'Status', 'User'],
                array_map(function($booking) {
                    return [
                        $booking['scenario'],
                        $booking['booking_id'],
                        $booking['transaction_id'],
                        $booking['cart_item_id'],
                        $booking['court'],
                        $booking['start_time'],
                        $booking['end_time'],
                        '₱' . number_format($booking['price'], 2),
                        $booking['status'],
                        $booking['user'],
                    ];
                }, $processedBookings)
            );
        }
    }

    /**
     * Group bookings by user and approximate time to create realistic cart transactions
     * Bookings created within 5 minutes of each other for the same user are grouped together
     *
     * @param \Illuminate\Support\Collection $bookings
     * @return array
     */
    private function groupBookingsByUserAndTime($bookings)
    {
        $grouped = [];
        $timeWindow = 5; // minutes

        foreach ($bookings as $booking) {
            $userId = $booking->user_id;
            $createdAt = Carbon::parse($booking->created_at);

            // Find existing group for this user within time window
            $foundGroup = false;
            foreach ($grouped as $key => &$group) {
                if (strpos($key, "user_{$userId}_") === 0) {
                    $groupTime = Carbon::parse($group->first()->created_at);
                    if ($createdAt->diffInMinutes($groupTime) <= $timeWindow) {
                        $group->push($booking);
                        $foundGroup = true;
                        break;
                    }
                }
            }

            // Create new group if none found
            if (!$foundGroup) {
                $groupKey = "user_{$userId}_" . $createdAt->timestamp;
                $grouped[$groupKey] = collect([$booking]);
            }
        }

        return $grouped;
    }

    /**
     * Determine the appropriate transaction status based on the booking status
     *
     * @param Booking $booking
     * @return string
     */
    private function determineTransactionStatus(Booking $booking): string
    {
        switch ($booking->status) {
            case Booking::STATUS_APPROVED:
            case Booking::STATUS_CHECKED_IN:
            case Booking::STATUS_COMPLETED:
                return 'completed';
            case Booking::STATUS_CANCELLED:
            case Booking::STATUS_REJECTED:
                return 'cancelled';
            case Booking::STATUS_PENDING:
            default:
                return 'pending';
        }
    }

    /**
     * Determine the appropriate approval status based on the booking status
     *
     * @param Booking $booking
     * @return string
     */
    private function determineApprovalStatus(Booking $booking): string
    {
        switch ($booking->status) {
            case Booking::STATUS_APPROVED:
            case Booking::STATUS_CHECKED_IN:
            case Booking::STATUS_COMPLETED:
                return 'approved';
            case Booking::STATUS_REJECTED:
                return 'rejected';
            case Booking::STATUS_PENDING:
            default:
                return 'pending';
        }
    }

    /**
     * Determine the approved_at timestamp based on the booking
     *
     * @param Booking $booking
     * @return \Carbon\Carbon|null
     */
    private function determineApprovedAt(Booking $booking)
    {
        if (in_array($booking->status, [
            Booking::STATUS_APPROVED,
            Booking::STATUS_CHECKED_IN,
            Booking::STATUS_COMPLETED
        ])) {
            // Use paid_at if available, otherwise use updated_at
            return $booking->paid_at ?? $booking->updated_at;
        }

        return null;
    }
}
