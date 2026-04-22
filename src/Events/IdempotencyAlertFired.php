<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Events;

use DevactionLabs\Idempotency\Logging\EventType;
use Illuminate\Foundation\Events\Dispatchable;

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
