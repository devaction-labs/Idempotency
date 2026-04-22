<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Telemetry;

use DevactionLabs\Idempotency\Contracts\TelemetryDriver;
use DevactionLabs\Idempotency\Telemetry\Drivers\InspectorTelemetryDriver;
use DevactionLabs\Idempotency\Telemetry\Drivers\NullTelemetryDriver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Inspector\Laravel\Facades\Inspector;
use InvalidArgumentException;
use RuntimeException;

final class TelemetryManager extends Manager
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        $enabled = $this->config->get('idempotency.telemetry.enabled', true);

        if (! is_bool($enabled) || ! $enabled) {
            return 'null';
        }

        $driver = $this->config->get('idempotency.telemetry.driver', 'null');

        return is_string($driver) ? $driver : 'null';
    }

    public function driver($driver = null): TelemetryDriver
    {
        /** @var TelemetryDriver $resolved */
        $resolved = parent::driver($driver);

        return $resolved;
    }

    protected function createNullDriver(): TelemetryDriver
    {
        return new NullTelemetryDriver;
    }

    protected function createInspectorDriver(): TelemetryDriver
    {
        if (! class_exists(Inspector::class)) {
            throw new RuntimeException(
                'Inspector telemetry driver requires inspector-apm/inspector-laravel. '.
                'Run `composer require inspector-apm/inspector-laravel` or switch the driver.'
            );
        }

        return new InspectorTelemetryDriver;
    }

    protected function createCustomDriver(): TelemetryDriver
    {
        $class = $this->config->get('idempotency.telemetry.custom_driver_class');

        if (! is_string($class) || ! class_exists($class)) {
            $label = is_string($class) ? $class : '(not a string)';
            throw new InvalidArgumentException("Custom telemetry driver class [{$label}] not found.");
        }

        $driver = $this->container->make($class);

        if (! $driver instanceof TelemetryDriver) {
            throw new InvalidArgumentException(
                'Custom telemetry driver must implement '.TelemetryDriver::class.'.'
            );
        }

        return $driver;
    }
}
