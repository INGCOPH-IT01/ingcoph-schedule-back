<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCartStatusesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder syncs statuses between:
     * - CartTransaction <-> CartItems
     * - CartTransaction <-> Bookings
     * - Ensures data integrity across related records
     */
    public function run(): void
    {
        $this->command->info('🔄 Starting status synchronization...');

        DB::beginTransaction();

        try {
            $stats = [
                'cart_items_updated' => 0,
                'transactions_updated' => 0,
                'bookings_updated' => 0,
                'waitlist_processed' => 0,
            ];

            // ========================================
            // STEP 1: Sync CartItems with rejected/cancelled CartTransactions
            // ========================================
            $this->command->info('');
            $this->command->info('📋 Step 1: Finding rejected/cancelled transactions with pending cart items...');

            $rejectedTransactions = CartTransaction::whereIn('approval_status', ['rejected'])
                ->orWhere('status', 'cancelled')
                ->with('cartItems')
                ->get();

            $this->command->info("Found {$rejectedTransactions->count()} rejected/cancelled transactions");

            foreach ($rejectedTransactions as $transaction) {
                // Find cart items that don't match transaction status
                $pendingItems = $transaction->cartItems()
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->get();

                if ($pendingItems->isNotEmpty()) {
                    $this->command->info("  Transaction #{$transaction->id}: Syncing {$pendingItems->count()} cart items");

                    // Update cart items to match transaction status
                    $newStatus = $transaction->approval_status === 'rejected' ? 'rejected' : 'cancelled';

                    CartItem::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->update([
                            'status' => $newStatus,
                            'admin_notes' => DB::raw("CONCAT(COALESCE(admin_notes, ''), '\n\nAuto-synced: Transaction was {$newStatus}')")
                        ]);

                    $stats['cart_items_updated'] += $pendingItems->count();
                }

                // Sync bookings too
                $pendingBookings = $transaction->bookings()
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->get();

                if ($pendingBookings->isNotEmpty()) {
                    $this->command->info("  Transaction #{$transaction->id}: Syncing {$pendingBookings->count()} bookings");

                    $newStatus = $transaction->approval_status === 'rejected' ? 'rejected' : 'cancelled';

                    Booking::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->update([
                            'status' => $newStatus,
                            'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAuto-synced: Transaction was {$newStatus}')")
                        ]);

                    $stats['bookings_updated'] += $pendingBookings->count();

                    // Process waitlist for rejected bookings
                    foreach ($pendingBookings as $booking) {
                        $waitlistCount = $this->processWaitlist($booking);
                        $stats['waitlist_processed'] += $waitlistCount;
                    }
                }
            }

            // ========================================
            // STEP 2: Sync CartTransactions where ALL items are rejected/cancelled
            // ========================================
            $this->command->info('');
            $this->command->info('📋 Step 2: Finding transactions where all cart items are rejected/cancelled...');

            $pendingTransactions = CartTransaction::where('approval_status', 'pending')
                ->where('status', '!=', 'cancelled')
                ->with('cartItems')
                ->get();

            $transactionsToUpdate = [];

            foreach ($pendingTransactions as $transaction) {
                $allItemsRejectedOrCancelled = $transaction->cartItems()
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->count() === 0;

                $hasItems = $transaction->cartItems()->count() > 0;

                if ($allItemsRejectedOrCancelled && $hasItems) {
                    $transactionsToUpdate[] = $transaction;
                }
            }

            $transactionsCount = count($transactionsToUpdate);
            $this->command->info("Found {$transactionsCount} transactions to update");

            foreach ($transactionsToUpdate as $transaction) {
                $this->command->info("  Transaction #{$transaction->id}: All cart items rejected/cancelled, updating transaction");

                $transaction->update([
                    'approval_status' => 'rejected',
                    'status' => 'cancelled',
                    'rejection_reason' => 'Auto-synced: All cart items were rejected or cancelled'
                ]);

                $stats['transactions_updated']++;

                // Update bookings
                $bookingsCount = Booking::where('cart_transaction_id', $transaction->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->update([
                        'status' => 'rejected',
                        'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAuto-synced: All cart items were rejected')")
                    ]);

                if ($bookingsCount > 0) {
                    $this->command->info("    Updated {$bookingsCount} bookings");
                    $stats['bookings_updated'] += $bookingsCount;

                    // Process waitlist
                    $bookings = Booking::where('cart_transaction_id', $transaction->id)
                        ->where('status', 'rejected')
                        ->get();

                    foreach ($bookings as $booking) {
                        $waitlistCount = $this->processWaitlist($booking);
                        $stats['waitlist_processed'] += $waitlistCount;
                    }
                }
            }

            // ========================================
            // STEP 3: Fix orphaned bookings (no matching transaction)
            // ========================================
            $this->command->info('');
            $this->command->info('📋 Step 3: Finding orphaned bookings...');

            $orphanedBookings = Booking::whereNotIn('status', ['rejected', 'cancelled'])
                ->whereNotNull('cart_transaction_id')
                ->whereDoesntHave('cartTransaction')
                ->get();

            if ($orphanedBookings->isNotEmpty()) {
                $this->command->info("Found {$orphanedBookings->count()} orphaned bookings");

                foreach ($orphanedBookings as $booking) {
                    $this->command->info("  Booking #{$booking->id}: No matching transaction, marking as cancelled");

                    $booking->update([
                        'status' => 'cancelled',
                        'notes' => ($booking->notes ?? '') . "\n\nAuto-synced: Parent transaction no longer exists"
                    ]);

                    $stats['bookings_updated']++;
                }
            } else {
                $this->command->info("No orphaned bookings found");
            }

            // ========================================
            // STEP 4: Fix orphaned cart items (no matching transaction)
            // ========================================
            $this->command->info('');
            $this->command->info('📋 Step 4: Finding orphaned cart items...');

            $orphanedCartItems = CartItem::whereNotIn('status', ['rejected', 'cancelled'])
                ->whereNotNull('cart_transaction_id')
                ->whereDoesntHave('cartTransaction')
                ->get();

            if ($orphanedCartItems->isNotEmpty()) {
                $this->command->info("Found {$orphanedCartItems->count()} orphaned cart items");

                foreach ($orphanedCartItems as $item) {
                    $this->command->info("  Cart Item #{$item->id}: No matching transaction, marking as cancelled");

                    $item->update([
                        'status' => 'cancelled',
                        'admin_notes' => ($item->admin_notes ?? '') . "\n\nAuto-synced: Parent transaction no longer exists"
                    ]);

                    $stats['cart_items_updated']++;
                }
            } else {
                $this->command->info("No orphaned cart items found");
            }

            DB::commit();

            $this->command->info('');
            $this->command->info('✅ Status synchronization completed successfully!');
            $this->command->info('');
            $this->command->table(
                ['Metric', 'Count'],
                [
                    ['Cart Items Updated', $stats['cart_items_updated']],
                    ['Cart Transactions Updated', $stats['transactions_updated']],
                    ['Bookings Updated', $stats['bookings_updated']],
                    ['Waitlist Entries Processed', $stats['waitlist_processed']],
                ]
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Synchronization failed: ' . $e->getMessage());
            $this->command->error($e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Process waitlist for a rejected booking
     */
    private function processWaitlist(Booking $booking): int
    {
        $waitlistEntries = BookingWaitlist::where('pending_booking_id', $booking->id)
            ->where('status', BookingWaitlist::STATUS_PENDING)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        if ($waitlistEntries->isEmpty()) {
            return 0;
        }

        // Only notify the first person in line (Position #1)
        $firstInLine = $waitlistEntries->first();

        $firstInLine->update([
            'status' => BookingWaitlist::STATUS_NOTIFIED,
            'notified_at' => now(),
            'expires_at' => now()->addHour()
        ]);

        $waitlistUserName = $firstInLine->user ? $firstInLine->user->name : 'N/A';
        $this->command->info("      Notified waitlist user: {$waitlistUserName} (Position #{$firstInLine->position})");

        // Send email notification
        if ($firstInLine->user && $firstInLine->user->email) {
            try {
                \Mail::to($firstInLine->user->email)
                    ->send(new \App\Mail\WaitlistNotificationMail($firstInLine));
                $this->command->info("      Email sent to {$firstInLine->user->email}");
            } catch (\Exception $e) {
                $this->command->warn("      Failed to send email: {$e->getMessage()}");
            }
        }

        return 1;
    }
}
