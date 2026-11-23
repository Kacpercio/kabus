<?php

namespace App\Providers;

use App\Services\Monero\MoneroWallet;
use App\Services\Monero\RpcMoneroWallet;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Carbon\Carbon;
use URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MoneroWallet::class, function () {
            $config = config('monero');

            return new RpcMoneroWallet(
                (string) $config['host'],
                (int) $config['port'],
                (bool) $config['ssl'],
                $config['user'] ?? null,
                $config['password'] ?? null,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Paginator::defaultView('components.pagination');
        Paginator::defaultSimpleView('components.pagination');

        // Set Carbon locale to English
        Carbon::setLocale('en');
    }
}
