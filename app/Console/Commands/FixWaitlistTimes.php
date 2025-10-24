<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BookingWaitlist;
use App\Models\Booking;
use App\Models\CartItem;
use Carbon\Carbon;

class FixWaitlistTimes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'waitlist:fix-times';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix waitlist entry times to match their parent booking times';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to fix waitlist entry times...');

        // Get all waitlist entries that have a parent booking
        $waitlistEntries = BookingWaitlist::whereNotNull('pending_booking_id')
            ->orWhereNotNull('pending_cart_transaction_id')
            ->get();

        $fixed = 0;
        $skipped = 0;

        foreach ($waitlistEntries as $entry) {
            $parentStartTime = null;
            $parentEndTime = null;

            // Try to get times from pending_booking_id first
            if ($entry->pending_booking_id) {
                $parentBooking = Booking::find($entry->pending_booking_id);
                if ($parentBooking) {
                    $parentStartTime = $parentBooking->start_time;
                    $parentEndTime = $parentBooking->end_time;
                    $this->line("Waitlist #{$entry->id}: Found parent booking #{$parentBooking->id}");
                }
            }

            // If no booking found, try to get times from cart transaction
            if (!$parentStartTime && $entry->pending_cart_transaction_id) {
                $cartItem = CartItem::where('cart_transaction_id', $entry->pending_cart_transaction_id)
                    ->where('court_id', $entry->court_id)
                    ->first();

                if ($cartItem) {
                    // Construct datetime from cart item
                    $parentStartTime = $cartItem->booking_date . ' ' . $cartItem->start_time;

                    // Handle midnight crossing
                    $startTime = Carbon::parse($cartItem->start_time);
                    $endTime = Carbon::parse($cartItem->end_time);

                    if ($endTime->lte($startTime)) {
                        $endDate = Carbon::parse($cartItem->booking_date)->addDay()->format('Y-m-d');
                        $parentEndTime = $endDate . ' ' . $cartItem->end_time;
                    } else {
                        $parentEndTime = $cartItem->booking_date . ' ' . $cartItem->end_time;
                    }

                    $this->line("Waitlist #{$entry->id}: Found parent cart item");
                }
            }

            // Update the waitlist entry if we found parent times
            if ($parentStartTime && $parentEndTime) {
                $oldStart = $entry->start_time;
                $oldEnd = $entry->end_time;

                $entry->start_time = $parentStartTime;
                $entry->end_time = $parentEndTime;
                $entry->save();

                $this->info("✅ Fixed Waitlist #{$entry->id}:");
                $this->line("   Old: {$oldStart} - {$oldEnd}");
                $this->line("   New: {$parentStartTime} - {$parentEndTime}");

                $fixed++;
            } else {
                $this->warn("⚠️  Skipped Waitlist #{$entry->id}: No parent booking found");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("✅ Fixed {$fixed} waitlist entries");
        if ($skipped > 0) {
            $this->warn("⚠️  Skipped {$skipped} entries (no parent booking found)");
        }

        return Command::SUCCESS;
    }
}
