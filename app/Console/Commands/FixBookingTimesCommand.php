<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Booking;
use App\Models\CartItem;
use Carbon\Carbon;

class FixBookingTimesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'booking:fix-times
                            {--dry-run : Preview changes without applying them}
                            {--booking= : Fix specific booking ID only}
                            {--transaction= : Fix bookings for specific cart transaction ID only}
                            {--verbose : Show detailed output for each booking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix inconsistent booking start_time and end_time based on cart items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $bookingId = $this->option('booking');
        $transactionId = $this->option('transaction');
        $verbose = $this->option('verbose');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made to the database');
        }

        $this->info('Starting to fix booking times from cart items...');
        $this->newLine();

        // Build query
        $query = Booking::whereNotNull('cart_transaction_id')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with('cartTransaction.cartItems', 'court');

        // Apply filters
        if ($bookingId) {
            $query->where('id', $bookingId);
            $this->info("Filtering to booking ID: {$bookingId}");
        }

        if ($transactionId) {
            $query->where('cart_transaction_id', $transactionId);
            $this->info("Filtering to transaction ID: {$transactionId}");
        }

        $bookings = $query->get();
        $this->info("Found {$bookings->count()} booking(s) to check.");
        $this->newLine();

        $updatedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        $processBooking = function ($booking) use (&$updatedCount, &$skippedCount, &$errorCount, $dryRun, $verbose) {
            try {
                $result = $this->fixBookingTimes($booking, $dryRun, $verbose);

                if ($result === 'updated') {
                    $updatedCount++;
                } elseif ($result === 'skipped') {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("❌ Error fixing booking #{$booking->id}: {$e->getMessage()}");
                Log::error("FixBookingTimesCommand error for booking #{$booking->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        };

        // Process bookings (with or without transaction)
        if ($dryRun) {
            // Dry run - no transaction needed
            foreach ($bookings as $booking) {
                $processBooking($booking);
            }
        } else {
            // Real run - use transaction
            DB::transaction(function () use ($bookings, $processBooking) {
                foreach ($bookings as $booking) {
                    $processBooking($booking);
                }
            });
        }

        // Summary
        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 SUMMARY');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total bookings checked', $bookings->count()],
                ['Bookings updated', $updatedCount],
                ['Bookings already correct', $skippedCount],
                ['Errors', $errorCount]
            ]
        );

        if ($dryRun && $updatedCount > 0) {
            $this->newLine();
            $this->warn('⚠️  DRY RUN - No changes were actually made!');
            $this->info('Run without --dry-run to apply changes.');
        } elseif (!$dryRun && $updatedCount > 0) {
            $this->newLine();
            $this->info("✅ Successfully updated {$updatedCount} booking(s)!");
        } elseif ($updatedCount === 0 && $errorCount === 0) {
            $this->newLine();
            $this->info('✅ All bookings are already in sync with their cart items!');
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Fix the start_time and end_time for a single booking
     *
     * @param Booking $booking
     * @param bool $dryRun
     * @param bool $verbose
     * @return string 'updated', 'skipped', or 'error'
     */
    protected function fixBookingTimes(Booking $booking, bool $dryRun = false, bool $verbose = false): string
    {
        // Get all active (non-cancelled, non-rejected) cart items for this booking's cart transaction and court
        $cartItems = CartItem::where('cart_transaction_id', $booking->cart_transaction_id)
            ->where('court_id', $booking->court_id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->get();

        if ($cartItems->isEmpty()) {
            if ($verbose) {
                $this->warn("⚠️  Booking #{$booking->id} has no active cart items. Skipping.");
            }
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
            if ($verbose) {
                $this->warn("⚠️  Could not calculate times for booking #{$booking->id}. Skipping.");
            }
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
            if ($verbose) {
                $this->line("✓ Booking #{$booking->id} is already correct. Skipping.");
            }
            return 'skipped';
        }

        // Show what will be updated
        $this->newLine();
        $this->info("🔧 Booking #{$booking->id} needs update:");
        $this->line("   Transaction ID: {$booking->cart_transaction_id}");
        $this->line("   Court: {$booking->court->name} (ID: {$booking->court_id})");
        $this->line("   Cart Items: {$cartItems->count()} items");

        if ($currentStartTime !== $calculatedStartTime) {
            $this->line("   Start Time: <fg=red>{$currentStartTime}</> → <fg=green>{$calculatedStartTime}</>");
        }

        if ($currentEndTime !== $calculatedEndTime) {
            $this->line("   End Time:   <fg=red>{$currentEndTime}</> → <fg=green>{$calculatedEndTime}</>");
        }

        if (abs($currentPrice - $calculatedPrice) > 0.01) {
            $this->line("   Price:      <fg=red>₱{$currentPrice}</> → <fg=green>₱{$calculatedPrice}</>");
        }

        if ($dryRun) {
            $this->comment("   [DRY RUN - Would update this booking]");
        } else {
            // Update the booking
            $booking->update([
                'start_time' => $calculatedStartTime,
                'end_time' => $calculatedEndTime,
                'total_price' => $calculatedPrice
            ]);

            $this->comment("   ✅ Updated successfully!");

            Log::info("FixBookingTimesCommand: Updated booking #{$booking->id}", [
                'old_start' => $currentStartTime,
                'new_start' => $calculatedStartTime,
                'old_end' => $currentEndTime,
                'new_end' => $calculatedEndTime,
                'old_price' => $currentPrice,
                'new_price' => $calculatedPrice,
                'cart_items_count' => $cartItems->count()
            ]);
        }

        return 'updated';
    }
}
