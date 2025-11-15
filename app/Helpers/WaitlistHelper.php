<?php

namespace App\Helpers;

use App\Models\CompanySetting;
use App\Models\BookingWaitlist;

class WaitlistHelper
{
    /**
     * Check if waitlist feature is enabled in company settings
     *
     * @return bool
     */
    public static function isWaitlistEnabled(): bool
    {
        $waitlistEnabled = CompanySetting::get('waitlist_enabled', '1');

        // Check if waitlist is disabled (0, '0', false)
        if ($waitlistEnabled === '0' || $waitlistEnabled === 0 || $waitlistEnabled === false) {
            return false;
        }

        return true;
    }

    /**
     * Get the booking waitlist ID to use based on waitlist_enabled setting
     * Returns null if waitlist is disabled, otherwise returns the waitlist entry ID
     *
     * @param BookingWaitlist|null $waitlistEntry
     * @return int|null
     */
    public static function getBookingWaitlistId(?BookingWaitlist $waitlistEntry): ?int
    {
        if (!$waitlistEntry) {
            return null;
        }

        if (!self::isWaitlistEnabled()) {
            return null;
        }

        return $waitlistEntry->id;
    }

    /**
     * Validate if waitlist operations can be performed
     * Throws an exception if waitlist is disabled
     *
     * @param string $customMessage Optional custom error message
     * @return void
     * @throws \Exception
     */
    public static function validateWaitlistEnabled(string $customMessage = null): void
    {
        if (!self::isWaitlistEnabled()) {
            $message = $customMessage ?? 'Waitlist feature is currently disabled.';
            throw new \Exception($message);
        }
    }

    /**
     * Get a standardized error response for when waitlist is disabled
     *
     * @param bool $includeConflict Whether to include conflict flag in response
     * @return array
     */
    public static function getDisabledResponse(bool $includeConflict = false): array
    {
        $response = [
            'success' => false,
            'message' => 'This time slot is currently pending approval and waitlist is disabled. Please try again later.',
        ];

        if ($includeConflict) {
            $response['conflict'] = true;
        }

        return $response;
    }
}
