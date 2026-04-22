<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Typed helpers over {@see Config}. The framework returns `mixed` from `get()`
 * — this trait narrows it at the edge so the rest of the code can stay strict.
 */
trait ConfigAccess
{
    private function configStr(Config $config, string $key, string $default = ''): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function configInt(Config $config, string $key, int $default = 0): int
    {
        $value = $config->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function configBool(Config $config, string $key, bool $default = false): bool
    {
        $value = $config->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<int|string,mixed>  $default
     * @return array<int|string,mixed>
     */
    private function configArr(Config $config, string $key, array $default = []): array
    {
        $value = $config->get($key, $default);

        return is_array($value) ? $value : $default;
    }
}
