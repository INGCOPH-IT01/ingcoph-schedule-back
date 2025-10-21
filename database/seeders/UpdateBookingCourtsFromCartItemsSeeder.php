<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\CartItem;
use App\Models\CartTransaction;

class UpdateBookingCourtsFromCartItemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder fixes booking records where the court_id doesn't match
     * the corresponding cart item's court_id. This issue occurred when
     * admins changed a booking's court through the cart item update,
     * but the booking record wasn't updated accordingly.
     *
     * @return void
     */
    public function run()
    {
        $this->command->info('Starting to sync booking courts with cart items...');

        // Get all bookings that have a cart_transaction_id
        $bookingsWithTransactions = Booking::whereNotNull('cart_transaction_id')
            ->whereIn('status', ['pending', 'approved', 'completed'])
            ->with(['cartTransaction.cartItems'])
            ->get();

        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        $issuesFound = [];

        foreach ($bookingsWithTransactions as $booking) {
            try {
                // Get the cart transaction
                $cartTransaction = $booking->cartTransaction;

                if (!$cartTransaction) {
                    $this->command->warn("Booking ID {$booking->id}: Cart transaction not found");
                    $skippedCount++;
                    continue;
                }

                // Find matching cart item by time slot
                $matchingCartItem = $cartTransaction->cartItems()
                    ->where('booking_date', '=', DB::raw('DATE(bookings.start_time)'))
                    ->where(function($query) use ($booking) {
                        $startTime = \Carbon\Carbon::parse($booking->start_time)->format('H:i:s');
                        $endTime = \Carbon\Carbon::parse($booking->end_time)->format('H:i:s');

                        $query->where('start_time', $startTime)
                              ->where('end_time', $endTime);
                    })
                    ->first();

                if (!$matchingCartItem) {
                    // Try to find by sport_id and booking date as fallback
                    $matchingCartItem = $cartTransaction->cartItems()
                        ->where('booking_date', '=', DB::raw('DATE(bookings.start_time)'))
                        ->where('sport_id', $booking->sport_id)
                        ->first();
                }

                if (!$matchingCartItem) {
                    $this->command->warn("Booking ID {$booking->id}: No matching cart item found");
                    $skippedCount++;
                    continue;
                }

                // Check if court_id differs
                if ($booking->court_id !== $matchingCartItem->court_id) {
                    $oldCourtId = $booking->court_id;
                    $newCourtId = $matchingCartItem->court_id;

                    // Update the booking's court_id
                    $booking->court_id = $newCourtId;
                    $booking->save();

                    $issuesFound[] = [
                        'booking_id' => $booking->id,
                        'old_court_id' => $oldCourtId,
                        'new_court_id' => $newCourtId,
                        'start_time' => $booking->start_time,
                        'end_time' => $booking->end_time,
                        'user' => $booking->user->name ?? 'Unknown',
                    ];

                    $updatedCount++;
                    $this->command->info("Updated Booking ID {$booking->id}: Court {$oldCourtId} → {$newCourtId}");
                }
            } catch (\Exception $e) {
                $this->command->error("Error processing Booking ID {$booking->id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        $this->command->newLine();
        $this->command->info('=================================');
        $this->command->info('Sync Complete!');
        $this->command->info('=================================');
        $this->command->info("Total bookings checked: " . $bookingsWithTransactions->count());
        $this->command->info("Bookings updated: {$updatedCount}");
        $this->command->info("Bookings skipped: {$skippedCount}");
        $this->command->info("Errors encountered: {$errorCount}");
        $this->command->newLine();

        if (!empty($issuesFound)) {
            $this->command->info('Details of updated bookings:');
            $this->command->table(
                ['Booking ID', 'Old Court', 'New Court', 'Start Time', 'End Time', 'User'],
                array_map(function($issue) {
                    return [
                        $issue['booking_id'],
                        $issue['old_court_id'],
                        $issue['new_court_id'],
                        $issue['start_time'],
                        $issue['end_time'],
                        $issue['user'],
                    ];
                }, $issuesFound)
            );
        }
    }
}
