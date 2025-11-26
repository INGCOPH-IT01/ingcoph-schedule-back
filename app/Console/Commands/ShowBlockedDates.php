<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CompanySetting;

class ShowBlockedDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blocked-dates:show';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show current blocked booking dates';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('📅 Blocked Booking Dates Status');
        $this->info('================================');
        $this->info('');

        $blockedDatesJson = CompanySetting::get('blocked_booking_dates');

        if (!$blockedDatesJson) {
            $this->info('✅ No blocked dates configured.');
            $this->info('All dates are available for booking (subject to other restrictions).');
            return 0;
        }

        $blockedDates = json_decode($blockedDatesJson, true);

        if (!is_array($blockedDates) || count($blockedDates) === 0) {
            $this->info('✅ No blocked dates configured.');
            $this->info('All dates are available for booking (subject to other restrictions).');
            return 0;
        }

        $this->info('❌ Currently blocked date ranges: ' . count($blockedDates));
        $this->info('');

        foreach ($blockedDates as $index => $range) {
            $this->info('Block #' . ($index + 1));
            $this->info('  Start Date: ' . ($range['start_date'] ?? 'N/A'));

            if (empty($range['end_date'])) {
                $this->warn('  End Date: INDEFINITE (blocks from start date onwards)');
            } else {
                $this->info('  End Date: ' . $range['end_date']);
            }

            if (!empty($range['reason'])) {
                $this->info('  Reason: ' . $range['reason']);
            }
            $this->info('');
        }

        $this->info('================================');
        $this->info('To clear all blocked dates, run:');
        $this->comment('  php artisan blocked-dates:clear');

        return 0;
    }
}


