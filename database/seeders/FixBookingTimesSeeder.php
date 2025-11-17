<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Booking;
use App\Models\CartItem;
use Carbon\Carbon;

class FixBookingTimesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder fixes inconsistent booking start_time and end_time
     * by recalculating them from associated cart items.
     */
    public function run(): void
    {
        $this->command->info('Starting to fix booking times from cart items...');

        DB::transaction(function () {
            // Get all bookings that have a cart_transaction_id
            // Only process bookings that are not cancelled or rejected
            $bookings = Booking::whereNotNull('cart_transaction_id')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->with('cartTransaction.cartItems')
                ->get();

            $this->command->info("Found {$bookings->count()} bookings to check.");

            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            foreach ($bookings as $booking) {
                try {
                    $result = $this->fixBookingTimes($booking);

                    if ($result === 'updated') {
                        $updatedCount++;
                    } elseif ($result === 'skipped') {
                        $skippedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->command->error("Error fixing booking #{$booking->id}: {$e->getMessage()}");
                    Log::error("FixBookingTimesSeeder error for booking #{$booking->id}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            $this->command->info("\n=== Summary ===");
            $this->command->info("Total bookings checked: {$bookings->count()}");
            $this->command->info("Bookings updated: {$updatedCount}");
            $this->command->info("Bookings already correct: {$skippedCount}");
            $this->command->info("Errors: {$errorCount}");
        });

        $this->command->info("\nDone!");
    }

    /**
     * Fix the start_time and end_time for a single booking
     *
     * @param Booking $booking
     * @return string 'updated', 'skipped', or 'error'
     */
    protected function fixBookingTimes(Booking $booking): string
    {
        // Get all active (non-cancelled, non-rejected) cart items for this booking's cart transaction and court
        $cartItems = CartItem::where('cart_transaction_id', $booking->cart_transaction_id)
            ->where('court_id', $booking->court_id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();

        if ($cartItems->isEmpty()) {
            $this->command->warn("Booking #{$booking->id} has no active cart items. Skipping.");
            return 'skipped';
        }

        // Calculate the correct start_time and end_time from cart items
        $earliestStart = null;
        $latestEnd = null;
        $totalPrice = 0;

        foreach ($cartItems as $item) {
            $bookingDate = Carbon::parse($item->booking_date)->format('Y-m-d');
            $itemStartDateTime = Carbon::parse($bookingDate . ' ' . $item->start_time);
            $itemEndDateTime = Carbon::parse($bookingDate . ' ' . $item->end_time);

            // Handle midnight crossing
            if ($itemEndDateTime->lte($itemStartDateTime)) {
                $itemEndDateTime->addDay();
            }

            // Track earliest start
            if ($earliestStart === null || $itemStartDateTime->lt($earliestStart)) {
                $earliestStart = $itemStartDateTime;
            }

            // Track latest end
            if ($latestEnd === null || $itemEndDateTime->gt($latestEnd)) {
                $latestEnd = $itemEndDateTime;
            }

            $totalPrice += floatval($item->price);
        }

        if (!$earliestStart || !$latestEnd) {
            $this->command->warn("Could not calculate times for booking #{$booking->id}. Skipping.");
            return 'skipped';
        }

        // Format the calculated times
        $calculatedStartTime = $earliestStart->format('Y-m-d H:i:s');
        $calculatedEndTime = $latestEnd->format('Y-m-d H:i:s');
        $calculatedPrice = round($totalPrice, 2);

        // Current booking times
        $currentStartTime = Carbon::parse($booking->start_time)->format('Y-m-d H:i:s');
        $currentEndTime = Carbon::parse($booking->end_time)->format('Y-m-d H:i:s');
        $currentPrice = floatval($booking->total_price);

        // Check if update is needed
        $needsUpdate = $currentStartTime !== $calculatedStartTime
                    || $currentEndTime !== $calculatedEndTime
                    || abs($currentPrice - $calculatedPrice) > 0.01;

        if (!$needsUpdate) {
            $this->command->line("Booking #{$booking->id} is already correct. Skipping.");
            return 'skipped';
        }

        // Show what will be updated
        $this->command->info("\nBooking #{$booking->id} needs update:");
        $this->command->line("  Cart Transaction ID: {$booking->cart_transaction_id}");
        $this->command->line("  Court: {$booking->court->name} (ID: {$booking->court_id})");
        $this->command->line("  Cart Items: {$cartItems->count()} items");

        if ($currentStartTime !== $calculatedStartTime) {
            $this->command->line("  Start Time: {$currentStartTime} → {$calculatedStartTime}");
        }

        if ($currentEndTime !== $calculatedEndTime) {
            $this->command->line("  End Time:   {$currentEndTime} → {$calculatedEndTime}");
        }

        if (abs($currentPrice - $calculatedPrice) > 0.01) {
            $this->command->line("  Price:      ₱{$currentPrice} → ₱{$calculatedPrice}");
        }

        // Update the booking
        $booking->update([
            'start_time' => $calculatedStartTime,
            'end_time' => $calculatedEndTime,
            'total_price' => $calculatedPrice
        ]);

        Log::info("FixBookingTimesSeeder: Updated booking #{$booking->id}", [
            'old_start' => $currentStartTime,
            'new_start' => $calculatedStartTime,
            'old_end' => $currentEndTime,
            'new_end' => $calculatedEndTime,
            'old_price' => $currentPrice,
            'new_price' => $calculatedPrice,
            'cart_items_count' => $cartItems->count()
        ]);

        return 'updated';
    }
}
