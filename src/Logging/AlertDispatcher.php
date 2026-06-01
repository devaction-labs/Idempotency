<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Logging;

use DevactionLabs\Idempotency\Events\IdempotencyAlertFired;
use DevactionLabs\Idempotency\Support\ConfigAccess;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use JsonException;
use Psr\SimpleCache\InvalidArgumentException;

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
        $context = $this->redactContext($context);

        if (! $this->shouldSend($eventType, $context)) {
            return;
        }

        $this->events->dispatch(new IdempotencyAlertFired($eventType, $context));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function redactContext(array $context): array
    {
        if (! $this->configBool($this->config, 'idempotency.alerts.redact_context', true)) {
            return $context;
        }

        if (isset($context['idempotency_key']) && is_scalar($context['idempotency_key'])) {
            $context['idempotency_key_hash'] = hash('sha256', (string) $context['idempotency_key']);
            unset($context['idempotency_key']);
        }

        if (! $this->configBool($this->config, 'idempotency.alerts.include_exception_messages', false)) {
            unset($context['message']);
        }

        return $context;
    }

    /** @param array<string,mixed> $context
     * @throws InvalidArgumentException
     */
    private function shouldSend(EventType $eventType, array $context): bool
    {
        try {
            $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encoded = serialize($context);
        }

        $fingerprint = hash('sha256', $eventType->value.':'.$encoded);
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
