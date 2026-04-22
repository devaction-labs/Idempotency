<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Telemetry\Drivers;

use Infinitypaul\Idempotency\Contracts\TelemetryDriver;

final class NullTelemetryDriver implements TelemetryDriver
{
    public function startSegment(string $type, ?string $label = null): mixed
    {
        return null;
    }

    public function addSegmentContext(mixed $segment, string $key, mixed $value): void {}

    public function endSegment(mixed $segment): void {}

    public function recordMetric(string $name, int|float $value = 1): void {}

    public function recordTiming(string $name, float $milliseconds): void {}

    public function recordSize(string $name, int $bytes): void {}
}
