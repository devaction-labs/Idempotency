<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Infinitypaul\Idempotency\Logging\EventType;

final class IdempotencyAlertFired
{
    use Dispatchable;

    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly EventType $eventType,
        public readonly array $context = [],
        public readonly \DateTimeImmutable $firedAt = new \DateTimeImmutable,
    ) {}
}
