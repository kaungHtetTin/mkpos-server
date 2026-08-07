<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantMySqlConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(TenantContext::class);
        Connection::resolverFor('mysql', fn ($connection, $database, $prefix, $config) => new TenantMySqlConnection(
            $connection,
            $database,
            $prefix,
            $config
        ));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
