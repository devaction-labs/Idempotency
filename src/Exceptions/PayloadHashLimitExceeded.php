<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Exceptions;

use RuntimeException;

final class PayloadHashLimitExceeded extends RuntimeException
{
    public static function payload(int $bytes, int $limit): self
    {
        return new self("Request payload is too large to hash ({$bytes} bytes, limit {$limit} bytes).");
    }

    public static function file(string $name, int $bytes, int $limit): self
    {
        return new self("Uploaded file '{$name}' is too large to hash ({$bytes} bytes, limit {$limit} bytes).");
    }
}
