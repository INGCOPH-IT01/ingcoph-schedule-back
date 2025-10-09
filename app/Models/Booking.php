<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $fillable = [
        'user_id',
        'cart_transaction_id',
        'court_id',
        'start_time',
        'end_time',
        'total_price',
        'status',
        'notes',
        'recurring_schedule',
        'recurring_schedule_data',
        'frequency_type',
        'frequency_days',
        'frequency_times',
        'frequency_duration_months',
        'frequency_end_date',
        'payment_method',
        'payment_status',
        'gcash_reference',
        'paid_at',
        'proof_of_payment',
        'qr_code',
        'checked_in_at'
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
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

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Get the cart items that were converted to this booking
     */
    public function cartTransaction(): BelongsTo
    {
        return $this->belongsTo(CartTransaction::class);
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
        return $this->status === self::STATUS_APPROVED && 
               !$this->checked_in_at &&
               now()->between($this->start_time, $this->end_time);
    }

    /**
     * Check in the booking
     */
    public function checkIn(): bool
    {
        if ($this->canCheckIn()) {
            $this->status = self::STATUS_CHECKED_IN;
            $this->checked_in_at = now();
            return $this->save();
        }
        return false;
    }

}
