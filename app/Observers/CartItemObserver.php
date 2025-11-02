<?php

namespace App\Observers;

use App\Models\CartItem;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartItemObserver
{
    /**
     * Handle the CartItem "updated" event.
     * Sync bookings when cart item status changes
     */
    public function updated(CartItem $cartItem)
    {
        // Only act on status changes to 'cancelled'
        if ($cartItem->isDirty('status') && $cartItem->status === 'cancelled') {
            // Wrap in transaction for atomicity
            DB::transaction(function () use ($cartItem) {
                $this->syncBookingAfterCartItemCancellation($cartItem);
            });
        }
    }

    /**
     * Sync booking when a cart item is cancelled
     *
     * NOTE: This method should be called within a database transaction.
     */
    protected function syncBookingAfterCartItemCancellation(CartItem $cartItem)
    {
        if (!$cartItem->cart_transaction_id) {
            return;
        }

        // Find bookings associated with this cart transaction and court
        $bookings = Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
            ->where('court_id', $cartItem->court_id)
            ->get();

        foreach ($bookings as $booking) {
            // Get all active (non-cancelled) cart items for this booking's court
            $activeCartItems = CartItem::where('cart_transaction_id', $cartItem->cart_transaction_id)
                ->where('court_id', $booking->court_id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('booking_date')
                ->orderBy('start_time')
                ->get();

            if ($activeCartItems->isEmpty()) {
                // All cart items cancelled - cancel the booking
                $booking->update(['status' => 'cancelled']);
                Log::info("Booking #{$booking->id} cancelled - all cart items cancelled");
            } else {
                // Recalculate booking times and price from remaining active cart items
                $startTime = null;
                $endTime = null;
                $totalPrice = 0;

                foreach ($activeCartItems as $item) {
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

                    Log::info("Booking #{$booking->id} synced after cart item cancellation", [
                        'old_start' => $booking->getOriginal('start_time'),
                        'new_start' => $startTime->format('Y-m-d H:i:s'),
                        'old_price' => $booking->getOriginal('total_price'),
                        'new_price' => $totalPrice
                    ]);
                }
            }
        }
    }
}
