<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartTransaction;
use App\Models\CartItem;
use App\Models\Booking;
use App\Models\BookingWaitlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupStaleCartItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:cleanup-stale
                            {--hours=1 : Number of hours after which cart items are considered stale}
                            {--dry-run : Run without making any changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale cart items and expired transactions that are older than specified hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');

        $this->info('🧹 Starting cleanup of stale cart items...');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No changes will be made');
        }

        // Define expiration threshold
        $expirationThreshold = Carbon::now()->subHours($hours);

        $this->info("Expiration threshold: {$expirationThreshold->format('Y-m-d H:i:s')} (older than {$hours} hour(s))");

        if (!$dryRun) {
            DB::beginTransaction();
        }

        try {
            // Find all stale cart transactions
            $staleTransactions = CartTransaction::where('approval_status', 'pending')
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '<', $expirationThreshold)
                ->with(['cartItems', 'bookings', 'user'])
                ->get();

            $this->info("Found {$staleTransactions->count()} stale cart transactions");

            if ($staleTransactions->isEmpty()) {
                $this->info('✅ No stale cart items found. Database is clean!');
                return 0;
            }

            $stats = [
                'transactions_rejected' => 0,
                'cart_items_rejected' => 0,
                'bookings_rejected' => 0,
                'waitlist_notified' => 0,
            ];

            foreach ($staleTransactions as $transaction) {
                $ageHours = $transaction->created_at->diffInHours(now());
                $userName = $transaction->user ? $transaction->user->name : 'N/A';
                $this->info("Processing Transaction #{$transaction->id} (Age: {$ageHours} hours, User: {$userName})...");

                if (!$dryRun) {
                    // Reject the cart transaction
                    $transaction->update([
                        'approval_status' => 'rejected',
                        'status' => 'cancelled',
                        'rejection_reason' => "Automatically rejected: Payment timeout ({$hours} hour(s) expired)",
                        'approved_at' => now()
                    ]);
                    $stats['transactions_rejected']++;

                    $this->line("  ✓ Rejected cart transaction #{$transaction->id}");

                    // Reject all associated cart items
                    $cartItemsCount = CartItem::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->update([
                            'status' => 'rejected',
                            'admin_notes' => 'Automatically rejected: Payment timeout'
                        ]);
                    $stats['cart_items_rejected'] += $cartItemsCount;

                    $this->line("  ✓ Rejected {$cartItemsCount} cart items");

                    // Reject all associated bookings
                    $bookingsCount = Booking::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->update([
                            'status' => 'rejected',
                            'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\n\nAutomatically rejected: Payment timeout ({$hours} hour(s) expired)')")
                        ]);
                    $stats['bookings_rejected'] += $bookingsCount;

                    $this->line("  ✓ Rejected {$bookingsCount} bookings");

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
                            $this->line("    - Found {$waitlistEntries->count()} waitlist entries for Booking #{$booking->id}");

                            // Only notify the first person in line (Position #1)
                            $firstInLine = $waitlistEntries->first();

                            $firstInLine->update([
                                'status' => BookingWaitlist::STATUS_NOTIFIED,
                                'notified_at' => now(),
                                'expires_at' => Carbon::now()->addHour() // 1 hour to complete payment
                            ]);

                            $stats['waitlist_notified']++;

                            $waitlistUserName = $firstInLine->user ? $firstInLine->user->name : 'N/A';
                            $this->line("    ✓ Notified waitlist user: {$waitlistUserName} (Position #{$firstInLine->position})");

                            // Send email notification
                            if ($firstInLine->user && $firstInLine->user->email) {
                                try {
                                    \Mail::to($firstInLine->user->email)
                                        ->send(new \App\Mail\WaitlistNotificationMail($firstInLine));
                                    $this->line("    ✓ Email sent to {$firstInLine->user->email}");
                                } catch (\Exception $e) {
                                    $this->warn("    ✗ Failed to send email: {$e->getMessage()}");
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
                        'age_hours' => $ageHours,
                    ]);
                } else {
                    // Dry run - just show what would be done
                    $cartItemsCount = CartItem::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->count();

                    $bookingsCount = Booking::where('cart_transaction_id', $transaction->id)
                        ->whereNotIn('status', ['rejected', 'cancelled'])
                        ->count();

                    $this->line("  [DRY RUN] Would reject transaction #{$transaction->id}");
                    $this->line("  [DRY RUN] Would reject {$cartItemsCount} cart items");
                    $this->line("  [DRY RUN] Would reject {$bookingsCount} bookings");

                    $stats['transactions_rejected']++;
                    $stats['cart_items_rejected'] += $cartItemsCount;
                    $stats['bookings_rejected'] += $bookingsCount;
                }
            }

            if (!$dryRun) {
                DB::commit();
            }

            $this->newLine();
            $this->info('✅ Cleanup completed successfully!');
            $this->newLine();

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Cart Transactions ' . ($dryRun ? '(Would Reject)' : 'Rejected'), $stats['transactions_rejected']],
                    ['Cart Items ' . ($dryRun ? '(Would Reject)' : 'Rejected'), $stats['cart_items_rejected']],
                    ['Bookings ' . ($dryRun ? '(Would Reject)' : 'Rejected'), $stats['bookings_rejected']],
                    ['Waitlist Users ' . ($dryRun ? '(Would Notify)' : 'Notified'), $stats['waitlist_notified']],
                ]
            );

            if ($dryRun) {
                $this->newLine();
                $this->info('💡 Run without --dry-run to actually perform the cleanup');
            }

            return 0;

        } catch (\Exception $e) {
            if (!$dryRun) {
                DB::rollBack();
            }
            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            Log::error('Stale cart cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}
