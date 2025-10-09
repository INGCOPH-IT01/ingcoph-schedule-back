<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class RecurringSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'court_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'duration_hours',
        'recurrence_type',
        'recurrence_days',
        'day_specific_times',
        'recurrence_interval',
        'start_date',
        'end_date',
        'max_occurrences',
        'is_active',
        'auto_approve',
        'notes'
    ];

    protected $casts = [
        'recurrence_days' => 'array',
        'day_specific_times' => 'array',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'auto_approve' => 'boolean',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    // Helper methods
    public function getDurationHoursAttribute()
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);
        return $end->diffInHours($start);
    }

    public function isActiveOnDate(Carbon $date): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($date->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }

        return $this->matchesRecurrencePattern($date);
    }

    public function matchesRecurrencePattern(Carbon $date): bool
    {
        switch ($this->recurrence_type) {
            case 'daily':
                return true;
            
            case 'weekly':
                $dayOfWeek = $date->dayOfWeek;
                return in_array($dayOfWeek, $this->recurrence_days);
            
            case 'monthly':
                // Check if it's the same day of the month
                return $date->day === Carbon::parse($this->start_date)->day;
            
            case 'yearly':
                // Check if it's the same month and day
                $startDate = Carbon::parse($this->start_date);
                return $date->month === $startDate->month && $date->day === $startDate->day;
            
            case 'yearly_multiple_times':
                // Check if the day has specific times defined (for yearly with day-specific times)
                $dayOfWeek = $date->dayOfWeek;
                return $this->hasDaySpecificTimes($dayOfWeek);
            
            case 'weekly_multiple_times':
                // Check if the day has specific times defined
                $dayOfWeek = $date->dayOfWeek;
                return $this->hasDaySpecificTimes($dayOfWeek);
            
            default:
                return false;
        }
    }

    public function hasDaySpecificTimes(int $dayOfWeek): bool
    {
        if (!$this->day_specific_times) {
            return false;
        }

        foreach ($this->day_specific_times as $dayTime) {
            if ($dayTime['day'] === $dayOfWeek) {
                return true;
            }
        }

        return false;
    }

    public function getDaySpecificTimes(int $dayOfWeek): ?array
    {
        if (!$this->day_specific_times) {
            return null;
        }

        foreach ($this->day_specific_times as $dayTime) {
            if ($dayTime['day'] === $dayOfWeek) {
                return $dayTime;
            }
        }

        return null;
    }

    public function generateBookingsForDateRange(Carbon $startDate, Carbon $endDate): array
    {
        $bookings = [];
        $current = $startDate->copy();

        while ($current->lte($endDate)) {
            if ($this->isActiveOnDate($current)) {
                // Handle day-specific times
                if (in_array($this->recurrence_type, ['weekly_multiple_times', 'yearly_multiple_times']) && $this->day_specific_times) {
                    $dayOfWeek = $current->dayOfWeek;
                    $dayTimes = $this->getDaySpecificTimes($dayOfWeek);
                    
                    if ($dayTimes) {
                        $bookingDateTime = $current->copy()->setTimeFromTimeString($dayTimes['start_time']);
                        $endDateTime = $current->copy()->setTimeFromTimeString($dayTimes['end_time']);

                        $bookings[] = [
                            'user_id' => $this->user_id,
                            'court_id' => $this->court_id,
                            'start_time' => $bookingDateTime,
                            'end_time' => $endDateTime,
                            'total_price' => $this->calculateTotalPrice($dayTimes['start_time'], $dayTimes['end_time']),
                            'status' => $this->auto_approve ? 'approved' : 'pending',
                            'notes' => $this->notes . " (Generated from recurring schedule: {$this->title})",
                            'recurring_schedule_id' => $this->id,
                        ];
                    }
                } else {
                    // Use default times
                    $bookingDateTime = $current->copy()->setTimeFromTimeString($this->start_time);
                    $endDateTime = $current->copy()->setTimeFromTimeString($this->end_time);

                    $bookings[] = [
                        'user_id' => $this->user_id,
                        'court_id' => $this->court_id,
                        'start_time' => $bookingDateTime,
                        'end_time' => $endDateTime,
                        'total_price' => $this->calculateTotalPrice($this->start_time, $this->end_time),
                        'status' => $this->auto_approve ? 'approved' : 'pending',
                        'notes' => $this->notes . " (Generated from recurring schedule: {$this->title})",
                        'recurring_schedule_id' => $this->id,
                    ];
                }
            }

            $current->addDay();
        }

        return $bookings;
    }

    private function calculateTotalPrice(string $startTime, string $endTime): float
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $duration = $end->diffInHours($start);
        
        // Get court price per hour
        $court = $this->court;
        $pricePerHour = $court ? $court->price_per_hour : 0;
        
        return $duration * $pricePerHour;
    }
}
