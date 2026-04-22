<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Support;

use Illuminate\Support\Str;
use Infinitypaul\Idempotency\Contracts\KeyValidator;

final class DefaultKeyValidator implements KeyValidator
{
    public function __construct(
        private readonly string $pattern,
        private readonly int $maxLength,
    ) {}

    public function isValid(string $key): bool
    {
        if ($key === '' || strlen($key) > $this->maxLength) {
            return false;
        }

        return match ($this->pattern) {
            'uuid' => Str::isUuid($key),
            'ulid' => Str::isUlid($key),
            default => $this->regex($key),
        };
    }

    private function regex(string $key): bool
    {
        $pattern = $this->pattern;

        if ($pattern === '' || @preg_match($pattern, '') === false) {
            return false;
        }

        return preg_match($pattern, $key) === 1;
    }
}
