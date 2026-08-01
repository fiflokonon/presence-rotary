<?php

namespace App\Providers;

use App\Services\TenantContext;
use App\Session\TenantSafeDatabaseSessionHandler;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Session::extend('database', function ($app) {
            $table = $app['config']->get('session.table');
            $lifetime = $app['config']->get('session.lifetime');
            $connection = $app['db']->connection($app['config']->get('session.connection'));

            return new TenantSafeDatabaseSessionHandler($connection, $table, $lifetime, $app);
        });
    }
}
