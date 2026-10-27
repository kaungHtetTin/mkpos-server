<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->configurationIsCached()) {
            throw new \RuntimeException('Clear cached local configuration before running tests. Never run the test suite on the production server.');
        }

        $app->make(Kernel::class)->bootstrap();

        $connection = config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'mysql' || ($connection['database'] ?? null) !== 'mkpos_testing') {
            throw new \RuntimeException('Tests require the isolated mkpos_testing MySQL database. Clear cached configuration and follow the test setup instructions.');
        }

        return $app;
    }
}
