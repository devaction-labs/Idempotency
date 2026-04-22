<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Facades;

use DevactionLabs\Idempotency\IdempotencyManager;
use Illuminate\Support\Facades\Facade;

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
