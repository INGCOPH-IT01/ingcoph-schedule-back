<?php

namespace App\Helpers;

use Carbon\Carbon;
use App\Models\Holiday;

class BusinessHoursHelper
{
    /**
     * Business hours start time (8:00 AM)
     */
    const BUSINESS_START_HOUR = 8;
    const BUSINESS_START_MINUTE = 0;

    /**
     * Business hours end time (5:00 PM / 17:00)
     */
    const BUSINESS_END_HOUR = 17;
    const BUSINESS_END_MINUTE = 0;

    /**
     * Check if a given time is within business hours
     */
    public static function isWithinBusinessHours(Carbon $dateTime): bool
    {
        $hour = $dateTime->hour;
        $minute = $dateTime->minute;

        // Before business hours
        if ($hour < self::BUSINESS_START_HOUR) {
            return false;
        }

        // After business hours (after 5:00 PM)
        if ($hour >= self::BUSINESS_END_HOUR) {
            return false;
        }

        return true;
    }

    /**
     * Check if a given date is a working day (Monday-Saturday, excluding holidays)
     */
    public static function isWorkingDay(Carbon $date): bool
    {
        // Check if it's Sunday
        if ($date->isSunday()) {
            return false;
        }

        // Check if it's a holiday
        if (Holiday::isHoliday($date)) {
            return false;
        }

        return true;
    }

    /**
     * Get the next working day starting from the given date
     * If the given date is a working day, it returns that date
     */
    public static function getNextWorkingDay(Carbon $date): Carbon
    {
        $nextDay = $date->copy();

        while (!self::isWorkingDay($nextDay)) {
            $nextDay->addDay();
        }

        return $nextDay;
    }

    /**
     * Calculate the expiration time for a cart transaction based on business hours
     *
     * Rules:
     * - If created during business hours (8am-5pm on working days):
     *   Expires 1 hour from creation time
     * - If created after 5pm:
     *   Timer starts at 8am the next working day, expires at 9am next working day
     * - If created before 8am:
     *   Timer starts at 8am same day, expires at 9am same day
     * - If created on weekend/holiday:
     *   Timer starts at 8am next working day
     *
     * @param Carbon $createdAt The time the transaction was created
     * @return Carbon The expiration time
     */
    public static function calculateExpirationTime(Carbon $createdAt): Carbon
    {
        $now = $createdAt->copy();

        // If created on a non-working day (Sunday or holiday)
        if (!self::isWorkingDay($now)) {
            $nextWorkingDay = self::getNextWorkingDay($now->copy()->addDay());
            return $nextWorkingDay->setTime(self::BUSINESS_START_HOUR + 1, self::BUSINESS_START_MINUTE);
        }

        // If created after business hours (after 5pm)
        if ($now->hour >= self::BUSINESS_END_HOUR) {
            $nextWorkingDay = self::getNextWorkingDay($now->copy()->addDay());
            return $nextWorkingDay->setTime(self::BUSINESS_START_HOUR + 1, self::BUSINESS_START_MINUTE);
        }

        // If created before business hours (before 8am)
        if ($now->hour < self::BUSINESS_START_HOUR) {
            return $now->copy()->setTime(self::BUSINESS_START_HOUR + 1, self::BUSINESS_START_MINUTE);
        }

        // Created during business hours - simple 1 hour from now
        return $now->copy()->addHour();
    }

    /**
     * Check if a cart transaction has expired based on business hours
     *
     * @param Carbon $createdAt The time the transaction was created
     * @param Carbon|null $checkTime The time to check against (defaults to now)
     * @return bool True if expired, false otherwise
     */
    public static function isExpired(Carbon $createdAt, ?Carbon $checkTime = null): bool
    {
        $checkTime = $checkTime ?? Carbon::now();
        $expirationTime = self::calculateExpirationTime($createdAt);

        return $checkTime->greaterThanOrEqualTo($expirationTime);
    }

    /**
     * Get time remaining until expiration (in seconds)
     * Returns 0 if already expired
     *
     * @param Carbon $createdAt The time the transaction was created
     * @return int Seconds remaining until expiration
     */
    public static function getTimeRemainingSeconds(Carbon $createdAt): int
    {
        $now = Carbon::now();
        $expirationTime = self::calculateExpirationTime($createdAt);

        if ($now->greaterThanOrEqualTo($expirationTime)) {
            return 0;
        }

        return $now->diffInSeconds($expirationTime, false);
    }

    /**
     * Get human-readable time remaining
     *
     * @param Carbon $createdAt The time the transaction was created
     * @return string Human-readable time remaining (e.g., "45m 30s", "Pending next business day")
     */
    public static function getTimeRemainingFormatted(Carbon $createdAt): string
    {
        $now = Carbon::now();
        $expirationTime = self::calculateExpirationTime($createdAt);

        // If we're not yet in the business day when timer starts
        $timerStartTime = $expirationTime->copy()->subHour();

        if ($now->lessThan($timerStartTime)) {
            return 'Pending next business day (' . $timerStartTime->format('M d, g:i A') . ')';
        }

        // If expired
        if ($now->greaterThanOrEqualTo($expirationTime)) {
            return 'Expired';
        }

        // Active countdown
        $secondsRemaining = $now->diffInSeconds($expirationTime, false);
        $minutes = floor($secondsRemaining / 60);
        $seconds = $secondsRemaining % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }

    /**
     * Check if a cart transaction should be exempt from expiration
     *
     * A transaction is exempt from expiration if:
     * - Created by admin or staff user
     * - Has proof of payment uploaded
     * - Has been approved (approval_status === 'approved')
     *
     * This centralizes the expiration exemption logic to follow DRY principles.
     *
     * @param \App\Models\CartTransaction $transaction The cart transaction to check
     * @return bool True if exempt from expiration, false if it should expire
     */
    public static function isExemptFromExpiration($transaction): bool
    {
        // Admin/staff bookings never expire
        if ($transaction->user && $transaction->user->isAdmin()) {
            return true;
        }

        // Bookings with proof of payment never expire
        if ($transaction->proof_of_payment) {
            return true;
        }

        // Approved bookings never expire
        if ($transaction->approval_status === 'approved') {
            return true;
        }

        // All other cases: should be subject to expiration
        return false;
    }

    /**
     * Check if a cart transaction should expire based on comprehensive rules
     *
     * This combines exemption checking with time-based expiration logic.
     * Returns true only if the transaction:
     * - Is NOT exempt from expiration AND
     * - Has passed its expiration time
     *
     * @param \App\Models\CartTransaction $transaction The cart transaction to check
     * @param Carbon|null $checkTime The time to check against (defaults to now)
     * @return bool True if transaction should expire, false otherwise
     */
    public static function shouldExpire($transaction, ?Carbon $checkTime = null): bool
    {
        // First check if exempt from expiration
        if (self::isExemptFromExpiration($transaction)) {
            return false;
        }

        // If not exempt, check if time has expired
        $createdAt = Carbon::parse($transaction->created_at);
        return self::isExpired($createdAt, $checkTime);
    }
}
