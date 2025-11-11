<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $fillable = [
        'user_id',
        'booking_for_user_id',
        'booking_for_user_name',
        'cart_transaction_id',
        'booking_waitlist_id',
        'court_id',
        'sport_id',
        'start_time',
        'end_time',
        'total_price',
        'number_of_players',
        'status',
        'notes',
        'admin_notes',
        'recurring_schedule',
        'recurring_schedule_data',
        'frequency_type',
        'frequency_days',
        'frequency_times',
        'frequency_duration_months',
        'frequency_end_date',
        'payment_method',
        'payment_reference_number',
        'payment_status',
        'paid_at',
        'proof_of_payment',
        'qr_code',
        'checked_in_at',
        'attendance_status',
        'attendance_scan_count',
        'players_attended'
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'number_of_players' => 'integer',
        'attendance_scan_count' => 'integer',
        'players_attended' => 'integer',
        'recurring_schedule_data' => 'array',
        'frequency_days' => 'array',
        'frequency_times' => 'array',
        'checked_in_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_RECURRING_SCHEDULE = 'recurring_schedule';

    // Get all possible statuses
    public static function getStatuses()
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_CHECKED_IN => 'Checked In',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_RECURRING_SCHEDULE => 'Recurring Schedule',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user this booking was made for (if admin booking for someone else)
     */
    public function bookingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booking_for_user_id');
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    /**
     * Get the cart items that were converted to this booking
     */
    public function cartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class);
    }

    /**
     * Get the waitlist entry that this booking was created from
     */
    public function bookingWaitlist(): BelongsTo
    {
        return $this->belongsTo(BookingWaitlist::class, 'booking_waitlist_id');
    }

    /**
     * Get waitlist entries for this booking
     */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(BookingWaitlist::class, 'pending_booking_id');
    }

    /**
     * Generate a unique QR code for this booking
     */
    public function generateQrCode(): string
    {
        if (!$this->qr_code) {
            $this->qr_code = 'BK' . $this->id . '_' . time() . '_' . bin2hex(random_bytes(8));
            $this->save();
        }
        return $this->qr_code;
    }

    /**
     * Check if booking can be checked in
     */
    public function canCheckIn(): bool
    {
        // Check if booking is approved and within time window
        $basicCheck = $this->status === self::STATUS_APPROVED &&
                     now()->between($this->start_time, $this->end_time);

        // If basic check passes, also verify scan count hasn't exceeded number of players
        if ($basicCheck) {
            return $this->attendance_scan_count < $this->number_of_players;
        }

        return false;
    }

    /**
     * Check in the booking
     */
    public function checkIn(): bool
    {
        if ($this->canCheckIn()) {
            // Increment the scan count
            $this->attendance_scan_count += 1;

            // If this is the first scan, update checked_in_at and status
            if ($this->attendance_scan_count === 1) {
                $this->status = self::STATUS_CHECKED_IN;
                $this->checked_in_at = now();
                $this->attendance_status = 'showed_up';
            }

            return $this->save();
        }
        return false;
    }

    /**
     * Check if all players have been scanned
     */
    public function hasAllPlayersScanned(): bool
    {
        return $this->attendance_scan_count >= $this->number_of_players;
    }

    /**
     * Get the display name for this booking
     * Returns booking_for_user_name if set, otherwise the user's name
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->booking_for_user_name) {
            return $this->booking_for_user_name;
        }
        return $this->user ? $this->user->name : 'Unknown';
    }

    /**
     * Get the effective user for this booking
     * Returns bookingForUser if set, otherwise returns the regular user
     */
    public function getEffectiveUserAttribute()
    {
        if ($this->booking_for_user_id && $this->bookingForUser) {
            return $this->bookingForUser;
        }
        return $this->user;
    }

    /**
     * Check if this booking was created by an admin
     * Returns true if the booking creator (user_id) is an admin
     */
    public function isAdminBooking(): bool
    {
        return $this->user && $this->user->isAdmin();
    }

    /**
     * Append custom attributes to model's array/JSON form
     */
    protected $appends = ['display_name', 'effective_user'];

}
