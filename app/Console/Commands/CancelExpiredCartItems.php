<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CartItem;
use App\Models\CartTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'Cancel cart items that have been pending for more than 1 hour without payment';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to cancel expired cart items...');

        // Get the timestamp for 1 hour ago
        $oneHourAgo = Carbon::now()->subHour();

        try {
            DB::beginTransaction();

            // Find all pending cart transactions that are older than 1 hour
            // Load the user relationship to check if created by admin
            $expiredTransactions = CartTransaction::with('user')
                ->where('status', 'pending')
                ->where('payment_status', 'unpaid')
                ->where('created_at', '<', $oneHourAgo)
                ->get();

            $cancelledCount = 0;
            $transactionCount = 0;
            $skippedAdminCount = 0;

            foreach ($expiredTransactions as $transaction) {
                // Skip admin bookings - they should not expire automatically
                if ($transaction->user && $transaction->user->isAdmin()) {
                    $skippedAdminCount++;
                    Log::info("Skipped admin cart transaction #{$transaction->id} from expiration");
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

                Log::info("Expired cart transaction #{$transaction->id} with {$itemsCancelled} items");
            }

            DB::commit();

            $this->info("Successfully cancelled {$cancelledCount} cart items from {$transactionCount} expired transactions.");
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
