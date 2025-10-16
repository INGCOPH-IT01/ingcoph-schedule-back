<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'cart_transaction_id',
        'court_id',
        'booking_date',
        'start_time',
        'end_time',
        'price',
        'admin_notes',
        'status',
        'session_id'
    ];

    protected $casts = [
        'booking_date' => 'date',
        'price' => 'decimal:2'
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
     * Get the cart transaction that this item belongs to
     */
    public function cartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class);
    }
}
