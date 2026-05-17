<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Support;

use DevactionLabs\Idempotency\Contracts\KeyValidator;
use Illuminate\Support\Str;

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
        if ($this->pattern === '' || ! $this->isValidPattern($this->pattern)) {
            return false;
        }

        return preg_match($this->pattern, $key) === 1;
    }

    private function isValidPattern(string $pattern): bool
    {
        // preg_match emits E_WARNING on invalid regex and returns false.
        // @ suppression is the standard PHP idiom for probing pattern validity.
        return @preg_match($pattern, '') !== false;
    }
}
