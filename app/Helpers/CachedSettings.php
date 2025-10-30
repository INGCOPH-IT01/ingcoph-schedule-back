<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use App\Models\CompanySetting;

/**
 * Helper class for cached company settings
 *
 * This class provides a caching layer for company settings to reduce database queries.
 * Settings are cached for 1 hour by default and can be cleared when updated.
 *
 * Usage:
 *   $enabled = CachedSettings::get('user_booking_enabled', '1');
 *   CachedSettings::flush('user_booking_enabled'); // Clear specific setting
 *   CachedSettings::flushAll(); // Clear all cached settings
 */
class CachedSettings
{
    /**
     * Cache duration in seconds (default: 1 hour)
     */
    const CACHE_TTL = 3600;

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'company_setting:';

    /**
     * Get a cached company setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value if setting not found
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            return CompanySetting::get($key, $default);
        });
    }

    /**
     * Get multiple cached settings at once
     *
     * @param array $keys Array of setting keys
     * @param mixed $default Default value for missing settings
     * @return array Associative array of key => value
     */
    public static function getMany(array $keys, $default = null): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = self::get($key, $default);
        }
        return $results;
    }

    /**
     * Get operating hours for a specific day with caching
     *
     * @param string $dayOfWeek Day name (lowercase: monday, tuesday, etc.)
     * @return array ['open' => string, 'close' => string, 'operational' => bool]
     */
    public static function getOperatingHours(string $dayOfWeek): array
    {
        $cacheKey = self::CACHE_PREFIX . "operating_hours:{$dayOfWeek}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dayOfWeek) {
            return [
                'open' => CompanySetting::get("operating_hours_{$dayOfWeek}_open", '08:00'),
                'close' => CompanySetting::get("operating_hours_{$dayOfWeek}_close", '22:00'),
                'operational' => CompanySetting::get("operating_hours_{$dayOfWeek}_operational", '1') === '1'
            ];
        });
    }

    /**
     * Set a setting and update the cache
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool
     */
    public static function set(string $key, $value): bool
    {
        $result = CompanySetting::set($key, $value);

        if ($result) {
            self::flush($key);
        }

        return $result;
    }

    /**
     * Clear cache for a specific setting
     *
     * @param string $key Setting key to clear
     * @return bool
     */
    public static function flush(string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        return Cache::forget($cacheKey);
    }

    /**
     * Clear all cached settings
     *
     * @return bool
     */
    public static function flushAll(): bool
    {
        // If using Redis or another tag-supporting cache driver
        if (method_exists(Cache::getStore(), 'tags')) {
            return Cache::tags(['company_settings'])->flush();
        }

        // Fallback: Clear all cache (use with caution in production)
        // In production, you might want to track keys separately
        return Cache::flush();
    }

    /**
     * Check if user booking is enabled
     * Convenience method for the most commonly checked setting
     *
     * @return bool
     */
    public static function isUserBookingEnabled(): bool
    {
        return self::get('user_booking_enabled', '1') === '1';
    }

    /**
     * Get payment settings with caching
     *
     * @return array
     */
    public static function getPaymentSettings(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'payment_settings';

        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return [
                'gcash_enabled' => CompanySetting::get('payment_gcash_enabled', '1') === '1',
                'cash_enabled' => CompanySetting::get('payment_cash_enabled', '1') === '1',
                'payment_qr_code' => CompanySetting::get('payment_qr_code'),
            ];
        });
    }

    /**
     * Warm up the cache with commonly used settings
     * Call this in a scheduled task or after settings updates
     *
     * @return void
     */
    public static function warmCache(): void
    {
        // Pre-load commonly accessed settings
        $commonSettings = [
            'user_booking_enabled',
            'company_name',
            'company_logo',
            'payment_gcash_enabled',
            'payment_cash_enabled',
        ];

        foreach ($commonSettings as $key) {
            self::get($key);
        }

        // Pre-load operating hours for all days
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($days as $day) {
            self::getOperatingHours($day);
        }
    }
}
