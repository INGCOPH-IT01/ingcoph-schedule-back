<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class WaitlistCartItem extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'waitlist_cart_transaction_id',
        'booking_waitlist_id',
        'court_id',
        'sport_id',
        'booking_date',
        'start_time',
        'end_time',
        'price',
        'number_of_players',
        'notes',
        'admin_notes',
        'status',
        'session_id'
    ];

    protected $casts = [
        'booking_date' => 'date:Y-m-d',
        'price' => 'decimal:2',
        'number_of_players' => 'integer'
    ];

    /**
     * Get the user that owns the waitlist cart item
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user this waitlist cart item is booked for (if admin booking for someone else)
     */
    public function bookingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booking_for_user_id');
    }

    /**
     * Get the court for this waitlist cart item
     */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Get the sport for this waitlist cart item
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * Get the waitlist cart transaction that this item belongs to
     */
    public function waitlistCartTransaction(): BelongsTo
    {
        return $this->belongsTo(WaitlistCartTransaction::class);
    }

    /**
     * Get the waitlist entry associated with this cart item
     */
    public function bookingWaitlist(): BelongsTo
    {
        return $this->belongsTo(BookingWaitlist::class);
    }

    /**
     * Get the bookings associated with this waitlist cart item through the waitlist cart transaction
     * This allows accessing all bookings created from the same waitlist cart transaction
     */
    public function bookings(): HasManyThrough
    {
        return $this->hasManyThrough(
            Booking::class,                    // The final model we want to access
            WaitlistCartTransaction::class,    // The intermediate model
            'id',                              // Foreign key on waitlist_cart_transactions table (what waitlist_cart_items.waitlist_cart_transaction_id references)
            'cart_transaction_id',             // Foreign key on bookings table (what references cart_transactions.id)
            'waitlist_cart_transaction_id',    // Local key on waitlist_cart_items table
            'id'                               // Local key on waitlist_cart_transactions table
        );
    }
}
