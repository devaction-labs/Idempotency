<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Telemetry\Drivers;

use DevactionLabs\Idempotency\Contracts\TelemetryDriver;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;

/**
 * OpenTelemetry telemetry driver.
 *
 * Requires open-telemetry/api (^1.0). Install any compatible SDK
 * (open-telemetry/sdk, open-telemetry/opentelemetry-auto-*) to export data.
 *
 * Set IDEMPOTENCY_TELEMETRY_DRIVER=otel in your environment.
 */
final class OtelTelemetryDriver implements TelemetryDriver
{
    private const INSTRUMENTATION = 'devaction-labs/idempotency';

    public function startSegment(string $type, ?string $label = null): SpanInterface
    {
        $spanName = $label ?? $type;

        return Globals::tracerProvider()
            ->getTracer(self::INSTRUMENTATION)
            ->spanBuilder('' !== $spanName ? $spanName : self::INSTRUMENTATION)
            ->setAttribute('idempotency.segment_type', $type)
            ->startSpan();
    }

    public function addSegmentContext(mixed $segment, string $key, mixed $value): void
    {
        if (! $segment instanceof SpanInterface) {
            return;
        }

        if (! is_scalar($value) && ! is_array($value) && $value !== null) {
            return;
        }

        $segment->setAttribute('idempotency.'.$key, $value);
    }

    public function endSegment(mixed $segment): void
    {
        if ($segment instanceof SpanInterface) {
            $segment->end();
        }
    }

    public function recordMetric(string $name, int|float $value = 1): void
    {
        Globals::meterProvider()
            ->getMeter(self::INSTRUMENTATION)
            ->createCounter($name)
            ->add((int) $value);
    }

    public function recordTiming(string $name, float $milliseconds): void
    {
        Globals::meterProvider()
            ->getMeter(self::INSTRUMENTATION)
            ->createHistogram($name, 'ms')
            ->record($milliseconds);
    }

    public function recordSize(string $name, int $bytes): void
    {
        Globals::meterProvider()
            ->getMeter(self::INSTRUMENTATION)
            ->createHistogram($name, 'By')
            ->record($bytes);
    }
}
