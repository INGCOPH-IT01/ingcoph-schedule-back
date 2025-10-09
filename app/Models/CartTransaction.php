<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'total_price',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'payment_method',
        'payment_status',
        'gcash_reference',
        'proof_of_payment',
        'paid_at',
        'qr_code'
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}