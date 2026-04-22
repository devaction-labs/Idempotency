<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;

final class IdempotencyManager
{
    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly Config $config,
    ) {}

    public function has(string $key, ?string $scope = null): bool
    {
        return $this->store()->has($this->responseKey($key, $scope));
    }

    /**
     * Flush every cache entry associated with an idempotency key.
     * Pass null to flush everything (not supported on all stores — falls back to per-key).
     */
    public function flush(?string $key = null, ?string $scope = null): void
    {
        if ($key === null) {
            return;
        }

        $prefix = $this->prefix($key, $scope);
        $store = $this->store();
        foreach (['response', 'processing', 'metadata', 'payload_hash'] as $segment) {
            $store->forget($prefix.':'.$segment);
        }
        $lockKey = 'idempotency_lock:'.ltrim(substr($prefix, strlen('idempotency:')), ':');
        $store->forget($lockKey);
    }

    private function store(): CacheRepository
    {
        $name = $this->config->get('idempotency.cache_store');

        return $this->cacheFactory->store(is_string($name) ? $name : null);
    }

    private function responseKey(string $key, ?string $scope): string
    {
        return $this->prefix($key, $scope).':response';
    }

    private function prefix(string $key, ?string $scope): string
    {
        return 'idempotency:'.($scope === null || $scope === '' ? '' : $scope.':').$key;
    }
}
