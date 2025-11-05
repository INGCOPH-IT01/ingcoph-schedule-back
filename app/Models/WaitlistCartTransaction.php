<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaitlistCartTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'booking_waitlist_id',
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

    /**
     * Get the user that owns the waitlist cart transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user this waitlist cart transaction is booked for (if admin booking for someone else)
     */
    public function bookingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booking_for_user_id');
    }

    /**
     * Get all waitlist cart items in this transaction
     */
    public function waitlistCartItems(): HasMany
    {
        return $this->hasMany(WaitlistCartItem::class);
    }

    /**
     * Get the bookings created from this waitlist cart transaction
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'cart_transaction_id');
    }

    /**
     * Get the user who approved this waitlist cart transaction
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the waitlist entry that this transaction was created from
     */
    public function bookingWaitlist(): BelongsTo
    {
        return $this->belongsTo(BookingWaitlist::class, 'booking_waitlist_id');
    }

    /**
     * Get all waitlist entries associated with this transaction
     */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(BookingWaitlist::class, 'converted_cart_transaction_id');
    }

    /**
     * Sync bookings status with waitlist cart transaction approval status
     * This ensures that when a waitlist cart transaction is approved/rejected,
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
