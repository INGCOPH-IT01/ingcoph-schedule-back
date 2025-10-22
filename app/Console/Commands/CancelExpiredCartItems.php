<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartItem;
use App\Models\CartTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Helpers\BusinessHoursHelper;

class CancelExpiredCartItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:cancel-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancel cart items that have expired based on business hours rules';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to cancel expired cart items (business hours mode)...');

        try {
            DB::beginTransaction();

            // Find all pending cart transactions
            // Load the user relationship to check if created by admin
            $pendingTransactions = CartTransaction::with('user')
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->get();

            $cancelledCount = 0;
            $transactionCount = 0;
            $skippedAdminCount = 0;
            $skippedNotExpiredCount = 0;

            foreach ($pendingTransactions as $transaction) {
                // Use universal helper to check if transaction should expire
                if (!BusinessHoursHelper::shouldExpire($transaction)) {
                    // Track why it was skipped
                    if (BusinessHoursHelper::isExemptFromExpiration($transaction)) {
                        $skippedAdminCount++;
                    } else {
                        $skippedNotExpiredCount++;
                    }
                    continue;
                }

                // Cancel all pending cart items in this transaction
                $itemsCancelled = CartItem::where('cart_transaction_id', $transaction->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired']);

                // Mark the transaction as expired
                $transaction->update(['status' => 'expired']);

                $cancelledCount += $itemsCancelled;
                $transactionCount++;
            }

            DB::commit();

            $this->info("Successfully cancelled {$cancelledCount} cart items from {$transactionCount} expired transactions.");
            $this->info("Skipped {$skippedNotExpiredCount} transactions that have not yet expired.");
            if ($skippedAdminCount > 0) {
                $this->info("Skipped {$skippedAdminCount} admin cart transactions from expiration.");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Failed to cancel expired cart items: " . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
