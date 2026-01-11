<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PosSaleItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdatePosSaleItemCostSeeder extends Seeder
{
    /**
     * Update PosSaleItem unit_cost to match the current Product cost.
     * Only updates items where unit_cost differs from the product's cost.
     */
    public function run(): void
    {
        $this->command->info('Starting to update PosSaleItem unit_cost from Product cost...');

        // Get all POS sale items with their products
        $saleItems = PosSaleItem::with('product')->get();

        $totalItems = $saleItems->count();
        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $this->command->info("Found {$totalItems} sale items to check");
        $this->command->newLine();

        // Use a progress bar for better feedback
        $bar = $this->command->getOutput()->createProgressBar($totalItems);
        $bar->start();

        foreach ($saleItems as $saleItem) {
            try {
                // Skip if product doesn't exist
                if (!$saleItem->product) {
                    $this->command->warn("Sale item ID {$saleItem->id} has no product - skipping");
                    $skippedCount++;
                    $bar->advance();
                    continue;
                }

                $productCost = $saleItem->product->cost;
                $currentUnitCost = $saleItem->unit_cost;

                // Only update if the unit_cost is different from product cost
                if ($currentUnitCost != $productCost) {
                    $saleItem->unit_cost = $productCost;
                    $saleItem->save();

                    $updatedCount++;

                    // Show details for the first 10 updates
                    if ($updatedCount <= 10) {
                        $bar->clear();
                        $this->command->info(
                            "Updated Sale Item #{$saleItem->id} - Product: {$saleItem->product->name} " .
                            "(SKU: {$saleItem->product->sku}) - Cost: ₱{$currentUnitCost} → ₱{$productCost}"
                        );
                        $bar->display();
                    }
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                $bar->clear();
                $this->command->error("Error updating sale item ID {$saleItem->id}: " . $e->getMessage());
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        // Summary
        $this->command->info('=================================================');
        $this->command->info('Update Summary:');
        $this->command->info('=================================================');
        $this->command->info("Total items checked:     {$totalItems}");
        $this->command->info("Items updated:           {$updatedCount}");
        $this->command->info("Items skipped (same):    {$skippedCount}");
        if ($errorCount > 0) {
            $this->command->error("Items with errors:       {$errorCount}");
        }
        $this->command->info('=================================================');

        if ($updatedCount > 0) {
            $this->command->info("✓ Successfully updated {$updatedCount} sale item(s) with new cost values!");
        } else {
            $this->command->info("No sale items needed updating - all costs are already synchronized.");
        }
    }
}
