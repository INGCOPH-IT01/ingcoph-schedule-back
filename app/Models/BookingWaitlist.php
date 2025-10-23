<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;
use App\Helpers\BusinessHoursHelper;

class BookingWaitlist extends Model
{
    protected $fillable = [
        'user_id',
        'pending_booking_id',
        'pending_cart_transaction_id',
        'court_id',
        'sport_id',
        'start_time',
        'end_time',
        'price',
        'number_of_players',
        'position',
        'status',
        'notified_at',
        'expires_at',
        'converted_cart_transaction_id',
        'notes'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'notified_at' => 'datetime',
        'expires_at' => 'datetime',
        'price' => 'decimal:2',
        'number_of_players' => 'integer',
        'position' => 'integer'
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_NOTIFIED = 'notified';
    const STATUS_CONVERTED = 'converted';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get the user on the waitlist
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the pending booking that this waitlist entry is for
     */
    public function pendingBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'pending_booking_id');
    }

    /**
     * Get the pending cart transaction that this waitlist entry is for
     */
    public function pendingCartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class, 'pending_cart_transaction_id');
    }

    /**
     * Get the court for this waitlist entry
     */
    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Get the sport for this waitlist entry
     */
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * Get the cart transaction created when converted from waitlist
     */
    public function convertedCartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class, 'converted_cart_transaction_id');
    }

    /**
     * Send notification email to user and start expiration timer
     * Uses business hours logic to calculate expiration time:
     * - During business hours (8am-5pm): expires 1 hour from now
     * - After 5pm or before 8am: expires at 9am next working day
     * - On weekends/holidays: expires at 9am next working day
     *
     * @param int $expirationHours Legacy parameter, ignored when using business hours
     */
    public function sendNotification(int $expirationHours = 1): void
    {
        $now = Carbon::now();

        // Use BusinessHoursHelper to calculate expiration time
        // This ensures waitlist users get proper deadline based on business hours
        $expiresAt = BusinessHoursHelper::calculateExpirationTime($now);

        $this->update([
            'status' => self::STATUS_NOTIFIED,
            'notified_at' => $now,
            'expires_at' => $expiresAt
        ]);
    }

    /**
     * Check if waitlist entry has expired
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        // Only check expiration if notified_at is set (timer started)
        if ($this->notified_at && $this->expires_at) {
            return Carbon::now()->greaterThan($this->expires_at);
        }

        return false;
    }

    /**
     * Convert waitlist to actual booking/cart transaction
     */
    public function convert(CartTransaction $cartTransaction): void
    {
        $this->update([
            'status' => self::STATUS_CONVERTED,
            'converted_cart_transaction_id' => $cartTransaction->id
        ]);
    }

    /**
     * Cancel the waitlist entry
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->save();
    }

    /**
     * Mark as expired
     */
    public function markAsExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Get all pending waitlist entries for a specific time slot
     */
    public static function getPendingForTimeSlot($courtId, $startTime, $endTime)
    {
        return static::where('court_id', $courtId)
            ->where('start_time', $startTime)
            ->where('end_time', $endTime)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }
}
