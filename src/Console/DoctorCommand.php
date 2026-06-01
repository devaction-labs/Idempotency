<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Console;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'idempotency:doctor';

    protected $description = 'Validate idempotency configuration for production use.';

    public function handle(Config $config, CacheFactory $cache): int
    {
        $errors = array_merge(
            $this->validateHashing($config),
            $this->validateTtls($config),
            $this->validateCacheStore($config, $cache),
        );

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Idempotency configuration looks valid.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function validateHashing(Config $config): array
    {
        $algo = $config->get('idempotency.payload.algo', 'sha256');

        if (! is_string($algo) || ! in_array($algo, hash_algos(), true)) {
            return ['idempotency.payload.algo must be a valid hash algorithm.'];
        }

        return [];
    }

    /** @return list<string> */
    private function validateTtls(Config $config): array
    {
        $errors = [];
        $ttl = $this->intConfig($config, 'idempotency.ttl');
        $processingTtl = $this->intConfig($config, 'idempotency.processing_ttl');
        $lockTimeout = $this->intConfig($config, 'idempotency.lock.timeout');
        $lockWait = $this->intConfig($config, 'idempotency.lock.wait');

        if ($ttl === null || $ttl <= 0) {
            $errors[] = 'idempotency.ttl must be greater than zero.';
        }

        if ($processingTtl === null || $processingTtl <= 0) {
            $errors[] = 'idempotency.processing_ttl must be greater than zero.';
        }

        if ($lockTimeout === null || $lockTimeout <= 0) {
            $errors[] = 'idempotency.lock.timeout must be greater than zero.';
        }

        if ($lockWait === null || $lockWait < 0) {
            $errors[] = 'idempotency.lock.wait must be zero or greater.';
        }

        if ($processingTtl !== null && $lockTimeout !== null && $processingTtl < $lockTimeout) {
            $errors[] = 'idempotency.processing_ttl must be greater than or equal to idempotency.lock.timeout.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateCacheStore(Config $config, CacheFactory $cache): array
    {
        $storeName = $config->get('idempotency.cache_store');

        try {
            $store = $cache->store(is_string($storeName) ? $storeName : null);
            if (! $store instanceof CacheRepository) {
                return ['idempotency.cache_store must resolve to Illuminate\\Cache\\Repository.'];
            }

            $lock = $store->lock('idempotency:doctor:'.bin2hex(random_bytes(8)), 1);
            if (! $lock instanceof Lock) {
                return ['idempotency.cache_store must return an atomic lock instance.'];
            }

            if ($lock->get()) {
                $lock->release();
            }
        } catch (Throwable $e) {
            return ['idempotency.cache_store must support atomic locks: '.$e->getMessage()];
        }

        return [];
    }

    private function intConfig(Config $config, string $key): ?int
    {
        $value = $config->get($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
