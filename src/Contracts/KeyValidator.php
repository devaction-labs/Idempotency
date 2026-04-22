<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Contracts;

interface KeyValidator
{
    public function isValid(string $key): bool;
}
