<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force English locale for dates and logs
        setlocale(LC_ALL, 'en_US.UTF-8', 'en_US', 'English_United States.1252');
        \Carbon\Carbon::setLocale('en');

        // Force HTTPS in production or when FORCE_HTTPS is true
        if (config('app.env') === 'production' || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}
