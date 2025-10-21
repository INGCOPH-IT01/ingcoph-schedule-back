<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'total_price',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'payment_method',
        'payment_status',
        'proof_of_payment',
        'paid_at',
        'qr_code',
        'attendance_status'
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

    public function bookingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booking_for_user_id');
    }

    /**
     * Sync bookings status with cart transaction approval status
     * This ensures that when a cart transaction is approved/rejected,
     * all associated bookings reflect the same status
     *
     * @param string $status The status to set ('approved', 'rejected', 'pending')
     * @return void
     */
    public function syncBookingsStatus(string $status): void
    {
        $this->bookings()->update(['status' => $status]);
    }
}