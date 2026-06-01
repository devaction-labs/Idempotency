<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency;

use DevactionLabs\Idempotency\Support\CacheKeys;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\SimpleCache\InvalidArgumentException;

final class IdempotencyManager
{
    public function __construct(
        private readonly CacheFactory $cacheFactory,
        private readonly Config $config,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function has(string $key, ?string $scope = null): bool
    {
        return $this->store()->has($this->responseKey($key, $scope));
    }

    /**
     * Flush every cache entry associated with an idempotency key.
     *
     * Bulk flush (key === null) is intentionally not supported: reliable
     * wildcard deletion requires a key-scanning API that is unavailable on all
     * cache backends (e.g. Redis SCAN vs array driver). Passing null is a
     * documented no-op — use the `idempotency:flush` Artisan command or your
     * cache backend's native tools for bulk cleanup.
     */
    public function flush(?string $key = null, ?string $scope = null): void
    {
        if ($key === null) {
            return;
        }

        $keys = CacheKeys::for($key, $scope);
        $store = $this->store();
        foreach (['response', 'processing', 'metadata', 'payload_hash'] as $segment) {
            $store->forget($keys[$segment]);
        }
        $store->forget($keys['lock']);
    }

    private function store(): CacheRepository
    {
        $name = $this->config->get('idempotency.cache_store');

        return $this->cacheFactory->store(is_string($name) ? $name : null);
    }

    private function responseKey(string $key, ?string $scope): string
    {
        return CacheKeys::for($key, $scope)['response'];
    }
}
