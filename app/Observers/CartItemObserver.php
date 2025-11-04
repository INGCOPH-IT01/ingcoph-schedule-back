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
     * Sync bookings when cart item status changes or court_id changes
     */
    public function updated(CartItem $cartItem)
    {
        // Handle status changes to 'cancelled'
        if ($cartItem->isDirty('status') && $cartItem->status === 'cancelled') {
            // Wrap in transaction for atomicity
            DB::transaction(function () use ($cartItem) {
                $this->syncBookingAfterCartItemCancellation($cartItem);
            });
        }

        // Handle court_id changes
        if ($cartItem->isDirty('court_id')) {
            // Note: This is called within the existing transaction from CartController
            $this->syncBookingAfterCourtChange($cartItem);
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

    /**
     * Sync booking when a cart item's court is changed
     *
     * When a cart item's court changes, we need to recalculate bookings because:
     * - Multiple cart items can be grouped into a single booking during checkout
     * - Changing one cart item's court may require splitting or recalculating bookings
     *
     * NOTE: This method should be called within a database transaction.
     */
    protected function syncBookingAfterCourtChange(CartItem $cartItem)
    {
        if (!$cartItem->cart_transaction_id) {
            return;
        }

        $oldCourtId = $cartItem->getOriginal('court_id');
        $newCourtId = $cartItem->court_id;

        Log::info("Cart item #{$cartItem->id} court changed from {$oldCourtId} to {$newCourtId}");

        // Build datetime strings for the cart item
        $bookingDate = Carbon::parse($cartItem->booking_date)->format('Y-m-d');
        $startDateTime = $bookingDate . ' ' . $cartItem->start_time;
        $endDateTime = $bookingDate . ' ' . $cartItem->end_time;

        // Handle midnight crossing
        $startTime = Carbon::parse($startDateTime);
        $endTime = Carbon::parse($endDateTime);
        if ($endTime->lte($startTime)) {
            $endDateTime = Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $cartItem->end_time;
        }

        // Find bookings for this cart transaction on the old court that OVERLAP with this cart item's time
        // A booking might span multiple cart items (e.g., booking from 9-12, cart item from 10-11)
        $affectedBookings = Booking::where('cart_transaction_id', $cartItem->cart_transaction_id)
            ->where('court_id', $oldCourtId)
            ->where(function ($query) use ($startDateTime, $endDateTime) {
                $query->where(function ($q) use ($startDateTime, $endDateTime) {
                    // Booking overlaps with cart item time range
                    $q->where('start_time', '<', $endDateTime)
                      ->where('end_time', '>', $startDateTime);
                });
            })
            ->get();

        if ($affectedBookings->isEmpty()) {
            Log::info("No bookings found to update for cart item #{$cartItem->id}");
            return;
        }

        foreach ($affectedBookings as $booking) {
            Log::info("Processing booking #{$booking->id} (was {$booking->start_time} to {$booking->end_time} on court {$oldCourtId})");

            // Get all active cart items for this booking's transaction and date range
            // that fall within or overlap with the original booking time
            $allCartItems = CartItem::where('cart_transaction_id', $cartItem->cart_transaction_id)
                ->where('status', '!=', 'cancelled')
                ->where('booking_date', $bookingDate)
                ->orderBy('start_time')
                ->get();

            // Filter to cart items that overlap with this booking
            $relevantCartItems = $allCartItems->filter(function($item) use ($booking, $bookingDate) {
                $itemStartDateTime = $bookingDate . ' ' . $item->start_time;
                $itemEndDateTime = $bookingDate . ' ' . $item->end_time;

                $itemStart = Carbon::parse($itemStartDateTime);
                $itemEnd = Carbon::parse($itemEndDateTime);
                if ($itemEnd->lte($itemStart)) {
                    $itemEnd->addDay();
                }

                $bookingStart = Carbon::parse($booking->start_time);
                $bookingEnd = Carbon::parse($booking->end_time);

                // Check if cart item overlaps with booking
                return $itemStart->lt($bookingEnd) && $itemEnd->gt($bookingStart);
            });

            if ($relevantCartItems->isEmpty()) {
                // No cart items left for this booking - cancel it
                $booking->update(['status' => 'cancelled']);
                Log::info("Booking #{$booking->id} cancelled - no cart items remain");
                continue;
            }

            // Re-group cart items by court and find the group that contains the original booking
            $newGroups = $this->groupCartItemsByCourtAndTime($relevantCartItems, $bookingDate);

            // Find which group(s) should replace this booking
            // The original booking was on $oldCourtId - find new groups on both old and new courts
            $matchingGroups = [];
            foreach ($newGroups as $group) {
                $groupStart = Carbon::parse($group['start_time']);
                $groupEnd = Carbon::parse($group['end_time']);
                $bookingStart = Carbon::parse($booking->start_time);
                $bookingEnd = Carbon::parse($booking->end_time);

                // Check if group overlaps with original booking
                if ($groupStart->lt($bookingEnd) && $groupEnd->gt($bookingStart)) {
                    $matchingGroups[] = $group;
                }
            }

            if (count($matchingGroups) === 1 && $matchingGroups[0]['court_id'] == $oldCourtId) {
                // Simple case: All items still on old court, just update time/price if needed
                $group = $matchingGroups[0];
                $booking->update([
                    'start_time' => $group['start_time'],
                    'end_time' => $group['end_time'],
                    'total_price' => $group['price']
                ]);
                Log::info("Updated booking #{$booking->id} - same court, adjusted times");
            } elseif (count($matchingGroups) === 1 && $matchingGroups[0]['court_id'] == $newCourtId) {
                // All items moved to new court - update the booking to new court
                $group = $matchingGroups[0];
                $booking->update([
                    'court_id' => $newCourtId,
                    'start_time' => $group['start_time'],
                    'end_time' => $group['end_time'],
                    'total_price' => $group['price']
                ]);
                Log::info("Updated booking #{$booking->id} - moved to court {$newCourtId}");
            } else {
                // Complex case: Booking needs to be split across multiple courts
                // Keep the first group in the existing booking, create new bookings for others
                $firstGroup = array_shift($matchingGroups);
                $booking->update([
                    'court_id' => $firstGroup['court_id'],
                    'start_time' => $firstGroup['start_time'],
                    'end_time' => $firstGroup['end_time'],
                    'total_price' => $firstGroup['price']
                ]);
                Log::info("Updated booking #{$booking->id} to court {$firstGroup['court_id']}");

                // Create additional bookings for remaining groups
                foreach ($matchingGroups as $group) {
                    $newBooking = Booking::create([
                        'user_id' => $booking->user_id,
                        'cart_transaction_id' => $booking->cart_transaction_id,
                        'court_id' => $group['court_id'],
                        'sport_id' => $booking->sport_id,
                        'start_time' => $group['start_time'],
                        'end_time' => $group['end_time'],
                        'total_price' => $group['price'],
                        'number_of_players' => $booking->number_of_players,
                        'status' => $booking->status,
                        'notes' => $booking->notes,
                        'admin_notes' => $booking->admin_notes,
                        'payment_method' => $booking->payment_method,
                        'payment_status' => $booking->payment_status,
                        'proof_of_payment' => $booking->proof_of_payment,
                        'paid_at' => $booking->paid_at,
                        'booking_for_user_id' => $booking->booking_for_user_id,
                        'booking_for_user_name' => $booking->booking_for_user_name,
                    ]);
                    Log::info("Created new booking #{$newBooking->id} for court {$group['court_id']}");
                }
            }
        }

        Log::info("Completed court change sync for cart item #{$cartItem->id}");
    }

    /**
     * Group cart items by court and consecutive time slots
     * Similar to the grouping logic used during checkout
     */
    protected function groupCartItemsByCourtAndTime($cartItems, $bookingDate)
    {
        $groups = [];
        $currentGroup = null;

        foreach ($cartItems as $item) {
            $groupKey = $item->court_id . '_' . $bookingDate;

            if (!$currentGroup || $currentGroup['key'] !== $groupKey ||
                $currentGroup['end_time_raw'] !== $item->start_time) {
                // Start new group
                if ($currentGroup) {
                    $groups[] = $currentGroup;
                }

                $startDateTime = $bookingDate . ' ' . $item->start_time;
                $endDateTime = $bookingDate . ' ' . $item->end_time;

                // Handle midnight crossing
                $startTime = Carbon::parse($startDateTime);
                $endTime = Carbon::parse($endDateTime);
                if ($endTime->lte($startTime)) {
                    $endDateTime = Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $item->end_time;
                }

                $currentGroup = [
                    'key' => $groupKey,
                    'court_id' => $item->court_id,
                    'start_time' => $startDateTime,
                    'end_time' => $endDateTime,
                    'end_time_raw' => $item->end_time,
                    'price' => floatval($item->price),
                    'items' => [$item->id]
                ];
            } else {
                // Extend current group
                $endDateTime = $bookingDate . ' ' . $item->end_time;

                // Handle midnight crossing
                $startTime = Carbon::parse($currentGroup['start_time']);
                $endTime = Carbon::parse($endDateTime);
                if ($endTime->lte($startTime)) {
                    $endDateTime = Carbon::parse($bookingDate)->addDay()->format('Y-m-d') . ' ' . $item->end_time;
                }

                $currentGroup['end_time'] = $endDateTime;
                $currentGroup['end_time_raw'] = $item->end_time;
                $currentGroup['price'] += floatval($item->price);
                $currentGroup['items'][] = $item->id;
            }
        }

        // Add last group
        if ($currentGroup) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }
}
