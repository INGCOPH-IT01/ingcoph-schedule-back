<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'cart_transaction_id',
        'court_id',
        'sport_id',
        'booking_date',
        'start_time',
        'end_time',
        'price',
        'number_of_players',
        'admin_notes',
        'status',
        'session_id'
    ];

    protected $casts = [
        'booking_date' => 'date',
        'price' => 'decimal:2',
        'number_of_players' => 'integer'
    ];

    /**
     * Get the user that owns the cart item
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user this cart item is booked for (if admin booking for someone else)
     */
    public function bookingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booking_for_user_id');
    }

    /**
     * Get the court for this cart item
     */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Get the sport for this cart item
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * Get the cart transaction that this item belongs to
     */
    public function cartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class);
    }

    /**
     * Get the bookings associated with this cart item through the cart transaction
     * This allows accessing all bookings created from the same cart transaction
     */
    public function bookings(): HasManyThrough
    {
        return $this->hasManyThrough(
            Booking::class,              // The final model we want to access
            CartTransaction::class,      // The intermediate model
            'id',                        // Foreign key on cart_transactions table (what cart_items.cart_transaction_id references)
            'cart_transaction_id',       // Foreign key on bookings table (what references cart_transactions.id)
            'cart_transaction_id',       // Local key on cart_items table
            'id'                         // Local key on cart_transactions table
        );
    }
}
