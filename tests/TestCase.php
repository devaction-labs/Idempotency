<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Tests;

use DevactionLabs\Idempotency\IdempotencyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [IdempotencyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('idempotency.enabled', true);
        $app['config']->set('idempotency.telemetry.enabled', false);
        $app['config']->set('idempotency.scope', 'global');
    }
}
