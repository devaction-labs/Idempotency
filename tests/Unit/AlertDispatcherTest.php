<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Events\IdempotencyAlertFired;
use DevactionLabs\Idempotency\Logging\AlertDispatcher;
use DevactionLabs\Idempotency\Logging\EventType;
use Illuminate\Support\Facades\Event;

it('redacts raw idempotency keys and exception messages by default', function () {
    Event::fake([IdempotencyAlertFired::class]);

    app(AlertDispatcher::class)->dispatch(EventType::EXCEPTION_THROWN, [
        'idempotency_key' => 'raw-key',
        'message' => 'database secret',
        'exception' => RuntimeException::class,
    ]);

    Event::assertDispatched(IdempotencyAlertFired::class, function (IdempotencyAlertFired $event): bool {
        return $event->context['idempotency_key_hash'] === hash('sha256', 'raw-key')
            && ! array_key_exists('idempotency_key', $event->context)
            && ! array_key_exists('message', $event->context)
            && $event->context['exception'] === RuntimeException::class;
    });
});

it('can emit unredacted alert context when explicitly configured', function () {
    config([
        'idempotency.alerts.redact_context' => false,
        'idempotency.alerts.include_exception_messages' => true,
    ]);
    Event::fake([IdempotencyAlertFired::class]);

    app(AlertDispatcher::class)->dispatch(EventType::EXCEPTION_THROWN, [
        'idempotency_key' => 'raw-key',
        'message' => 'visible message',
    ]);

    Event::assertDispatched(IdempotencyAlertFired::class, function (IdempotencyAlertFired $event): bool {
        return $event->context['idempotency_key'] === 'raw-key'
            && $event->context['message'] === 'visible message';
    });
});
