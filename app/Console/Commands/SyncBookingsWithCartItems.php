<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Models\CartTransaction;
use App\Models\CartItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SyncBookingsWithCartItems extends Command
{
    protected $signature = 'bookings:sync-cart-items {--dry-run : Show what would be changed without making changes} {--fix : Actually fix the inconsistencies}';
    protected $description = 'Synchronize bookings with their active cart items, fixing stale booking data';

    protected $issues = [];
    protected $fixed = [];

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $fix = $this->option('fix');

        if (!$dryRun && !$fix) {
            $this->error('You must specify either --dry-run or --fix');
            $this->info('Usage:');
            $this->info('  php artisan bookings:sync-cart-items --dry-run   (preview changes)');
            $this->info('  php artisan bookings:sync-cart-items --fix       (apply changes)');
            return 1;
        }

        $this->info('==========================================');
        $this->info('Booking-Cart Item Synchronization Tool');
        $this->info('==========================================');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        } else {
            $this->warn('⚠️  FIX MODE - Changes will be applied to the database');
        }
        $this->newLine();

        // Get all bookings with cart transactions
        $bookings = Booking::whereNotNull('cart_transaction_id')
            ->with(['cartTransaction.cartItems' => function($query) {
                $query->orderBy('booking_date')->orderBy('start_time');
            }, 'court'])
            ->get();

        $this->info("Found {$bookings->count()} bookings linked to cart transactions");
        $this->newLine();

        $issueCount = 0;
        $fixedCount = 0;

        foreach ($bookings as $booking) {
            $result = $this->analyzeBooking($booking, $dryRun, $fix);
            if ($result['has_issue']) {
                $issueCount++;
                if ($result['fixed']) {
                    $fixedCount++;
                }
            }
        }

        $this->newLine();
        $this->info('==========================================');
        $this->info('SUMMARY');
        $this->info('==========================================');
        $this->line("Total bookings analyzed: {$bookings->count()}");
        $this->line("Bookings with issues: {$issueCount}");

        if ($fix) {
            $this->line("Bookings fixed: {$fixedCount}");
            $this->newLine();
            if ($fixedCount > 0) {
                $this->info("✅ Successfully fixed {$fixedCount} booking(s)!");
            }
        } else {
            $this->newLine();
            $this->warn("Run with --fix to apply these changes");
        }

        if ($issueCount === 0) {
            $this->newLine();
            $this->info("✅ All bookings are in sync with their cart items!");
        }

        return 0;
    }

    protected function analyzeBooking($booking, $dryRun, $fix)
    {
        $hasIssue = false;
        $fixed = false;

        if (!$booking->cartTransaction) {
            $this->warn("Booking #{$booking->id}: Cart transaction #{$booking->cart_transaction_id} not found");
            return ['has_issue' => true, 'fixed' => false];
        }

        // Get all cart items for this transaction
        $allCartItems = $booking->cartTransaction->cartItems;

        // Filter to only completed/active items for this specific court
        $activeCartItems = $allCartItems->filter(function($item) use ($booking) {
            return $item->court_id == $booking->court_id &&
                   $item->status !== 'cancelled';
        });

        // If no active cart items, this booking should probably be cancelled
        if ($activeCartItems->isEmpty()) {
            $hasIssue = true;
            $this->error("Booking #{$booking->id}: All cart items are cancelled but booking is still {$booking->status}");
            $this->line("  Court: {$booking->court->name}");
            $this->line("  Time: {$booking->start_time} - {$booking->end_time}");
            $this->line("  Price: ₱{$booking->total_price}");
            $this->line("  Recommendation: Should be cancelled or deleted");
            $this->newLine();

            if ($fix) {
                $booking->update(['status' => 'cancelled']);
                $this->info("  ✅ Fixed: Set booking status to 'cancelled'");
                $this->newLine();
                $fixed = true;
            }

            return ['has_issue' => $hasIssue, 'fixed' => $fixed];
        }

        // Calculate expected booking details from active cart items
        $expectedStartTime = null;
        $expectedEndTime = null;
        $expectedPrice = 0;

        foreach ($activeCartItems as $item) {
            $bookingDate = Carbon::parse($item->booking_date)->format('Y-m-d');
            $itemStart = Carbon::parse($bookingDate . ' ' . $item->start_time);
            $itemEnd = Carbon::parse($bookingDate . ' ' . $item->end_time);

            // Handle midnight crossing
            if ($itemEnd->lte($itemStart)) {
                $itemEnd->addDay();
            }

            if ($expectedStartTime === null || $itemStart->lt($expectedStartTime)) {
                $expectedStartTime = $itemStart;
            }

            if ($expectedEndTime === null || $itemEnd->gt($expectedEndTime)) {
                $expectedEndTime = $itemEnd;
            }

            $expectedPrice += floatval($item->price);
        }

        // Compare with actual booking
        $actualStartTime = Carbon::parse($booking->start_time);
        $actualEndTime = Carbon::parse($booking->end_time);
        $actualPrice = floatval($booking->total_price);

        $startMismatch = !$actualStartTime->eq($expectedStartTime);
        $endMismatch = !$actualEndTime->eq($expectedEndTime);
        $priceMismatch = abs($actualPrice - $expectedPrice) > 0.01;

        if ($startMismatch || $endMismatch || $priceMismatch) {
            $hasIssue = true;
            $this->error("Booking #{$booking->id}: Mismatch with active cart items");
            $this->line("  Court: {$booking->court->name}");
            $this->line("  Active cart items: {$activeCartItems->count()}");

            if ($startMismatch) {
                $this->line("  Start Time:");
                $this->line("    Current:  {$actualStartTime->format('Y-m-d H:i:s')}");
                $this->line("    Expected: {$expectedStartTime->format('Y-m-d H:i:s')} ⚠️");
            }

            if ($endMismatch) {
                $this->line("  End Time:");
                $this->line("    Current:  {$actualEndTime->format('Y-m-d H:i:s')}");
                $this->line("    Expected: {$expectedEndTime->format('Y-m-d H:i:s')} ⚠️");
            }

            if ($priceMismatch) {
                $this->line("  Price:");
                $this->line("    Current:  ₱{$actualPrice}");
                $this->line("    Expected: ₱{$expectedPrice} ⚠️");
            }

            // Show which cart items are active vs cancelled
            $this->line("  Cart Items Breakdown:");
            foreach ($allCartItems->where('court_id', $booking->court_id) as $item) {
                $status = $item->status === 'cancelled' ? '❌ CANCELLED' : '✅ ' . strtoupper($item->status);
                $bookingDate = Carbon::parse($item->booking_date)->format('Y-m-d');
                $this->line("    #{$item->id}: {$bookingDate} {$item->start_time}-{$item->end_time} ₱{$item->price} {$status}");
            }

            if ($fix) {
                try {
                    DB::beginTransaction();

                    $booking->update([
                        'start_time' => $expectedStartTime->format('Y-m-d H:i:s'),
                        'end_time' => $expectedEndTime->format('Y-m-d H:i:s'),
                        'total_price' => $expectedPrice
                    ]);

                    DB::commit();
                    $this->info("  ✅ Fixed: Updated booking to match active cart items");
                    $fixed = true;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error("  ❌ Error fixing booking: " . $e->getMessage());
                }
            }

            $this->newLine();
        }

        return ['has_issue' => $hasIssue, 'fixed' => $fixed];
    }
}
