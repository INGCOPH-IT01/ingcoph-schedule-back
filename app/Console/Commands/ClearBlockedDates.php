<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompanySetting;
use App\Helpers\CachedSettings;
use Illuminate\Support\Facades\Cache;

class ClearBlockedDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blocked-dates:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all blocked booking dates from the system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Clearing blocked booking dates...');

        // Get current value before clearing
        $currentValue = CompanySetting::get('blocked_booking_dates');
        if ($currentValue) {
            $currentDates = json_decode($currentValue, true);
            $this->info('Current blocked dates: ' . json_encode($currentDates, JSON_PRETTY_PRINT));
        } else {
            $this->info('No blocked dates currently set.');
        }

        // Clear from database
        CompanySetting::set('blocked_booking_dates', json_encode([]));
        $this->info('✅ Cleared blocked dates from database');

        // Clear all caches
        CachedSettings::flush('blocked_booking_dates');
        Cache::forget('company_setting:blocked_booking_dates');

        try {
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['company_settings'])->flush();
            }
        } catch (\Exception $e) {
            // Cache tags not supported
        }

        $this->info('✅ Cleared all caches');

        // Verify it's cleared
        $newValue = CompanySetting::get('blocked_booking_dates');
        $this->info('New value: ' . $newValue);

        $this->info('');
        $this->info('✨ All blocked booking dates have been cleared!');
        $this->info('Users can now book any date (subject to other restrictions).');

        return 0;
    }
}


