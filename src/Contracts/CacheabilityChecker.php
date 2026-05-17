<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Contracts;

use Symfony\Component\HttpFoundation\Response;

/**
 * Optional companion to ResponseSerializer.
 *
 * Implement this interface on a custom ResponseSerializer to control which
 * responses the middleware caches. When absent, EnsureIdempotency falls back
 * to DefaultResponseSerializer::isCacheable().
 */
interface CacheabilityChecker
{
    public function isCacheable(Response $response): bool;
}
