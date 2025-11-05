<?php

namespace App\Observers;

use App\Models\WaitlistCartItem;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistCartItemObserver
{
    /**
     * Handle the WaitlistCartItem "updated" event.
     * Sync bookings when waitlist cart item status changes or court_id changes or date/time changes
     */
    public function updated(WaitlistCartItem $waitlistCartItem)
    {
        // Handle status changes to 'cancelled'
        if ($waitlistCartItem->isDirty('status') && $waitlistCartItem->status === 'cancelled') {
            // Wrap in transaction for atomicity
            DB::transaction(function () use ($waitlistCartItem) {
                $this->syncBookingAfterWaitlistCartItemCancellation($waitlistCartItem);
            });
        }

        // Handle court_id changes
        if ($waitlistCartItem->isDirty('court_id')) {
            // Note: This is called within the existing transaction from controller
            $this->syncBookingAfterCourtChange($waitlistCartItem);
        }

        // Handle booking_date, start_time, or end_time changes
        if ($waitlistCartItem->isDirty('booking_date') || $waitlistCartItem->isDirty('start_time') || $waitlistCartItem->isDirty('end_time')) {
            // Note: This is called within the existing transaction from controller
            $this->syncBookingAfterDateTimeChange($waitlistCartItem);
        }
    }

    /**
     * Sync booking when a waitlist cart item is cancelled
     *
     * NOTE: This method should be called within a database transaction.
     */
    protected function syncBookingAfterWaitlistCartItemCancellation(WaitlistCartItem $waitlistCartItem)
    {
        if (!$waitlistCartItem->waitlist_cart_transaction_id) {
            return;
        }

        // Find bookings associated with the waitlist cart transaction's converted cart transaction and court
        $waitlistCartTransaction = $waitlistCartItem->waitlistCartTransaction;
        if (!$waitlistCartTransaction) {
            return;
        }

        // Get the regular cart_transaction_id from the booking_waitlist's converted_cart_transaction_id
        $bookingWaitlist = $waitlistCartTransaction->bookingWaitlist;
        if (!$bookingWaitlist || !$bookingWaitlist->converted_cart_transaction_id) {
            return;
        }

        // Find bookings associated with this cart transaction and court
        $bookings = Booking::where('cart_transaction_id', $bookingWaitlist->converted_cart_transaction_id)
            ->where('court_id', $waitlistCartItem->court_id)
            ->get();

        foreach ($bookings as $booking) {
            // Get all active (non-cancelled) waitlist cart items for this booking's court
            $activeWaitlistCartItems = WaitlistCartItem::where('waitlist_cart_transaction_id', $waitlistCartItem->waitlist_cart_transaction_id)
                ->where('court_id', $booking->court_id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('booking_date')
                ->orderBy('start_time')
                ->get();

            if ($activeWaitlistCartItems->isEmpty()) {
                // All waitlist cart items cancelled - cancel the booking
                $booking->update(['status' => 'cancelled']);
                Log::info("Booking #{$booking->id} cancelled - all waitlist cart items cancelled");
            } else {
                // Recalculate booking times and price from remaining active waitlist cart items
                $startTime = null;
                $endTime = null;
                $totalPrice = 0;

                foreach ($activeWaitlistCartItems as $item) {
                    $bookingDate = Carbon::parse($item->booking_date)->format('Y-m-d');
                    $itemStart = Carbon::parse($bookingDate . ' ' . $item->start_time);
                    $itemEnd = Carbon::parse($bookingDate . ' ' . $item->end_time);

                    // Handle midnight crossing
                    if ($itemEnd->lte($itemStart)) {
                        $itemEnd->addDay();
                    }

                    if ($startTime === null || $itemStart->lt($startTime)) {
                        $startTime = $itemStart;
                    }

                    if ($endTime === null || $itemEnd->gt($endTime)) {
                        $endTime = $itemEnd;
                    }

                    $totalPrice += floatval($item->price);
                }

                // Update booking with recalculated values
                if ($startTime && $endTime) {
                    $booking->update([
                        'start_time' => $startTime->format('Y-m-d H:i:s'),
                        'end_time' => $endTime->format('Y-m-d H:i:s'),
                        'total_price' => $totalPrice
                    ]);

                    Log::info("Booking #{$booking->id} synced after waitlist cart item cancellation", [
                        'old_start' => $booking->getOriginal('start_time'),
                        'new_start' => $startTime->format('Y-m-d H:i:s'),
                        'old_price' => $booking->getOriginal('total_price'),
                        'new_price' => $totalPrice
                    ]);
                }
            }
        }
    }

    /**
     * Sync booking when a waitlist cart item's court is changed
     *
     * NOTE: This method should be called within a database transaction.
     */
    protected function syncBookingAfterCourtChange(WaitlistCartItem $waitlistCartItem)
    {
        // TODO: Implement court change logic similar to CartItemObserver
        // This is a placeholder for future implementation
        Log::info("Waitlist cart item #{$waitlistCartItem->id} court changed - sync logic not yet implemented");
    }

    /**
     * Sync booking when a waitlist cart item's date or time is changed
     *
     * NOTE: This method should be called within a database transaction.
     */
    protected function syncBookingAfterDateTimeChange(WaitlistCartItem $waitlistCartItem)
    {
        // TODO: Implement date/time change logic similar to CartItemObserver
        // This is a placeholder for future implementation
        Log::info("Waitlist cart item #{$waitlistCartItem->id} date/time changed - sync logic not yet implemented");
    }
}
