<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CartTransaction;
use App\Models\CartItem;

class SyncCartItemsStatusFromTransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder syncs the status of cart_items to match their parent
     * cart_transaction's approval_status. This ensures consistency
     * between the transaction approval state and individual cart items.
     *
     * @return void
     */
    public function run()
    {
        $this->command->info('Starting to sync cart items status with cart transactions approval_status...');

        // Get all cart transactions
        $cartTransactions = CartTransaction::with('cartItems')->get();

        $totalTransactions = $cartTransactions->count();
        $updatedItemsCount = 0;
        $skippedItemsCount = 0;
        $errorCount = 0;
        $syncDetails = [];

        foreach ($cartTransactions as $transaction) {
            try {
                $cartItems = $transaction->cartItems;

                if ($cartItems->isEmpty()) {
                    $this->command->warn("Transaction ID {$transaction->id}: No cart items found");
                    continue;
                }

                $transactionApprovalStatus = $transaction->approval_status;
                $itemsUpdatedInTransaction = 0;

                foreach ($cartItems as $cartItem) {
                    // Check if status needs to be updated
                    if ($cartItem->status !== $transactionApprovalStatus) {
                        $oldStatus = $cartItem->status;
                        $cartItem->status = $transactionApprovalStatus;
                        $cartItem->save();

                        $itemsUpdatedInTransaction++;
                        $updatedItemsCount++;

                        $this->command->info(
                            "Updated Cart Item ID {$cartItem->id}: " .
                            "'{$oldStatus}' → '{$transactionApprovalStatus}' " .
                            "(Transaction ID: {$transaction->id})"
                        );
                    } else {
                        $skippedItemsCount++;
                    }
                }

                if ($itemsUpdatedInTransaction > 0) {
                    $syncDetails[] = [
                        'transaction_id' => $transaction->id,
                        'approval_status' => $transactionApprovalStatus,
                        'items_updated' => $itemsUpdatedInTransaction,
                        'total_items' => $cartItems->count(),
                        'user_id' => $transaction->user_id,
                    ];
                }
            } catch (\Exception $e) {
                $this->command->error(
                    "Error processing Transaction ID {$transaction->id}: " .
                    $e->getMessage()
                );
                $errorCount++;
            }
        }

        $this->command->newLine();
        $this->command->info('=================================');
        $this->command->info('Sync Complete!');
        $this->command->info('=================================');
        $this->command->info("Total transactions checked: {$totalTransactions}");
        $this->command->info("Cart items updated: {$updatedItemsCount}");
        $this->command->info("Cart items already in sync (skipped): {$skippedItemsCount}");
        $this->command->info("Errors encountered: {$errorCount}");
        $this->command->newLine();

        if (!empty($syncDetails)) {
            $this->command->info('Details of transactions with updated items:');
            $this->command->table(
                ['Transaction ID', 'Approval Status', 'Items Updated', 'Total Items', 'User ID'],
                array_map(function($detail) {
                    return [
                        $detail['transaction_id'],
                        $detail['approval_status'],
                        $detail['items_updated'],
                        $detail['total_items'],
                        $detail['user_id'],
                    ];
                }, $syncDetails)
            );
        } else {
            $this->command->info('All cart items were already in sync with their transactions.');
        }
    }
}
