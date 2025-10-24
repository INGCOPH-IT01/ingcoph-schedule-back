<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\CartItem;
use App\Models\CartTransaction;
use Carbon\Carbon;

class CreateBookingsForCartTransactionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates booking records for cart_transactions that don't have
     * any associated bookings. This fixes data inconsistencies where transactions
     * were created but bookings were not properly generated.
     *
     * @return void
     */
    public function run()
    {
        $this->command->info('Starting to create bookings for cart transactions with no bookings...');

        // Find cart transactions that have no associated bookings
        $transactionsWithoutBookings = CartTransaction::doesntHave('bookings')
            ->with(['cartItems.court', 'cartItems.sport', 'user'])
            ->get();

        if ($transactionsWithoutBookings->isEmpty()) {
            $this->command->info('No cart transactions found without bookings.');
            return;
        }

        $this->command->info("Found {$transactionsWithoutBookings->count()} cart transaction(s) without bookings.");
        $this->command->newLine();

        $createdBookings = 0;
        $skippedItems = 0;
        $errorCount = 0;
        $bookingsCreated = [];

        foreach ($transactionsWithoutBookings as $transaction) {
            $this->command->info("Processing Cart Transaction ID: {$transaction->id}");

            if ($transaction->cartItems->isEmpty()) {
                $this->command->warn("  Transaction ID {$transaction->id}: No cart items found");
                continue;
            }

            DB::beginTransaction();
            try {
                foreach ($transaction->cartItems as $cartItem) {
                    // Validate that the cart item has all required data
                    if (!$cartItem->court_id || !$cartItem->booking_date || !$cartItem->start_time || !$cartItem->end_time) {
                        $this->command->warn("  Cart Item ID {$cartItem->id}: Missing required fields, skipping");
                        $skippedItems++;
                        continue;
                    }

                    // Create booking date-time strings
                    $startDateTime = Carbon::parse($cartItem->booking_date)
                        ->setTimeFromTimeString($cartItem->start_time);
                    $endDateTime = Carbon::parse($cartItem->booking_date)
                        ->setTimeFromTimeString($cartItem->end_time);

                    // Create the booking
                    $booking = Booking::create([
                        'user_id' => $cartItem->user_id,
                        'booking_for_user_id' => $cartItem->booking_for_user_id,
                        'booking_for_user_name' => $cartItem->booking_for_user_name,
                        'cart_transaction_id' => $transaction->id,
                        'booking_waitlist_id' => $cartItem->booking_waitlist_id ?? $transaction->booking_waitlist_id,
                        'court_id' => $cartItem->court_id,
                        'sport_id' => $cartItem->sport_id,
                        'start_time' => $startDateTime,
                        'end_time' => $endDateTime,
                        'total_price' => $cartItem->price,
                        'number_of_players' => $cartItem->number_of_players ?? 1,
                        'status' => $this->determineBookingStatus($transaction),
                        'notes' => $cartItem->notes,
                        'admin_notes' => $cartItem->admin_notes,
                        'payment_method' => $transaction->payment_method,
                        'payment_status' => $transaction->payment_status,
                        'paid_at' => $transaction->paid_at,
                        'proof_of_payment' => $transaction->proof_of_payment,
                        'qr_code' => $transaction->qr_code,
                        'attendance_status' => $transaction->attendance_status,
                    ]);

                    $bookingsCreated[] = [
                        'booking_id' => $booking->id,
                        'transaction_id' => $transaction->id,
                        'court_id' => $booking->court_id,
                        'start_time' => $booking->start_time->format('Y-m-d H:i'),
                        'end_time' => $booking->end_time->format('Y-m-d H:i'),
                        'price' => $booking->total_price,
                        'status' => $booking->status,
                        'user' => $transaction->user->name ?? 'Unknown',
                    ];

                    $createdBookings++;
                    $this->command->info("  ✓ Created Booking ID {$booking->id} for Cart Item ID {$cartItem->id}");
                }

                DB::commit();
                $this->command->info("  Transaction ID {$transaction->id} completed successfully.");
                $this->command->newLine();

            } catch (\Exception $e) {
                DB::rollBack();
                $this->command->error("  Error processing Transaction ID {$transaction->id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        // Summary
        $this->command->newLine();
        $this->command->info('=================================');
        $this->command->info('Seeding Complete!');
        $this->command->info('=================================');
        $this->command->info("Transactions processed: {$transactionsWithoutBookings->count()}");
        $this->command->info("Bookings created: {$createdBookings}");
        $this->command->info("Cart items skipped: {$skippedItems}");
        $this->command->info("Errors encountered: {$errorCount}");
        $this->command->newLine();

        // Display created bookings table
        if (!empty($bookingsCreated)) {
            $this->command->info('Details of created bookings:');
            $this->command->table(
                ['Booking ID', 'Transaction ID', 'Court', 'Start Time', 'End Time', 'Price', 'Status', 'User'],
                array_map(function($booking) {
                    return [
                        $booking['booking_id'],
                        $booking['transaction_id'],
                        $booking['court_id'],
                        $booking['start_time'],
                        $booking['end_time'],
                        '₱' . number_format($booking['price'], 2),
                        $booking['status'],
                        $booking['user'],
                    ];
                }, $bookingsCreated)
            );
        }
    }

    /**
     * Determine the appropriate booking status based on the cart transaction
     *
     * @param CartTransaction $transaction
     * @return string
     */
    private function determineBookingStatus(CartTransaction $transaction): string
    {
        // If transaction has approval_status, use that
        if (isset($transaction->approval_status)) {
            switch ($transaction->approval_status) {
                case 'approved':
                    return Booking::STATUS_APPROVED;
                case 'rejected':
                    return Booking::STATUS_REJECTED;
                case 'pending':
                default:
                    return Booking::STATUS_PENDING;
            }
        }

        // Fallback to transaction status
        switch ($transaction->status) {
            case 'completed':
                return Booking::STATUS_APPROVED;
            case 'cancelled':
                return Booking::STATUS_CANCELLED;
            default:
                return Booking::STATUS_PENDING;
        }
    }
}
