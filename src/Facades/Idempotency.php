<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Facades;

use Illuminate\Support\Facades\Facade;
use Infinitypaul\Idempotency\IdempotencyManager;

/**
 * @method static void flush(?string $key = null, ?string $scope = null)
 * @method static bool has(string $key, ?string $scope = null)
 */
final class Idempotency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IdempotencyManager::class;
    }
}
