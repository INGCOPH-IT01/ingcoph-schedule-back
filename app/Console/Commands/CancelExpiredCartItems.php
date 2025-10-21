<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartItem;
use App\Models\CartTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                // Skip admin bookings - they should not expire automatically
                if ($transaction->user && $transaction->user->isAdmin()) {
                    $skippedAdminCount++;
                    Log::info("Skipped admin cart transaction #{$transaction->id} from expiration");
                    continue;
                }

                // Check if transaction has expired based on business hours
                $createdAt = Carbon::parse($transaction->created_at);

                if (!BusinessHoursHelper::isExpired($createdAt)) {
                    $skippedNotExpiredCount++;
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

                Log::info("Expired cart transaction #{$transaction->id} with {$itemsCancelled} items (created: {$createdAt->format('Y-m-d H:i:s')})");
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
            Log::error("Failed to cancel expired cart items: " . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
