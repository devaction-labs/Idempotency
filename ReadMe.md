# Idempotency for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/infinitypaul/idempotency-laravel.svg?style=flat-square)](https://packagist.org/packages/infinitypaul/idempotency-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/infinitypaul/idempotency-laravel/tests.yml?branch=main&label=tests)](https://github.com/infinitypaul/idempotency-laravel/actions)

A production-ready Laravel middleware for implementing idempotency in your API.
Safely retry mutating requests without double-charging, double-submitting, or
double-side-effecting anything.

> **v2** is a rewrite. See [CHANGELOG](CHANGELOG.md) for migration notes.

## Requirements

- PHP **8.3+** (tested on 8.3, 8.4, 8.5)
- Laravel **10.x, 11.x, 12.x, or 13.x**
- A cache store that supports atomic locks (`redis`, `memcached`, `database`, `dynamodb`)

## Install

```bash
composer require infinitypaul/idempotency-laravel
php artisan vendor:publish --tag=idempotency-config
```

## Quick start

```php
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;

Route::middleware(['auth:api', 'idempotent'])->group(function () {
    Route::post('/payments', [PaymentController::class, 'store']);
});
```

Clients send an `Idempotency-Key` header (UUID by default):

```http
POST /api/payments HTTP/1.1
Idempotency-Key: 123e4567-e89b-12d3-a456-426614174000
Content-Type: application/json

{ "amount": 1000, "currency": "USD" }
```

Retries with the same key **and** payload replay the original response. Retries with
the same key and a **different** payload return `422`.

## What v2 is smarter about

| Feature | v1 | v2 |
| --- | --- | --- |
| Payload hashing | `md5(json_encode($request->all()))` — order-dependent | SHA-256 over recursively sorted payload + file fingerprints |
| Scoping | Global (user A's key collided with user B's) | `user_route` by default; configurable enum + custom resolver |
| Response caching | Cloned `Symfony\Response` objects into Redis | Portable `{class, status, headers, content}` payload |
| Header name | Hardcoded `Idempotency-Key` | Config-driven |
| Key validation | UUID regex in config | `uuid` / `ulid` / regex / custom `KeyValidator` |
| Per-route config | None | `idempotent:optional,ttl=300,scope=user` |
| Streamed/binary responses | Silently corrupted the cache | Skipped |
| Inspector | Hard `require` | `suggest` |
| Artisan | — | `idempotency:flush {key}` |

## Per-route parameters

```php
Route::post('/webhooks', $handler)->middleware('idempotent:optional');
Route::post('/charges',  $handler)->middleware('idempotent:ttl=600,scope=user');
```

Supported: `optional`, `ttl=<seconds>`, `scope=<name>`.

## Configuration

See [`config/idempotency.php`](config/idempotency.php) for the full list. Highlights:

```php
'header_name'     => 'Idempotency-Key',
'methods'         => ['POST', 'PUT', 'PATCH', 'DELETE'],
'ttl'             => 86_400,                 // seconds
'scope'           => 'user_route',           // global|user|route|ip|user_route|FQCN
'validation'      => ['pattern' => 'uuid'],  // uuid|ulid|<regex>|FQCN
'payload'         => [
    'algo'          => 'sha256',
    'sort_keys'     => true,
    'ignore'        => ['timestamp'],        // dot-notation
    'include_files' => true,
],
'cacheable_status' => ['min' => 200, 'max' => 499, 'exclude' => [408,409,425,429]],
'lock'             => ['timeout' => 30, 'wait' => 5],
'alerts'           => ['hit_threshold' => 5, 'cooldown' => 3_600],
'telemetry'        => ['driver' => 'null'],  // null|inspector|custom
```

## Custom resolvers

Everything intelligent is behind a contract — override via the container:

```php
use Infinitypaul\Idempotency\Contracts\{KeyValidator, PayloadHasher, ScopeResolver, TelemetryDriver};

$this->app->bind(ScopeResolver::class, MyTenantScopeResolver::class);
$this->app->bind(PayloadHasher::class, MyHasher::class);
```

## Response headers

The middleware adds:

- `Idempotency-Key` — the key the client sent
- `Idempotency-Status` — `Original` on first request, `Repeated` on replay

## Events

Listen for `Infinitypaul\Idempotency\Events\IdempotencyAlertFired`:

```php
Event::listen(IdempotencyAlertFired::class, function ($event) {
    logger()->warning('idempotency', [
        'type'    => $event->eventType->value,
        'context' => $event->context,
    ]);
});
```

Event types are exposed via `Infinitypaul\Idempotency\Logging\EventType` (backed enum):
`RESPONSE_DUPLICATE`, `PAYLOAD_MISMATCH`, `CONCURRENT_CONFLICT`, `SIZE_WARNING`,
`LOCK_INCONSISTENCY`, `EXCEPTION_THROWN`, `MISSING_KEY`, `INVALID_KEY_FORMAT`.

## Telemetry

Bundled drivers: `null` (default) and `inspector`. To use Inspector:

```bash
composer require inspector-apm/inspector-laravel
```

Then set `IDEMPOTENCY_TELEMETRY_DRIVER=inspector`. Or implement
`Infinitypaul\Idempotency\Contracts\TelemetryDriver` and point `telemetry.custom_driver_class`
at your class.

## Artisan

```bash
php artisan idempotency:flush 123e4567-e89b-12d3-a456-426614174000 --scope=u42
```

## Testing

```bash
composer test
composer analyse
composer format
```

## License

MIT. See [LICENSE](LICENSE.md).
