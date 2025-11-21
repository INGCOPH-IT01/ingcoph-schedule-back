<?php

namespace App\Console\Commands;

use App\Models\CartTransaction;
use App\Models\CartItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupStaleCartTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:cleanup-stale-transactions
                            {--dry-run : Run without making changes}
                            {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup cart transactions and items with mismatched statuses';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Scanning for data integrity issues...');
        $this->newLine();

        // Issue 1: Pending transactions with all non-pending items
        $staleTransactions = CartTransaction::where('status', 'pending')
            ->whereHas('cartItems')
            ->whereDoesntHave('cartItems', function($query) {
                $query->where('status', 'pending');
            })
            ->with('cartItems', 'user')
            ->get();

        // Issue 2: Completed transactions with pending items
        $completedWithPending = CartTransaction::where('status', 'completed')
            ->whereHas('cartItems', function($q) {
                $q->where('status', 'pending');
            })
            ->with('cartItems', 'user')
            ->get();

        // Issue 3: Rejected transactions with pending items
        $rejectedWithPending = CartTransaction::where(function($query) {
            $query->where('status', 'rejected')
                  ->orWhere('approval_status', 'rejected');
        })
            ->whereHas('cartItems', function($q) {
                $q->where('status', 'pending');
            })
            ->with('cartItems', 'user')
            ->get();

        // Issue 4: Cancelled transactions with pending items
        $cancelledWithPending = CartTransaction::where('status', 'cancelled')
            ->whereHas('cartItems', function($q) {
                $q->where('status', 'pending');
            })
            ->with('cartItems', 'user')
            ->get();

        $totalIssues = $staleTransactions->count() +
                      $completedWithPending->count() +
                      $rejectedWithPending->count() +
                      $cancelledWithPending->count();

        if ($totalIssues === 0) {
            $this->info('✓ No data integrity issues found!');
            return Command::SUCCESS;
        }

        $this->warn("Found {$totalIssues} transactions with data integrity issues:");
        $this->newLine();

        // Display summaries
        if ($staleTransactions->count() > 0) {
            $this->info("Issue 1: Pending transactions with non-pending items ({$staleTransactions->count()})");
        }
        if ($completedWithPending->count() > 0) {
            $this->info("Issue 2: Completed transactions with pending items ({$completedWithPending->count()})");
        }
        if ($rejectedWithPending->count() > 0) {
            $this->info("Issue 3: Rejected transactions with pending items ({$rejectedWithPending->count()})");
        }
        if ($cancelledWithPending->count() > 0) {
            $this->info("Issue 4: Cancelled transactions with pending items ({$cancelledWithPending->count()})");
        }
        $this->newLine();

        if ($dryRun) {
            $this->info('DRY RUN - No changes made.');
            $this->info('Run without --dry-run to apply changes.');
            return Command::SUCCESS;
        }

        if (!$force) {
            if (!$this->confirm('Do you want to fix these issues?')) {
                $this->info('Aborted.');
                return 1; // User cancelled
            }
        }

        $this->info('');
        $this->info('Fixing issues...');
        $this->newLine();

        $updatedCount = 0;
        $bar = $this->output->createProgressBar($totalIssues);

        DB::beginTransaction();
        try {
            // Fix Issue 1: Update transaction status to match cart items
            foreach ($staleTransactions as $transaction) {
                $hasCompletedItems = $transaction->cartItems->where('status', 'completed')->isNotEmpty();
                $hasApprovedItems = $transaction->cartItems->where('status', 'approved')->isNotEmpty();
                $allRejected = $transaction->cartItems->every(function($item) {
                    return $item->status === 'rejected';
                });
                $allCancelled = $transaction->cartItems->every(function($item) {
                    return $item->status === 'cancelled';
                });

                $newStatus = 'pending'; // Default
                $cartItemStatus = null;

                if ($hasCompletedItems) {
                    $newStatus = 'completed';
                    $cartItemStatus = 'completed';
                } elseif ($allRejected || ($transaction->approval_status === 'rejected')) {
                    $newStatus = 'rejected';
                    $cartItemStatus = 'rejected';
                } elseif ($allCancelled) {
                    $newStatus = 'cancelled';
                    $cartItemStatus = 'cancelled';
                } elseif ($hasApprovedItems) {
                    if ($transaction->bookings()->exists()) {
                        $cartItemStatus = 'completed';
                        $newStatus = 'completed';
                    }
                }

                $transaction->update(['status' => $newStatus]);

                if ($cartItemStatus) {
                    $transaction->cartItems()
                        ->where('status', 'pending')
                        ->update(['status' => $cartItemStatus]);
                }

                $updatedCount++;
                $bar->advance();
            }

            // Fix Issue 2: Update pending cart items in completed transactions
            foreach ($completedWithPending as $transaction) {
                $transaction->cartItems()
                    ->where('status', 'pending')
                    ->update(['status' => 'completed']);
                $updatedCount++;
                $bar->advance();
            }

            // Fix Issue 3: Update pending cart items in rejected transactions
            foreach ($rejectedWithPending as $transaction) {
                $transaction->cartItems()
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected']);
                $updatedCount++;
                $bar->advance();
            }

            // Fix Issue 4: Update pending cart items in cancelled transactions
            foreach ($cancelledWithPending as $transaction) {
                $transaction->cartItems()
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
                $updatedCount++;
                $bar->advance();
            }

            DB::commit();
            $bar->finish();

            $this->newLine(2);
            $this->info("✓ Successfully fixed {$updatedCount} transactions!");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('');
            $this->error('Failed to fix issues: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
