<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncCartStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:sync-statuses
                            {--dry-run : Run without making any changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize statuses between CartTransaction, CartItem, and Booking records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔄 Starting status synchronization...');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
        }

        if (!$dryRun) {
            DB::beginTransaction();
        }

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
            $this->newLine();
            $this->info('📋 Step 1: Finding rejected/cancelled transactions with pending cart items...');

            $rejectedTransactions = CartTransaction::whereIn('approval_status', ['rejected'])
                ->orWhere('status', 'cancelled')
                ->with('cartItems', 'bookings')
                ->get();

            $this->info("Found {$rejectedTransactions->count()} rejected/cancelled transactions");

            foreach ($rejectedTransactions as $transaction) {
                // Find cart items that don't match transaction status
                $pendingItems = $transaction->cartItems()
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->get();

                if ($pendingItems->isNotEmpty()) {
                    $newStatus = $transaction->approval_status === 'rejected' ? 'rejected' : 'cancelled';
                    $this->line("  Transaction #{$transaction->id}: " . ($dryRun ? 'Would sync' : 'Syncing') . " {$pendingItems->count()} cart items to '{$newStatus}'");

                    if (!$dryRun) {
                        CartItem::where('cart_transaction_id', $transaction->id)
                            ->whereNotIn('status', ['rejected', 'cancelled'])
                            ->update([
                                'status' => $newStatus,
                                'admin_notes' => DB::raw("CONCAT(COALESCE(admin_notes, ''), '\n\nAuto-synced: Transaction was {$newStatus}')")
                            ]);
                    }

                    $stats['cart_items_updated'] += $pendingItems->count();
                }

                // Sync bookings too
                $pendingBookings = $transaction->bookings()
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->get();

                if ($pendingBookings->isNotEmpty()) {
                    $newStatus = $transaction->approval_status === 'rejected' ? 'rejected' : 'cancelled';
                    $this->line("  Transaction #{$transaction->id}: " . ($dryRun ? 'Would sync' : 'Syncing') . " {$pendingBookings->count()} bookings to '{$newStatus}'");

                    if (!$dryRun) {
                        Booking::where('cart_transaction_id', $transaction->id)
                            ->whereNotIn('status', ['rejected', 'cancelled'])
                            ->update([
                                'status' => $newStatus,
                                'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAuto-synced: Transaction was {$newStatus}')")
                            ]);

                        // Process waitlist for rejected bookings
                        foreach ($pendingBookings as $booking) {
                            $waitlistCount = $this->processWaitlist($booking, $dryRun);
                            $stats['waitlist_processed'] += $waitlistCount;
                        }
                    }

                    $stats['bookings_updated'] += $pendingBookings->count();
                }
            }

            // ========================================
            // STEP 2: Sync CartTransactions where ALL items are rejected/cancelled
            // ========================================
            $this->newLine();
            $this->info('📋 Step 2: Finding transactions where all cart items are rejected/cancelled...');

            $pendingTransactions = CartTransaction::where('approval_status', 'pending')
                ->where('status', '!=', 'cancelled')
                ->with('cartItems', 'bookings')
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
            $this->info("Found {$transactionsCount} transactions to update");

            foreach ($transactionsToUpdate as $transaction) {
                $this->line("  Transaction #{$transaction->id}: All cart items rejected/cancelled, " . ($dryRun ? 'would update' : 'updating') . " transaction");

                if (!$dryRun) {
                    $transaction->update([
                        'approval_status' => 'rejected',
                        'status' => 'cancelled',
                        'rejection_reason' => 'Auto-synced: All cart items were rejected or cancelled'
                    ]);

                    // Update bookings
                    $bookingsCount = Booking::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->update([
                            'status' => 'rejected',
                            'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAuto-synced: All cart items were rejected')")
                        ]);

                    if ($bookingsCount > 0) {
                        $this->line("    Updated {$bookingsCount} bookings");
                        $stats['bookings_updated'] += $bookingsCount;

                        // Process waitlist
                        $bookings = Booking::where('cart_transaction_id', $transaction->id)
                            ->where('status', 'rejected')
                            ->get();

                        foreach ($bookings as $booking) {
                            $waitlistCount = $this->processWaitlist($booking, $dryRun);
                            $stats['waitlist_processed'] += $waitlistCount;
                        }
                    }
                }

                $stats['transactions_updated']++;
            }

            // ========================================
            // STEP 3: Fix orphaned bookings (no matching transaction)
            // ========================================
            $this->newLine();
            $this->info('📋 Step 3: Finding orphaned bookings...');

            $orphanedBookings = Booking::whereNotIn('status', ['rejected', 'cancelled'])
                ->whereNotNull('cart_transaction_id')
                ->whereDoesntHave('cartTransaction')
                ->get();

            if ($orphanedBookings->isNotEmpty()) {
                $this->info("Found {$orphanedBookings->count()} orphaned bookings");

                foreach ($orphanedBookings as $booking) {
                    $this->line("  Booking #{$booking->id}: No matching transaction, " . ($dryRun ? 'would mark' : 'marking') . " as cancelled");

                    if (!$dryRun) {
                        $booking->update([
                            'status' => 'cancelled',
                            'notes' => ($booking->notes ?? '') . "\n\nAuto-synced: Parent transaction no longer exists"
                        ]);
                    }

                    $stats['bookings_updated']++;
                }
            } else {
                $this->info("No orphaned bookings found");
            }

            // ========================================
            // STEP 4: Fix orphaned cart items (no matching transaction)
            // ========================================
            $this->newLine();
            $this->info('📋 Step 4: Finding orphaned cart items...');

            $orphanedCartItems = CartItem::whereNotIn('status', ['rejected', 'cancelled'])
                ->whereNotNull('cart_transaction_id')
                ->whereDoesntHave('cartTransaction')
                ->get();

            if ($orphanedCartItems->isNotEmpty()) {
                $this->info("Found {$orphanedCartItems->count()} orphaned cart items");

                foreach ($orphanedCartItems as $item) {
                    $this->line("  Cart Item #{$item->id}: No matching transaction, " . ($dryRun ? 'would mark' : 'marking') . " as cancelled");

                    if (!$dryRun) {
                        $item->update([
                            'status' => 'cancelled',
                            'admin_notes' => ($item->admin_notes ?? '') . "\n\nAuto-synced: Parent transaction no longer exists"
                        ]);
                    }

                    $stats['cart_items_updated']++;
                }
            } else {
                $this->info("No orphaned cart items found");
            }

            if (!$dryRun) {
                DB::commit();
            }

            $this->newLine();
            $this->info('✅ Status synchronization completed successfully!');
            $this->newLine();

            $prefix = $dryRun ? '(Would Update)' : 'Updated';
            $this->table(
                ['Metric', 'Count'],
                [
                    ["Cart Items {$prefix}", $stats['cart_items_updated']],
                    ["Cart Transactions {$prefix}", $stats['transactions_updated']],
                    ["Bookings {$prefix}", $stats['bookings_updated']],
                    ["Waitlist Entries " . ($dryRun ? '(Would Process)' : 'Processed'), $stats['waitlist_processed']],
                ]
            );

            if ($dryRun) {
                $this->newLine();
                $this->info('💡 Run without --dry-run to actually perform the synchronization');
            }

            return 0;

        } catch (\Exception $e) {
            if (!$dryRun) {
                DB::rollBack();
            }
            $this->error('❌ Synchronization failed: ' . $e->getMessage());
            Log::error('Cart status sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Process waitlist for a rejected booking
     */
    private function processWaitlist(Booking $booking, bool $dryRun = false): int
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

        if (!$dryRun) {
            $firstInLine->update([
                'status' => BookingWaitlist::STATUS_NOTIFIED,
                'notified_at' => now(),
                'expires_at' => now()->addHour()
            ]);
        }

        $waitlistUserName = $firstInLine->user ? $firstInLine->user->name : 'N/A';
        $action = $dryRun ? 'Would notify' : 'Notified';
        $this->line("      {$action} waitlist user: {$waitlistUserName} (Position #{$firstInLine->position})");

        // Send email notification
        if (!$dryRun && $firstInLine->user && $firstInLine->user->email) {
            try {
                \Mail::to($firstInLine->user->email)
                    ->send(new \App\Mail\WaitlistNotificationMail($firstInLine));
                $this->line("      Email sent to {$firstInLine->user->email}");
            } catch (\Exception $e) {
                $this->warn("      Failed to send email: {$e->getMessage()}");
            }
        }

        return 1;
    }
}
