<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the cart expiration check to run every 5 minutes
Schedule::command('cart:cancel-expired')->everyFiveMinutes();

// Schedule the stale cart transaction cleanup to run daily at 2 AM
Schedule::command('cart:cleanup-stale-transactions --force')->dailyAt('02:00');
