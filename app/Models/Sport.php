<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;

class Sport extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image',
        'icon',
        'price_per_hour',
        'is_active'
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'lowest_price_per_hour'
    ];

    // Legacy one-to-many relationship (kept for backward compatibility)
    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    // New many-to-many relationship for multiple courts
    public function courtsMany(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'court_sport');
    }

    // Time-based pricing relationship
    public function timeBasedPricing(): HasMany
    {
        return $this->hasMany(SportTimeBasedPricing::class);
    }

    /**
     * Get the lowest price per hour from time-based pricing or default price
     *
     * @return float
     */
    public function getLowestPricePerHourAttribute(): float
    {
        // Get all active time-based pricing rules
        $activeTimeBasedPrices = $this->timeBasedPricing()
            ->where('is_active', true)
            ->pluck('price_per_hour')
            ->toArray();

        // If there are active time-based prices, include them with the base price
        if (!empty($activeTimeBasedPrices)) {
            $allPrices = array_merge([$this->price_per_hour], $activeTimeBasedPrices);
            return (float) min($allPrices);
        }

        // If no time-based pricing, return the base price
        return (float) $this->price_per_hour;
    }

    /**
     * Get the price per hour for a specific date and time
     *
     * @param Carbon $dateTime
     * @return float
     */
    public function getPriceForDateTime(Carbon $dateTime): float
    {
        // Get all active time-based pricing rules for this sport, ordered by priority (highest first)
        $pricingRules = $this->timeBasedPricing()
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        // If no pricing rules exist, return the default price
        if ($pricingRules->isEmpty()) {
            return (float) $this->price_per_hour;
        }

        $dayOfWeek = $dateTime->dayOfWeek; // 0 = Sunday, 1 = Monday, etc.
        $time = $dateTime->format('H:i:s');

        // Find the first matching rule with highest priority
        foreach ($pricingRules as $rule) {
            // Check if the rule applies to this day of week
            $daysOfWeek = $rule->days_of_week;

            // If days_of_week is null, rule applies to all days
            if ($daysOfWeek !== null && !in_array($dayOfWeek, $daysOfWeek)) {
                continue;
            }

            // Check if the time falls within the rule's time range
            if ($time >= $rule->start_time && $time < $rule->end_time) {
                return (float) $rule->price_per_hour;
            }
        }

        // If no matching rule found, return the default price
        return (float) $this->price_per_hour;
    }

    /**
     * Calculate total price for a time range
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return float
     */
    public function calculatePriceForRange(Carbon $startTime, Carbon $endTime): float
    {
        $totalPrice = 0;
        $currentTime = $startTime->copy();

        // Calculate price for each hour segment
        while ($currentTime->lt($endTime)) {
            $nextHour = $currentTime->copy()->addHour();

            // If next hour exceeds end time, calculate partial hour
            if ($nextHour->gt($endTime)) {
                $fraction = $currentTime->diffInMinutes($endTime) / 60;
                $totalPrice += $this->getPriceForDateTime($currentTime) * $fraction;
                break;
            }

            // Add full hour price
            $totalPrice += $this->getPriceForDateTime($currentTime);
            $currentTime = $nextHour;
        }

        return round($totalPrice, 2);
    }
}
