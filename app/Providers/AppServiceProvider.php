<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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

        // Register model observers for automatic data synchronization
        \App\Models\CartItem::observe(\App\Observers\CartItemObserver::class);
    }
}
