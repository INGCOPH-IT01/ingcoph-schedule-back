<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartTransaction;
use App\Models\Booking;
use App\Models\CartItem;
use App\Models\BookingWaitlist;
use Illuminate\Support\Facades\DB;

class CheckStatusConsistency extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'status:check-consistency
                            {--fix : Attempt to fix inconsistencies automatically}
                            {--verbose : Show detailed output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for status inconsistencies across bookings, cart_transactions, cart_items, and booking_waitlists tables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Status Consistency Check ===');
        $this->newLine();

        $issues = [];
        $fixMode = $this->option('fix');
        $verbose = $this->option('verbose');

        if ($fixMode) {
            $this->warn('⚠️  FIX MODE ENABLED - Will attempt to fix inconsistencies');
            if (!$this->confirm('Are you sure you want to proceed with automatic fixes?')) {
                $this->error('Operation cancelled');
                return 1;
            }
            $this->newLine();
        }

        // Check 1: Approved transactions with pending bookings
        $this->info('1. Checking approved transactions with pending bookings...');
        $approvedTransactionsWithPendingBookings = CartTransaction::where('approval_status', 'approved')
            ->whereHas('bookings', function($query) {
                $query->where('status', 'pending');
            })
            ->with('bookings')
            ->get();

        if ($approvedTransactionsWithPendingBookings->count() > 0) {
            $this->error("   ❌ Found {$approvedTransactionsWithPendingBookings->count()} approved transactions with pending bookings");

            foreach ($approvedTransactionsWithPendingBookings as $transaction) {
                $pendingCount = $transaction->bookings()->where('status', 'pending')->count();
                $issue = "Transaction #{$transaction->id}: {$pendingCount} pending bookings";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Fixing transaction #{$transaction->id}...");
                    $transaction->bookings()->where('status', 'pending')->update(['status' => 'approved']);
                    $this->info("      ✓ Fixed");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 2: Rejected transactions with approved bookings
        $this->info('2. Checking rejected transactions with approved bookings...');
        $rejectedTransactionsWithApprovedBookings = CartTransaction::where('approval_status', 'rejected')
            ->whereHas('bookings', function($query) {
                $query->whereIn('status', ['approved', 'pending']);
            })
            ->with('bookings')
            ->get();

        if ($rejectedTransactionsWithApprovedBookings->count() > 0) {
            $this->error("   ❌ Found {$rejectedTransactionsWithApprovedBookings->count()} rejected transactions with non-rejected bookings");

            foreach ($rejectedTransactionsWithApprovedBookings as $transaction) {
                $nonRejectedCount = $transaction->bookings()->whereIn('status', ['approved', 'pending'])->count();
                $issue = "Transaction #{$transaction->id}: {$nonRejectedCount} non-rejected bookings";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Fixing transaction #{$transaction->id}...");
                    $transaction->bookings()->whereIn('status', ['approved', 'pending'])->update(['status' => 'rejected']);
                    $this->info("      ✓ Fixed");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 3: Paid transactions with unpaid bookings
        $this->info('3. Checking paid transactions with unpaid bookings...');
        $paidTransactionsWithUnpaidBookings = CartTransaction::where('payment_status', 'paid')
            ->whereHas('bookings', function($query) {
                $query->where('payment_status', 'unpaid');
            })
            ->with('bookings')
            ->get();

        if ($paidTransactionsWithUnpaidBookings->count() > 0) {
            $this->error("   ❌ Found {$paidTransactionsWithUnpaidBookings->count()} paid transactions with unpaid bookings");

            foreach ($paidTransactionsWithUnpaidBookings as $transaction) {
                $unpaidCount = $transaction->bookings()->where('payment_status', 'unpaid')->count();
                $issue = "Transaction #{$transaction->id}: {$unpaidCount} unpaid bookings";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Fixing transaction #{$transaction->id}...");
                    $transaction->bookings()->where('payment_status', 'unpaid')->update([
                        'payment_status' => $transaction->payment_status,
                        'payment_method' => $transaction->payment_method,
                        'proof_of_payment' => $transaction->proof_of_payment,
                        'paid_at' => $transaction->paid_at
                    ]);
                    $this->info("      ✓ Fixed");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 4: Bookings without cart transactions (orphaned)
        $this->info('4. Checking for orphaned bookings...');
        $orphanedBookings = Booking::whereNotNull('cart_transaction_id')
            ->whereDoesntHave('cartTransaction')
            ->get();

        if ($orphanedBookings->count() > 0) {
            $this->error("   ❌ Found {$orphanedBookings->count()} orphaned bookings (cart_transaction_id points to non-existent transaction)");

            foreach ($orphanedBookings as $booking) {
                $issue = "Booking #{$booking->id}: References non-existent transaction #{$booking->cart_transaction_id}";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Cannot auto-fix orphaned bookings (manual review required)");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 5: Cart items without cart transactions (orphaned)
        $this->info('5. Checking for orphaned cart items...');
        $orphanedCartItems = CartItem::whereNotNull('cart_transaction_id')
            ->whereDoesntHave('cartTransaction')
            ->get();

        if ($orphanedCartItems->count() > 0) {
            $this->error("   ❌ Found {$orphanedCartItems->count()} orphaned cart items");

            foreach ($orphanedCartItems as $cartItem) {
                $issue = "Cart Item #{$cartItem->id}: References non-existent transaction #{$cartItem->cart_transaction_id}";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Cannot auto-fix orphaned cart items (manual review required)");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 6: Completed cart items without bookings
        $this->info('6. Checking completed cart items without bookings...');
        $completedCartItemsWithoutBookings = CartItem::where('status', 'completed')
            ->whereHas('cartTransaction', function($query) {
                $query->where('status', 'completed');
            })
            ->get()
            ->filter(function($cartItem) {
                // Check if there's a corresponding booking
                $hasBooking = Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
                    ->where('court_id', $cartItem->court_id)
                    ->exists();
                return !$hasBooking;
            });

        if ($completedCartItemsWithoutBookings->count() > 0) {
            $this->error("   ❌ Found {$completedCartItemsWithoutBookings->count()} completed cart items without corresponding bookings");

            foreach ($completedCartItemsWithoutBookings as $cartItem) {
                $issue = "Cart Item #{$cartItem->id} (Transaction #{$cartItem->cart_transaction_id}): No corresponding booking";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Cannot auto-fix missing bookings (manual review required)");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 7: Checked-in transactions with non-completed bookings
        $this->info('7. Checking checked-in transactions with non-completed bookings...');
        $checkedInTransactionsWithNonCompletedBookings = CartTransaction::where('status', 'checked_in')
            ->whereHas('bookings', function($query) {
                $query->whereNotIn('status', ['completed', 'checked_in']);
            })
            ->with('bookings')
            ->get();

        if ($checkedInTransactionsWithNonCompletedBookings->count() > 0) {
            $this->error("   ❌ Found {$checkedInTransactionsWithNonCompletedBookings->count()} checked-in transactions with non-completed bookings");

            foreach ($checkedInTransactionsWithNonCompletedBookings as $transaction) {
                $nonCompletedCount = $transaction->bookings()->whereNotIn('status', ['completed', 'checked_in'])->count();
                $issue = "Transaction #{$transaction->id}: {$nonCompletedCount} non-completed bookings";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Fixing transaction #{$transaction->id}...");
                    $transaction->bookings()->whereNotIn('status', ['completed', 'checked_in'])->update(['status' => 'completed']);
                    $this->info("      ✓ Fixed");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Check 8: Converted waitlist entries without bookings
        $this->info('8. Checking converted waitlist entries without bookings...');
        $convertedWaitlistWithoutBookings = BookingWaitlist::where('status', BookingWaitlist::STATUS_CONVERTED)
            ->whereNotNull('converted_cart_transaction_id')
            ->get()
            ->filter(function($waitlist) {
                $hasBooking = Booking::where('booking_waitlist_id', $waitlist->id)
                    ->orWhere('cart_transaction_id', $waitlist->converted_cart_transaction_id)
                    ->exists();
                return !$hasBooking;
            });

        if ($convertedWaitlistWithoutBookings->count() > 0) {
            $this->error("   ❌ Found {$convertedWaitlistWithoutBookings->count()} converted waitlist entries without bookings");

            foreach ($convertedWaitlistWithoutBookings as $waitlist) {
                $issue = "Waitlist #{$waitlist->id}: Marked converted but no booking found";
                $issues[] = $issue;

                if ($verbose) {
                    $this->line("      - {$issue}");
                }

                if ($fixMode) {
                    $this->line("      → Cannot auto-fix converted waitlist entries (manual review required)");
                }
            }
        } else {
            $this->info('   ✓ No issues found');
        }
        $this->newLine();

        // Summary
        $this->info('=== Summary ===');
        $totalIssues = count($issues);

        if ($totalIssues > 0) {
            $this->error("Total issues found: {$totalIssues}");

            if ($fixMode) {
                $this->info("\nAttempted automatic fixes for fixable issues.");
                $this->warn("Some issues require manual review and cannot be auto-fixed.");
                $this->info("Please review the output above and run without --fix to verify.");
            } else {
                $this->warn("\nRun with --fix flag to attempt automatic fixes for supported issues.");
                $this->info("Example: php artisan status:check-consistency --fix");
            }

            return 1; // Exit with error code
        } else {
            $this->info("✓ All checks passed! No inconsistencies found.");
            return 0; // Exit successfully
        }
    }
}
