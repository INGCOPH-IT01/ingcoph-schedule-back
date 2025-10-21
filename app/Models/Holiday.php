<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'name',
        'description',
        'is_recurring',
    ];

    protected $casts = [
        'date' => 'date',
        'is_recurring' => 'boolean',
    ];

    /**
     * Check if a given date is a holiday
     */
    public static function isHoliday(Carbon $date): bool
    {
        // Check for exact date match
        $exactMatch = self::whereDate('date', $date->format('Y-m-d'))->exists();

        if ($exactMatch) {
            return true;
        }

        // Check for recurring holidays (same month and day, different year)
        $recurringMatch = self::where('is_recurring', true)
            ->whereMonth('date', $date->month)
            ->whereDay('date', $date->day)
            ->exists();

        return $recurringMatch;
    }

    /**
     * Get all holidays for a specific year
     */
    public static function getHolidaysForYear(int $year): array
    {
        $holidays = [];

        // Get specific holidays for this year
        $specificHolidays = self::whereYear('date', $year)->get();
        foreach ($specificHolidays as $holiday) {
            $holidays[] = $holiday->date->format('Y-m-d');
        }

        // Get recurring holidays and apply them to this year
        $recurringHolidays = self::where('is_recurring', true)->get();
        foreach ($recurringHolidays as $holiday) {
            $date = Carbon::parse($holiday->date);
            $yearlyDate = Carbon::create($year, $date->month, $date->day);
            $holidays[] = $yearlyDate->format('Y-m-d');
        }

        return array_unique($holidays);
    }
}
