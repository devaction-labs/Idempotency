<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Contracts;

use Symfony\Component\HttpFoundation\Response;

interface ResponseSerializer
{
    /** @return array<string,mixed> */
    public function serialize(Response $response): array;

    /** @param array<string,mixed> $payload */
    public function deserialize(array $payload): Response;
}
