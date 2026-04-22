<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Contracts;

use Illuminate\Http\Request;

interface ScopeResolver
{
    /**
     * Return a short, stable string that partitions the idempotency key space.
     * Empty string means "global / no additional scope".
     */
    public function resolve(Request $request): string;
}
