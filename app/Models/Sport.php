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

    // Price history relationship
    public function priceHistory(): HasMany
    {
        return $this->hasMany(SportPriceHistory::class);
    }

    /**
     * Get the lowest price per hour from time-based pricing or default price
     *
     * @return float
     */
    public function getLowestPricePerHourAttribute(): float
    {
        $now = Carbon::now();

        // Get all active time-based pricing rules that are currently effective
        $activeTimeBasedPrices = $this->timeBasedPricing()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('effective_date')
                      ->orWhere('effective_date', '<=', $now);
            })
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
            // Check if the rule has become effective yet
            if ($rule->effective_date !== null) {
                // Ensure we have a Carbon instance
                $effectiveDate = $rule->effective_date instanceof Carbon
                    ? $rule->effective_date
                    : Carbon::parse($rule->effective_date);

                // Skip if booking date is before effective date
                if ($dateTime->timestamp < $effectiveDate->timestamp) {
                    continue; // Skip this rule if its effective date hasn't been reached
                }
            }

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
     * Handles pricing changes that occur within the booking period
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return float
     */
    public function calculatePriceForRange(Carbon $startTime, Carbon $endTime): float
    {
        $totalPrice = 0;
        $currentTime = $startTime->copy();

        // Get all effectivity dates that fall within this booking range
        $effectivityTransitions = $this->getEffectivityTransitions($startTime, $endTime);

        // If there are effectivity transitions, we need to split the calculation at those points
        if (!empty($effectivityTransitions)) {
            foreach ($effectivityTransitions as $transitionTime) {
                // Calculate price from current time to transition point
                if ($currentTime->lt($transitionTime)) {
                    $totalPrice += $this->calculatePriceForSegment($currentTime, $transitionTime);
                    $currentTime = $transitionTime->copy();
                }
            }
        }

        // Calculate remaining time (from last transition to end, or full range if no transitions)
        if ($currentTime->lt($endTime)) {
            $totalPrice += $this->calculatePriceForSegment($currentTime, $endTime);
        }

        return round($totalPrice, 2);
    }

    /**
     * Get all effectivity date transitions that occur within a time range
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return array Array of Carbon objects representing transition times
     */
    private function getEffectivityTransitions(Carbon $startTime, Carbon $endTime): array
    {
        $transitions = [];

        $pricingRules = $this->timeBasedPricing()
            ->where('is_active', true)
            ->whereNotNull('effective_date')
            ->get();

        foreach ($pricingRules as $rule) {
            $effectiveDate = Carbon::parse($rule->effective_date);

            // Check if this effective date falls within our booking range
            if ($effectiveDate->gt($startTime) && $effectiveDate->lt($endTime)) {
                $transitions[] = $effectiveDate;
            }
        }

        // Sort transitions chronologically
        usort($transitions, function($a, $b) {
            return $a->timestamp <=> $b->timestamp;
        });

        return $transitions;
    }

    /**
     * Calculate price for a continuous segment (no effectivity transitions)
     *
     * @param Carbon $startTime
     * @param Carbon $endTime
     * @return float
     */
    private function calculatePriceForSegment(Carbon $startTime, Carbon $endTime): float
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

        return $totalPrice;
    }
}
