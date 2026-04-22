<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Telemetry\Drivers;

use Infinitypaul\Idempotency\Contracts\TelemetryDriver;
use Inspector\Laravel\Facades\Inspector;
use Inspector\Models\Segment;

final class InspectorTelemetryDriver implements TelemetryDriver
{
    public function startSegment(string $type, ?string $label = null): ?Segment
    {
        if (! Inspector::isRecording()) {
            return null;
        }

        return Inspector::startSegment($type, $label ?? $type);
    }

    public function addSegmentContext(mixed $segment, string $key, mixed $value): void
    {
        if ($segment instanceof Segment) {
            $segment->addContext($key, $value);
        }
    }

    public function endSegment(mixed $segment): void
    {
        if ($segment instanceof Segment) {
            $segment->end();
        }
    }

    public function recordMetric(string $name, int|float $value = 1): void
    {
        if (! Inspector::isRecording()) {
            return;
        }

        Inspector::startSegment('metric', $name)
            ->addContext('value', $value)
            ->end();
    }

    public function recordTiming(string $name, float $milliseconds): void
    {
        if (! Inspector::isRecording()) {
            return;
        }

        Inspector::startSegment('timing', $name)
            ->addContext('value_ms', $milliseconds)
            ->end();
    }

    public function recordSize(string $name, int $bytes): void
    {
        if (! Inspector::isRecording()) {
            return;
        }

        Inspector::startSegment('size', $name)
            ->addContext('bytes', $bytes)
            ->end();
    }
}
