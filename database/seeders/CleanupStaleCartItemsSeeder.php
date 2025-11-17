<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupStaleCartItemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder cleans up:
     * 1. Expired cart transactions (older than 1 hour, still pending)
     * 2. Associated cart items
     * 3. Associated bookings
     * 4. Notifies waitlist users
     */
    public function run(): void
    {
        $this->command->info('🧹 Starting cleanup of stale cart items...');

        // Define expiration threshold (1 hour ago)
        $expirationThreshold = Carbon::now()->subHour();

        DB::beginTransaction();

        try {
            // Find all stale cart transactions
            $staleTransactions = CartTransaction::where('approval_status', 'pending')
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '<', $expirationThreshold)
                ->with(['cartItems', 'bookings', 'user'])
                ->get();

            $this->command->info("Found {$staleTransactions->count()} stale cart transactions");

            $stats = [
                'transactions_rejected' => 0,
                'cart_items_rejected' => 0,
                'bookings_rejected' => 0,
                'waitlist_notified' => 0,
            ];

            foreach ($staleTransactions as $transaction) {
                $this->command->info("Processing Transaction #{$transaction->id}...");

                // Reject the cart transaction
                $transaction->update([
                    'approval_status' => 'rejected',
                    'status' => 'cancelled',
                    'rejection_reason' => 'Automatically rejected: Payment timeout (1 hour expired)',
                    'approved_at' => now()
                ]);
                $stats['transactions_rejected']++;

                $this->command->info("  - Rejected cart transaction #{$transaction->id}");

                // Reject all associated cart items
                $cartItemsCount = CartItem::where('cart_transaction_id', $transaction->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->update([
                        'status' => 'rejected',
                        'admin_notes' => 'Automatically rejected: Payment timeout'
                    ]);
                $stats['cart_items_rejected'] += $cartItemsCount;

                $this->command->info("  - Rejected {$cartItemsCount} cart items");

                // Reject all associated bookings
                $bookingsCount = Booking::where('cart_transaction_id', $transaction->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->update([
                        'status' => 'rejected',
                        'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAutomatically rejected: Payment timeout (1 hour expired)')")
                    ]);
                $stats['bookings_rejected'] += $bookingsCount;

                $this->command->info("  - Rejected {$bookingsCount} bookings");

                // Process waitlist for each rejected booking
                $rejectedBookings = Booking::where('cart_transaction_id', $transaction->id)
                    ->where('status', 'rejected')
                    ->get();

                foreach ($rejectedBookings as $booking) {
                    // Find waitlist entries for this booking
                    $waitlistEntries = BookingWaitlist::where('pending_booking_id', $booking->id)
                        ->where('status', BookingWaitlist::STATUS_PENDING)
                        ->orderBy('position')
                        ->orderBy('created_at')
                        ->get();

                    if ($waitlistEntries->isNotEmpty()) {
                        $this->command->info("    - Found {$waitlistEntries->count()} waitlist entries for Booking #{$booking->id}");

                        // Only notify the first person in line (Position #1)
                        $firstInLine = $waitlistEntries->first();

                        $firstInLine->update([
                            'status' => BookingWaitlist::STATUS_NOTIFIED,
                            'notified_at' => now(),
                            'expires_at' => Carbon::now()->addHour() // 1 hour to complete payment
                        ]);

                        $stats['waitlist_notified']++;

                        $waitlistUserName = $firstInLine->user ? $firstInLine->user->name : 'N/A';
                        $this->command->info("    - Notified waitlist user: {$waitlistUserName} (Position #{$firstInLine->position})");

                        // Send email notification
                        if ($firstInLine->user && $firstInLine->user->email) {
                            try {
                                \Mail::to($firstInLine->user->email)
                                    ->send(new \App\Mail\WaitlistNotificationMail($firstInLine));
                                $this->command->info("    - Email sent to {$firstInLine->user->email}");
                            } catch (\Exception $e) {
                                $this->command->warn("    - Failed to send email: {$e->getMessage()}");
                            }
                        }
                    }
                }

                // Log cleanup
                Log::info('Stale cart transaction cleaned up', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $transaction->user_id,
                    'total_price' => $transaction->total_price,
                    'created_at' => $transaction->created_at,
                    'age_hours' => $transaction->created_at->diffInHours(now()),
                ]);
            }

            DB::commit();

            $this->command->info('');
            $this->command->info('✅ Cleanup completed successfully!');
            $this->command->info('');
            $this->command->table(
                ['Metric', 'Count'],
                [
                    ['Cart Transactions Rejected', $stats['transactions_rejected']],
                    ['Cart Items Rejected', $stats['cart_items_rejected']],
                    ['Bookings Rejected', $stats['bookings_rejected']],
                    ['Waitlist Users Notified', $stats['waitlist_notified']],
                ]
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Cleanup failed: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
            throw $e;
        }
    }
}
