<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Support;

final class CacheKeys
{
    /** @return array{response:string, processing:string, metadata:string, lock:string, payload_hash:string} */
    public static function for(string $key, ?string $scope = null): array
    {
        $prefix = self::prefix($key, $scope);

        return [
            'response' => $prefix.':response',
            'processing' => $prefix.':processing',
            'metadata' => $prefix.':metadata',
            'lock' => 'idempotency_lock:'.self::digest($key, $scope),
            'payload_hash' => $prefix.':payload_hash',
        ];
    }

    private static function prefix(string $key, ?string $scope): string
    {
        return 'idempotency:'.self::digest($key, $scope);
    }

    private static function digest(string $key, ?string $scope): string
    {
        return hash('sha256', json_encode([
            'scope' => $scope ?? '',
            'key' => $key,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
