<?php

namespace App\Services;

use App\Models\BookingWaitlist;
use App\Models\CartItem;
use App\Models\CartTransaction;
use App\Models\WaitlistCartItem;
use App\Models\WaitlistCartTransaction;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistCartService
{
    /**
     * Create waitlist cart records when a BookingWaitlist entry is created (from cart checkout)
     *
     * @param BookingWaitlist $waitlistEntry The waitlist entry that was created
     * @param CartItem $originalCartItem The original cart item being waitlisted
     * @param CartTransaction $originalCartTransaction The original cart transaction
     * @return array ['waitlistCartItem' => WaitlistCartItem, 'waitlistCartTransaction' => WaitlistCartTransaction]
     */
    public function createWaitlistCartRecords(
        BookingWaitlist $waitlistEntry,
        CartItem $originalCartItem,
        CartTransaction $originalCartTransaction
    ): array {
        // Create or find WaitlistCartTransaction
        $waitlistCartTransaction = WaitlistCartTransaction::firstOrCreate(
            [
                'user_id' => $originalCartTransaction->user_id,
                'booking_waitlist_id' => $waitlistEntry->id,
            ],
            [
                'booking_for_user_id' => $originalCartTransaction->booking_for_user_id,
                'booking_for_user_name' => $originalCartTransaction->booking_for_user_name,
                'total_price' => $originalCartItem->price, // Will be updated as items are added
                'status' => 'pending',
                'approval_status' => 'pending',
                'payment_method' => $originalCartTransaction->payment_method,
                'payment_status' => $originalCartTransaction->payment_status,
            ]
        );

        // Create WaitlistCartItem with reference to original CartItem.id
        $waitlistCartItem = WaitlistCartItem::create([
            'user_id' => $originalCartItem->user_id,
            'booking_for_user_id' => $originalCartItem->booking_for_user_id,
            'booking_for_user_name' => $originalCartItem->booking_for_user_name,
            'waitlist_cart_transaction_id' => $waitlistCartTransaction->id,
            'booking_waitlist_id' => $waitlistEntry->id,
            'court_id' => $originalCartItem->court_id,
            'sport_id' => $originalCartItem->sport_id,
            'booking_date' => $originalCartItem->booking_date,
            'start_time' => $originalCartItem->start_time,
            'end_time' => $originalCartItem->end_time,
            'price' => $originalCartItem->price,
            'number_of_players' => $originalCartItem->number_of_players,
            'status' => 'pending',
            'notes' => $originalCartItem->notes,
            'admin_notes' => $originalCartItem->admin_notes,
            'session_id' => $originalCartItem->session_id,
        ]);

        Log::info('Created waitlist cart records', [
            'waitlist_entry_id' => $waitlistEntry->id,
            'original_cart_item_id' => $originalCartItem->id,
            'original_cart_transaction_id' => $originalCartTransaction->id,
            'waitlist_cart_item_id' => $waitlistCartItem->id,
            'waitlist_cart_transaction_id' => $waitlistCartTransaction->id,
        ]);

        return [
            'waitlistCartItem' => $waitlistCartItem,
            'waitlistCartTransaction' => $waitlistCartTransaction
        ];
    }

    /**
     * Create waitlist cart records when a BookingWaitlist entry is created (from direct booking)
     * This is used when there's no existing cart item/transaction yet
     *
     * @param BookingWaitlist $waitlistEntry The waitlist entry that was created
     * @return array ['waitlistCartItem' => WaitlistCartItem, 'waitlistCartTransaction' => WaitlistCartTransaction]
     */
    public function createWaitlistCartRecordsFromWaitlist(BookingWaitlist $waitlistEntry): array
    {
        // Create WaitlistCartTransaction
        $waitlistCartTransaction = WaitlistCartTransaction::create([
            'user_id' => $waitlistEntry->user_id,
            'booking_for_user_id' => $waitlistEntry->booking_for_user_id,
            'booking_for_user_name' => $waitlistEntry->booking_for_user_name,
            'booking_waitlist_id' => $waitlistEntry->id,
            'total_price' => $waitlistEntry->price,
            'status' => 'pending',
            'approval_status' => 'pending',
            'payment_method' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        // Calculate booking date from start_time
        $startTime = \Carbon\Carbon::parse($waitlistEntry->start_time);
        $bookingDate = $startTime->format('Y-m-d');
        $startTimeOnly = $startTime->format('H:i:s');
        $endTimeOnly = \Carbon\Carbon::parse($waitlistEntry->end_time)->format('H:i:s');

        // Create WaitlistCartItem
        $waitlistCartItem = WaitlistCartItem::create([
            'user_id' => $waitlistEntry->user_id,
            'booking_for_user_id' => $waitlistEntry->booking_for_user_id,
            'booking_for_user_name' => $waitlistEntry->booking_for_user_name,
            'waitlist_cart_transaction_id' => $waitlistCartTransaction->id,
            'booking_waitlist_id' => $waitlistEntry->id,
            'court_id' => $waitlistEntry->court_id,
            'sport_id' => $waitlistEntry->sport_id,
            'booking_date' => $bookingDate,
            'start_time' => $startTimeOnly,
            'end_time' => $endTimeOnly,
            'price' => $waitlistEntry->price,
            'number_of_players' => $waitlistEntry->number_of_players,
            'status' => 'pending',
            'notes' => $waitlistEntry->notes,
            'admin_notes' => $waitlistEntry->admin_notes,
        ]);

        Log::info('Created waitlist cart records from direct booking', [
            'waitlist_entry_id' => $waitlistEntry->id,
            'waitlist_cart_item_id' => $waitlistCartItem->id,
            'waitlist_cart_transaction_id' => $waitlistCartTransaction->id,
        ]);

        return [
            'waitlistCartItem' => $waitlistCartItem,
            'waitlistCartTransaction' => $waitlistCartTransaction
        ];
    }

    /**
     * Convert waitlist cart records to actual bookings when original booking is rejected
     *
     * @param BookingWaitlist $waitlistEntry The waitlist entry being converted
     * @return Booking The created booking
     */
    public function convertWaitlistToBooking(BookingWaitlist $waitlistEntry): Booking
    {
        return DB::transaction(function () use ($waitlistEntry) {
            // Find waitlist cart items
            $waitlistCartItems = WaitlistCartItem::where('booking_waitlist_id', $waitlistEntry->id)
                ->where('status', '!=', 'cancelled')
                ->get();

            if ($waitlistCartItems->isEmpty()) {
                throw new \Exception("No waitlist cart items found for waitlist entry {$waitlistEntry->id}");
            }

            $waitlistCartItem = $waitlistCartItems->first();
            $waitlistCartTransaction = $waitlistCartItem->waitlistCartTransaction;

            if (!$waitlistCartTransaction) {
                throw new \Exception("No waitlist cart transaction found for waitlist cart item {$waitlistCartItem->id}");
            }

            // Create a new CartTransaction from waitlist data
            $newCartTransaction = CartTransaction::create([
                'user_id' => $waitlistCartTransaction->user_id,
                'booking_for_user_id' => $waitlistCartTransaction->booking_for_user_id,
                'booking_for_user_name' => $waitlistCartTransaction->booking_for_user_name,
                'booking_waitlist_id' => null, // Cleared since it's now converted
                'total_price' => $waitlistCartTransaction->total_price,
                'status' => 'pending',
                'approval_status' => 'pending',
                'payment_method' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            // Create CartItems from WaitlistCartItems
            foreach ($waitlistCartItems as $waitlistCartItem) {
                CartItem::create([
                    'user_id' => $waitlistCartItem->user_id,
                    'booking_for_user_id' => $waitlistCartItem->booking_for_user_id,
                    'booking_for_user_name' => $waitlistCartItem->booking_for_user_name,
                    'cart_transaction_id' => $newCartTransaction->id,
                    'booking_waitlist_id' => null, // Cleared
                    'court_id' => $waitlistCartItem->court_id,
                    'sport_id' => $waitlistCartItem->sport_id,
                    'booking_date' => $waitlistCartItem->booking_date,
                    'start_time' => $waitlistCartItem->start_time,
                    'end_time' => $waitlistCartItem->end_time,
                    'price' => $waitlistCartItem->price,
                    'number_of_players' => $waitlistCartItem->number_of_players,
                    'status' => 'pending',
                    'notes' => $waitlistCartItem->notes . "\n\nConverted from waitlist position #{$waitlistEntry->position}",
                    'admin_notes' => $waitlistCartItem->admin_notes,
                    'session_id' => $waitlistCartItem->session_id,
                ]);
            }

            // Create the booking
            $newBooking = Booking::create([
                'user_id' => $waitlistEntry->user_id,
                'cart_transaction_id' => $newCartTransaction->id,
                'booking_waitlist_id' => $waitlistEntry->id,
                'court_id' => $waitlistEntry->court_id,
                'sport_id' => $waitlistEntry->sport_id,
                'start_time' => $waitlistEntry->start_time,
                'end_time' => $waitlistEntry->end_time,
                'total_price' => $waitlistEntry->price,
                'number_of_players' => $waitlistEntry->number_of_players,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => 'pending',
                'notes' => 'Auto-created from waitlist position #' . $waitlistEntry->position,
                'booking_for_user_id' => $waitlistEntry->booking_for_user_id,
                'booking_for_user_name' => $waitlistEntry->booking_for_user_name,
                'admin_notes' => $waitlistEntry->admin_notes,
            ]);

            // Mark waitlist cart items as converted
            WaitlistCartItem::where('booking_waitlist_id', $waitlistEntry->id)
                ->update(['status' => 'converted']);

            // Mark waitlist cart transaction as converted
            $waitlistCartTransaction->update([
                'status' => 'converted',
                'approval_status' => 'converted'
            ]);

            // Update waitlist entry
            $waitlistEntry->update([
                'status' => BookingWaitlist::STATUS_CONVERTED,
                'converted_cart_transaction_id' => $newCartTransaction->id
            ]);

            Log::info('Converted waitlist to booking', [
                'waitlist_entry_id' => $waitlistEntry->id,
                'new_cart_transaction_id' => $newCartTransaction->id,
                'new_booking_id' => $newBooking->id,
            ]);

            return $newBooking;
        });
    }

    /**
     * Reject waitlist cart records when original booking is approved
     *
     * @param BookingWaitlist $waitlistEntry The waitlist entry being rejected
     * @return void
     */
    public function rejectWaitlistCartRecords(BookingWaitlist $waitlistEntry): void
    {
        DB::transaction(function () use ($waitlistEntry) {
            // Update waitlist cart items
            WaitlistCartItem::where('booking_waitlist_id', $waitlistEntry->id)
                ->where('status', '!=', 'cancelled')
                ->update([
                    'status' => 'rejected',
                    'admin_notes' => 'Rejected: Original booking was approved'
                ]);

            // Update waitlist cart transaction
            $waitlistCartTransactions = WaitlistCartTransaction::where('booking_waitlist_id', $waitlistEntry->id)
                ->where('approval_status', '!=', 'rejected')
                ->get();

            foreach ($waitlistCartTransactions as $transaction) {
                $transaction->update([
                    'approval_status' => 'rejected',
                    'status' => 'cancelled',
                    'rejection_reason' => 'Original booking was approved - waitlist cancelled'
                ]);
            }

            // Mark waitlist entry as cancelled
            $waitlistEntry->update([
                'status' => BookingWaitlist::STATUS_CANCELLED
            ]);

            Log::info('Rejected waitlist cart records', [
                'waitlist_entry_id' => $waitlistEntry->id,
            ]);
        });
    }
}
