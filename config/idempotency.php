<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\Scope;

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Global switch. When disabled, the middleware becomes a no-op. Useful for
    | local/testing environments where you don't want cached replays.
    |
    */
    'enabled' => env('IDEMPOTENCY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Header Name
    |--------------------------------------------------------------------------
    |
    | The HTTP header clients use to supply the idempotency key. Defaults to
    | "Idempotency-Key" per the IETF draft.
    |
    */
    'header_name' => env('IDEMPOTENCY_HEADER_NAME', 'Idempotency-Key'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Methods
    |--------------------------------------------------------------------------
    |
    | Which methods are eligible for idempotency handling. GET/HEAD/OPTIONS are
    | intentionally excluded — they should be idempotent by protocol contract.
    |
    */
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Which cache store backs the middleware. Must support atomic locks
    | (redis, memcached, database, dynamodb). The "array" store does support
    | locks but is per-process and unsafe across workers.
    |
    */
    'cache_store' => env('IDEMPOTENCY_CACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Time-to-Live (seconds)
    |--------------------------------------------------------------------------
    |
    | How long cached responses are served for the same key. Defaults to 24h.
    |
    */
    'ttl' => (int) env('IDEMPOTENCY_TTL', 86_400),

    /*
    |--------------------------------------------------------------------------
    | Processing TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | How long the "processing" marker lives. Acts as a fallback if a worker
    | dies before releasing the lock. Should be >= lock_timeout.
    |
    */
    'processing_ttl' => (int) env('IDEMPOTENCY_PROCESSING_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Lock
    |--------------------------------------------------------------------------
    */
    'lock' => [
        // Max time a worker may hold the lock while processing.
        'timeout' => (int) env('IDEMPOTENCY_LOCK_TIMEOUT', 30),

        // Max time a concurrent request waits before giving up.
        'wait' => (int) env('IDEMPOTENCY_LOCK_WAIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Validation
    |--------------------------------------------------------------------------
    |
    | Pattern: 'uuid' | 'ulid' | a regex string | a callable FQCN implementing
    | DevactionLabs\Idempotency\Contracts\KeyValidator. Keep the allow-list of
    | formats tight; rejecting malformed keys early avoids cache pollution.
    |
    */
    'validation' => [
        'pattern' => env('IDEMPOTENCY_KEY_PATTERN', 'uuid'),
        'max_key_length' => (int) env('IDEMPOTENCY_KEY_MAX_LENGTH', 255),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | How keys are partitioned. Preventing "user A's key leaks to user B" is
    | the whole reason this exists. Built-ins: Scope::GLOBAL, USER, ROUTE, IP,
    | USER_ROUTE. For anything else, bind a Contracts\ScopeResolver.
    |
    */
    'scope' => env('IDEMPOTENCY_SCOPE', Scope::USER_ROUTE->value),

    /*
    |--------------------------------------------------------------------------
    | Payload Hashing
    |--------------------------------------------------------------------------
    |
    | algo:    any hash_algos() value (sha256 recommended).
    | sort:    canonicalize array keys so {"a":1,"b":2} == {"b":2,"a":1}.
    | ignore:  dot-notation paths to strip before hashing (e.g. timestamps).
    | include_files: whether uploaded files are included in the hash.
    |
    */
    'payload' => [
        'algo' => env('IDEMPOTENCY_HASH_ALGO', 'sha256'),
        'sort_keys' => true,
        'ignore' => [],
        'include_files' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cacheable Responses
    |--------------------------------------------------------------------------
    |
    | Only responses with a status code in this range are cached. Anything
    | else is returned but not replayed — a 500 shouldn't be pinned for 24h.
    |
    */
    'cacheable_status' => [
        'min' => 200,
        'max' => 499,
        'exclude' => [408, 409, 425, 429],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Size Warning (bytes)
    |--------------------------------------------------------------------------
    |
    | Responses larger than this trigger a SIZE_WARNING alert. Does not block
    | caching — adjust to your cache backend's practical item size.
    |
    */
    'size_warning' => (int) env('IDEMPOTENCY_SIZE_WARNING', 1_048_576),

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | hit_threshold: after how many replays of the same key an alert fires.
    | cooldown:      seconds before the same (type + context) alert can fire
    |                again — prevents event floods.
    |
    */
    'alerts' => [
        'hit_threshold' => (int) env('IDEMPOTENCY_ALERT_HIT_THRESHOLD', 5),
        'cooldown' => (int) env('IDEMPOTENCY_ALERT_COOLDOWN', 3_600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | driver: 'null' | 'inspector' | 'custom'
    | custom_driver_class: FQCN of a Contracts\TelemetryDriver when using custom.
    |
    */
    'telemetry' => [
        'enabled' => env('IDEMPOTENCY_TELEMETRY_ENABLED', true),
        'driver' => env('IDEMPOTENCY_TELEMETRY_DRIVER', 'null'),
        'custom_driver_class' => null,
    ],
];
