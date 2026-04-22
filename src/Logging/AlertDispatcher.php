<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Logging;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Infinitypaul\Idempotency\Events\IdempotencyAlertFired;
use Infinitypaul\Idempotency\Support\ConfigAccess;

final class AlertDispatcher
{
    use ConfigAccess;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {}

    /** @param array<string,mixed> $context */
    public function dispatch(EventType $eventType, array $context = []): void
    {
        if (! $this->shouldSend($eventType, $context)) {
            return;
        }

        $this->events->dispatch(new IdempotencyAlertFired($eventType, $context));
    }

    /** @param array<string,mixed> $context */
    private function shouldSend(EventType $eventType, array $context): bool
    {
        $fingerprint = hash('sha256', $eventType->value.':'.json_encode($context, JSON_THROW_ON_ERROR));
        $cacheKey = "idempotency:alert_sent:{$fingerprint}";
        $storeName = $this->configStr($this->config, 'idempotency.cache_store', '');
        $store = $this->cache->store($storeName === '' ? null : $storeName);

        if ($store->has($cacheKey)) {
            return false;
        }

        $cooldown = $this->configInt($this->config, 'idempotency.alerts.cooldown', 3_600);
        $store->put($cacheKey, true, $cooldown);

        return true;
    }
}
