<div align="center">

# Idempotency for Laravel

**Safely retry mutating HTTP requests. No double charges. No duplicated orders. No accidental side effects.**

[![Latest Version](https://img.shields.io/packagist/v/infinitypaul/idempotency-laravel.svg?style=flat-square&label=packagist)](https://packagist.org/packages/infinitypaul/idempotency-laravel)
[![Tests](https://img.shields.io/github/actions/workflow/status/devaction-labs/Idempotency/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/devaction-labs/Idempotency/actions)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen?style=flat-square)](phpstan.neon)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange?style=flat-square)](pint.json)
[![PHP](https://img.shields.io/packagist/php-v/infinitypaul/idempotency-laravel?style=flat-square)](composer.json)
[![License](https://img.shields.io/github/license/devaction-labs/Idempotency?style=flat-square)](LICENSE.md)

</div>

---

## Why this exists

Your payment endpoint is a bomb waiting to go off. A mobile client's Wi-Fi hiccups, the request retries, and now you've charged the customer twice. You add a `requests` table with a unique constraint. A month later, a webhook consumer takes 31 seconds to respond, the sender retries, and you've just shipped two of the same order.

Idempotency is the protocol-level answer: the client sends a unique `Idempotency-Key` header, the server guarantees the same key produces the same outcome exactly once — even under retries, concurrency, and network failures.

This package is that guarantee, made Laravel-native.

```http
POST /api/payments HTTP/1.1
Idempotency-Key: 123e4567-e89b-12d3-a456-426614174000
Content-Type: application/json

{ "amount": 1000, "currency": "USD" }
```

- **First request** → processes and returns `201 Created` with `Idempotency-Status: Original`
- **Retry with same key + same payload** → returns the cached `201` with `Idempotency-Status: Repeated`
- **Retry with same key + different payload** → returns `422` (key reuse with different intent)
- **Concurrent retry while first is still processing** → returns `409` (another request in flight)

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [Per-route configuration](#per-route-configuration)
- [Configuration reference](#configuration-reference)
- [Scoping](#scoping)
- [Payload fingerprinting](#payload-fingerprinting)
- [Events and alerts](#events-and-alerts)
- [Telemetry](#telemetry)
- [Custom resolvers](#custom-resolvers)
- [Client integration](#client-integration)
- [Artisan commands](#artisan-commands)
- [Testing](#testing)
- [What v2 changed](#what-v2-changed)
- [FAQ](#faq)
- [License](#license)

---

## Requirements

| Dependency | Version |
| --- | --- |
| PHP | **8.3, 8.4, 8.5** |
| Laravel | **10.x / 11.x / 12.x / 13.x** |
| Cache store | any driver with atomic locks — `redis`, `memcached`, `database`, `dynamodb` |

## Installation

```bash
composer require infinitypaul/idempotency-laravel
php artisan vendor:publish --tag=idempotency-config
```

The service provider auto-registers and aliases the middleware as `idempotent`.

## Quick start

Wrap any mutating route in the `idempotent` middleware:

```php
// routes/api.php
Route::middleware(['auth:api', 'idempotent'])->group(function () {
    Route::post('/payments',  [PaymentController::class, 'store']);
    Route::post('/refunds',   [RefundController::class, 'store']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
});
```

Clients opt in by sending a UUID (or ULID, or any shape you configure) in the `Idempotency-Key` header. Done.

---

## How it works

```
┌───────────────┐     ┌──────────────────┐     ┌──────────────┐
│  Client sends │ ──► │  Acquire atomic  │ ──► │  Replay from │
│ Idempotency-  │     │  lock + check    │     │  cache if    │
│ Key header    │     │  cache           │     │  we have it  │
└───────────────┘     └──────────────────┘     └──────────────┘
                              │
                              ▼
                      ┌───────────────────┐
                      │ First time: run   │
                      │ handler, cache    │
                      │ response, release │
                      │ lock              │
                      └───────────────────┘
```

1. Validate the key format (UUID by default).
2. Compute a scope-aware cache key (`idempotency:{scope}:{key}:response`).
3. Acquire an atomic lock; if already held, wait up to `lock.wait` seconds.
4. Check the cache. If present, validate the payload matches and replay.
5. Otherwise, execute the route and cache the response if its status code is in range.
6. Release the lock.

Every step is instrumented — see [Events](#events-and-alerts) and [Telemetry](#telemetry).

---

## Per-route configuration

Middleware parameters let you tune behaviour per route without touching config:

```php
// Allow the route to be called without a key (e.g. public webhook probe)
Route::post('/webhooks/stripe', $handler)
    ->middleware('idempotent:optional');

// Short TTL for ephemeral actions
Route::post('/votes', $handler)
    ->middleware('idempotent:ttl=60');

// Stricter scope for tenant-partitioned operations
Route::post('/charges', $handler)
    ->middleware('idempotent:ttl=900,scope=user');
```

| Parameter | Effect |
| --- | --- |
| `optional` | Missing key is allowed — route runs without idempotency |
| `ttl=<seconds>` | Override the default TTL for this route |
| `scope=<name>` | Override the scope strategy for this route |

---

## Configuration reference

The full file lives at [`config/idempotency.php`](config/idempotency.php). The important knobs:

```php
return [
    'enabled'     => env('IDEMPOTENCY_ENABLED', true),
    'header_name' => env('IDEMPOTENCY_HEADER_NAME', 'Idempotency-Key'),
    'methods'     => ['POST', 'PUT', 'PATCH', 'DELETE'],

    'cache_store' => env('IDEMPOTENCY_CACHE_STORE', null),  // null = default
    'ttl'         => (int) env('IDEMPOTENCY_TTL', 86_400),   // seconds

    'scope' => env('IDEMPOTENCY_SCOPE', 'user_route'),
    //       global | user | route | ip | user_route | FQCN<ScopeResolver>

    'validation' => [
        'pattern'        => env('IDEMPOTENCY_KEY_PATTERN', 'uuid'),
        'max_key_length' => (int) env('IDEMPOTENCY_KEY_MAX_LENGTH', 255),
    ],

    'payload' => [
        'algo'          => env('IDEMPOTENCY_HASH_ALGO', 'sha256'),
        'sort_keys'     => true,
        'ignore'        => ['timestamp', 'client_request_id'],
        'include_files' => true,
    ],

    'cacheable_status' => [
        'min'     => 200,
        'max'     => 499,
        'exclude' => [408, 409, 425, 429],  // transient errors not replayed
    ],

    'lock' => [
        'timeout' => (int) env('IDEMPOTENCY_LOCK_TIMEOUT', 30),
        'wait'    => (int) env('IDEMPOTENCY_LOCK_WAIT', 5),
    ],

    'alerts' => [
        'hit_threshold' => (int) env('IDEMPOTENCY_ALERT_HIT_THRESHOLD', 5),
        'cooldown'      => (int) env('IDEMPOTENCY_ALERT_COOLDOWN', 3_600),
    ],

    'telemetry' => [
        'enabled'             => env('IDEMPOTENCY_TELEMETRY_ENABLED', true),
        'driver'              => env('IDEMPOTENCY_TELEMETRY_DRIVER', 'null'),
        'custom_driver_class' => null,
    ],
];
```

---

## Scoping

Scoping is the invisible safety net that stops user A's key from ever matching user B's cached response.

| Scope | Key partition | When to pick it |
| --- | --- | --- |
| `global` | none | Internal / trusted clients only |
| `user` | authenticated user id | User-level idempotence across their own routes |
| `route` | route name or URI | Public endpoints that shouldn't cross-pollinate |
| `ip` | client IP | Anonymous POSTs from the same caller |
| `user_route` *(default)* | user id + route | Most apps want this |

Need something custom (tenant id, API key, device id)? Implement `ScopeResolver` — see [Custom resolvers](#custom-resolvers).

---

## Payload fingerprinting

The package guards against "same key, different body" by hashing the payload and comparing. The hash is:

- Deterministic — keys are recursively sorted before hashing, so `{a:1,b:2}` and `{b:2,a:1}` match.
- Configurable — pick your algorithm (`sha256`, `xxh128`, …) via `payload.algo`.
- File-aware — uploaded files are fingerprinted by name + size + mime + content hash.
- Redactable — `payload.ignore` lets you strip volatile fields (`timestamp`, `client_request_id`) before hashing.

```php
'payload' => [
    'algo'          => 'sha256',
    'sort_keys'     => true,
    'ignore'        => ['timestamp', 'captured_at'],
    'include_files' => true,
],
```

---

## Events and alerts

The package dispatches `IdempotencyAlertFired` whenever something interesting happens. Listen for it and route to logs, Sentry, Slack — whatever:

```php
use Infinitypaul\Idempotency\Events\IdempotencyAlertFired;
use Infinitypaul\Idempotency\Logging\EventType;

Event::listen(IdempotencyAlertFired::class, function (IdempotencyAlertFired $event): void {
    match ($event->eventType) {
        EventType::PAYLOAD_MISMATCH    => logger()->warning('idempotency.mismatch', $event->context),
        EventType::CONCURRENT_CONFLICT => logger()->info('idempotency.concurrent',   $event->context),
        EventType::SIZE_WARNING        => logger()->notice('idempotency.large',      $event->context),
        default                        => logger()->debug('idempotency.'.$event->eventType->value, $event->context),
    };
});
```

Full event catalogue (`Infinitypaul\Idempotency\Logging\EventType`):

| Case | Fires when |
| --- | --- |
| `RESPONSE_DUPLICATE` | Hit count on a key exceeds `alerts.hit_threshold` |
| `PAYLOAD_MISMATCH` | Same key reused with a different request body |
| `CONCURRENT_CONFLICT` | A second request hits while the first is still processing |
| `LOCK_INCONSISTENCY` | Lock acquisition failed and no processing marker was found |
| `SIZE_WARNING` | Cached response exceeds `size_warning` bytes |
| `EXCEPTION_THROWN` | Cache or handler threw during processing |
| `MISSING_KEY` / `INVALID_KEY_FORMAT` | Client supplied a bad or absent key |

Alerts have a built-in cooldown (`alerts.cooldown`, defaults to 1h) so a bad client can't flood your logs.

---

## Telemetry

Shipped drivers: `null` (default) and `inspector`.

```bash
# To use Inspector
composer require inspector-apm/inspector-laravel
```

```dotenv
IDEMPOTENCY_TELEMETRY_DRIVER=inspector
```

The driver records: request counts, cache hit/miss, lock acquisition time, processing time, response size.

Write your own by implementing `Infinitypaul\Idempotency\Contracts\TelemetryDriver` and pointing `telemetry.custom_driver_class` at it.

---

## Custom resolvers

Every piece of logic sits behind a contract. Swap any of them:

```php
use Infinitypaul\Idempotency\Contracts\{
    KeyValidator,
    PayloadHasher,
    ScopeResolver,
    ResponseSerializer,
    TelemetryDriver,
};

// In your AppServiceProvider
public function register(): void
{
    $this->app->bind(ScopeResolver::class, TenantScopeResolver::class);
    $this->app->bind(PayloadHasher::class, WebhookAwareHasher::class);
}
```

A tenant-aware scope, for example:

```php
final class TenantScopeResolver implements ScopeResolver
{
    public function resolve(Illuminate\Http\Request $request): string
    {
        $tenant = $request->header('X-Tenant-ID') ?? 'public';
        $user   = $request->user()?->getAuthIdentifier() ?? 'guest';

        return "t:{$tenant}:u:{$user}";
    }
}
```

---

## Client integration

The server is only half of the contract — the client has to do three things correctly:

1. **Generate one key per logical operation**, not per HTTP attempt.
2. **Send the same key on every retry** of that operation.
3. **Only retry on transient failures** (network errors, `408`, `409`, `429`, `5xx`).

Below are drop-in patterns for the stacks you'll actually encounter.

### Key generation

Use a UUID v4/v7 — `crypto.randomUUID()` is in every modern browser and Node runtime:

```ts
// src/idempotency.ts
export const newIdempotencyKey = (): string =>
    (globalThis.crypto && 'randomUUID' in globalThis.crypto)
        ? globalThis.crypto.randomUUID()
        : fallbackUuidV4();

function fallbackUuidV4(): string {
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
}
```

**Rule of thumb**: bind the key to the user's intent, not the request. A "Pay" button click creates **one** key; every retry of that click reuses it. If the user clicks Pay again after seeing a final error, that's a new intent — new key.

### fetch + AbortController + retry

```ts
type IdempotentOptions<T> = {
    url: string;
    body: unknown;
    key?: string;
    signal?: AbortSignal;
    maxAttempts?: number;
};

const RETRY_ON = new Set([408, 409, 425, 429, 500, 502, 503, 504]);

export async function idempotentPost<T>({
    url,
    body,
    key = newIdempotencyKey(),
    signal,
    maxAttempts = 4,
}: IdempotentOptions<T>): Promise<T> {
    let lastError: unknown;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        try {
            const res = await fetch(url, {
                method: 'POST',
                signal,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Idempotency-Key': key,
                },
                body: JSON.stringify(body),
            });

            if (res.ok) return res.json() as Promise<T>;

            if (!RETRY_ON.has(res.status)) {
                // 400, 401, 403, 422, etc. are definitive — don't retry, don't regenerate the key
                throw new HttpError(res.status, await res.text());
            }

            lastError = new HttpError(res.status, await res.text());
        } catch (err) {
            if (signal?.aborted) throw err;
            lastError = err;
        }

        // Exponential backoff with full jitter — caps at 8s
        const delay = Math.min(8_000, 2 ** attempt * 250) * Math.random();
        await new Promise(resolve => setTimeout(resolve, delay));
    }

    throw lastError;
}

class HttpError extends Error {
    constructor(public status: number, public body: string) {
        super(`HTTP ${status}`);
    }
}
```

Usage:

```ts
const payment = await idempotentPost({
    url: '/api/payments',
    body: { amount: 1000, currency: 'USD', order_id: order.id },
});
```

### Axios interceptor

An interceptor that attaches an idempotency key to every mutating request, and retries on transient failures reusing the same key:

```ts
// src/http.ts
import axios, { AxiosError, AxiosRequestConfig } from 'axios';
import { newIdempotencyKey } from './idempotency';

const MUTATING = new Set(['post', 'put', 'patch', 'delete']);
const RETRY_ON = new Set([408, 409, 425, 429, 500, 502, 503, 504]);
const MAX_ATTEMPTS = 4;

export const http = axios.create({ baseURL: '/api' });

http.interceptors.request.use((config) => {
    const method = (config.method ?? 'get').toLowerCase();

    if (MUTATING.has(method) && !config.headers['Idempotency-Key']) {
        config.headers['Idempotency-Key'] = newIdempotencyKey();
    }

    return config;
});

http.interceptors.response.use(undefined, async (error: AxiosError) => {
    const config = error.config as AxiosRequestConfig & { __attempt?: number };
    if (!config) throw error;

    const status = error.response?.status;
    const networkError = !error.response;
    const shouldRetry = networkError || (status !== undefined && RETRY_ON.has(status));
    if (!shouldRetry) throw error;

    config.__attempt = (config.__attempt ?? 0) + 1;
    if (config.__attempt >= MAX_ATTEMPTS) throw error;

    const delay = Math.min(8_000, 2 ** config.__attempt * 250) * Math.random();
    await new Promise(r => setTimeout(r, delay));

    return http.request(config); // same Idempotency-Key header travels with the config
});
```

Call sites are oblivious to any of it:

```ts
const { data: order } = await http.post('/orders', payload);
```

### React — useIdempotentMutation

A hook that guarantees one key per mount-and-submit cycle, regenerated only after success or definitive failure:

```tsx
import { useCallback, useRef, useState } from 'react';

type State<T> =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; data: T }
    | { status: 'error'; error: unknown };

export function useIdempotentMutation<TBody, TResult>(
    url: string,
) {
    const keyRef = useRef<string | null>(null);
    const [state, setState] = useState<State<TResult>>({ status: 'idle' });

    const submit = useCallback(async (body: TBody): Promise<TResult> => {
        keyRef.current ??= newIdempotencyKey();
        setState({ status: 'loading' });

        try {
            const data = await idempotentPost<TResult>({ url, body, key: keyRef.current });
            setState({ status: 'success', data });
            keyRef.current = null; // next call is a new intent
            return data;
        } catch (error) {
            setState({ status: 'error', error });
            // Key stays so the user can retry the same logical action
            throw error;
        }
    }, [url]);

    const reset = useCallback(() => {
        keyRef.current = null;
        setState({ status: 'idle' });
    }, []);

    return { state, submit, reset };
}
```

```tsx
function PayButton({ amount }: { amount: number }) {
    const { state, submit } = useIdempotentMutation<{ amount: number }, Payment>('/api/payments');

    return (
        <button
            disabled={state.status === 'loading'}
            onClick={() => submit({ amount })}
        >
            {state.status === 'loading' ? 'Processing…' : 'Pay'}
        </button>
    );
}
```

### cURL / raw HTTP

For CLI tests, Postman collections, or platform docs:

```bash
KEY=$(uuidgen)

curl -X POST https://api.example.com/payments \
    -H "Authorization: Bearer $TOKEN" \
    -H "Idempotency-Key: $KEY" \
    -H "Content-Type: application/json" \
    -d '{"amount": 1000, "currency": "USD"}'
```

Run the exact same command again — the second response will include `Idempotency-Status: Repeated`.

### Checklist

- [x] One key per logical intent (not per click, per retry, or per render).
- [x] Key is UUID v4/v7 by default (or matches your `validation.pattern`).
- [x] Only retry on network errors, `408`, `409`, `425`, `429`, `5xx`.
- [x] Never retry on `400`/`401`/`403`/`422` — those are definitive.
- [x] Exponential backoff with jitter, cap retries at ~4.
- [x] Inspect `Idempotency-Status` header in dev tools when debugging.

---

## Artisan commands

```bash
# Flush everything we know about one key (response, metadata, lock, payload hash)
php artisan idempotency:flush 123e4567-e89b-12d3-a456-426614174000

# Scoped keys need the scope prefix you used when writing
php artisan idempotency:flush 123e4567-e89b-12d3-a456-426614174000 --scope=u42
```

You can also reach the same behaviour programmatically through the facade:

```php
use Infinitypaul\Idempotency\Facades\Idempotency;

Idempotency::flush('123e4567-e89b-12d3-a456-426614174000', scope: 'u42');
Idempotency::has('123e4567-e89b-12d3-a456-426614174000');
```

---

## Testing

```bash
composer test        # Pest suite
composer analyse     # PHPStan at level max
composer format      # Laravel Pint
```

The bundled Pest suite covers cache hit/miss, lock contention, payload mismatch, scope isolation, streamed-response skipping, header name override, and alert threshold firing. Run it as a living spec for how the middleware behaves.

---

## What v2 changed

A complete rewrite with breaking changes — migration notes live in [CHANGELOG.md](CHANGELOG.md).

| | v1 | v2 |
| --- | --- | --- |
| Payload hash | `md5(json_encode($request->all()))` — order-dependent | SHA-256 over recursively sorted payload + file fingerprints |
| Scoping | Global — keys leaked across users | `user_route` default, pluggable `ScopeResolver` |
| Response cache | Cloned `Symfony\Response` objects | Portable `{class, status, headers, content}` struct |
| Header name | Hardcoded | Config-driven |
| Key validation | UUID regex | `uuid` / `ulid` / regex / custom `KeyValidator` |
| Per-route config | Not supported | `idempotent:optional,ttl=300,scope=user` |
| Streamed/binary responses | Corrupted cache silently | Skipped |
| Inspector | Hard require | `suggest` |
| Static analysis | — | PHPStan level max |
| Tests | — | 21 Pest tests across unit + feature |

---

## FAQ

**Do I need Redis?** No, any cache store with atomic locks works (`redis`, `memcached`, `database`, `dynamodb`). The `array` driver is OK for tests.

**What happens if the client doesn't send a key?** A 400 by default. Use `idempotent:optional` on routes where the key is advisory.

**What if my handler throws?** The lock releases, the processing marker is cleared, nothing is cached, and an `EXCEPTION_THROWN` event fires. The retry starts fresh.

**Does it cache 4xx?** 400–499 are cached by default (with `408/409/425/429` excluded as transient). Adjust via `cacheable_status`.

**Does it cache 5xx?** No. Server errors are never cached — the retry runs fresh.

**What about file uploads?** Included in the hash by default (name + size + mime + xxh128 of contents). Disable via `payload.include_files`.

**ULID support?** `'pattern' => 'ulid'`. Or supply a regex. Or a class implementing `KeyValidator`.

**Octane-safe?** Yes — no request-scoped state is held on the middleware between requests.

---

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

<div align="center">
<sub>Original package by <a href="https://github.com/infinitypaul">@infinitypaul</a>. v2 rewrite maintained at <a href="https://github.com/devaction-labs/Idempotency">devaction-labs/Idempotency</a>.</sub>
</div>
