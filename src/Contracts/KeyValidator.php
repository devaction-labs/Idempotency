<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Contracts;

interface KeyValidator
{
    public function isValid(string $key): bool;
}
