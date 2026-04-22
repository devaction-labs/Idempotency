<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Contracts;

use Illuminate\Http\Request;

interface PayloadHasher
{
    public function hash(Request $request): string;
}
