# Changelog

## [2.0.0] - 2026-04-22

Major rewrite. Everything below is a **breaking change** unless noted.

### Added
- **Per-route middleware parameters**: `idempotent:optional`, `idempotent:ttl=600`,
  `idempotent:scope=user_route`.
- **Scoping** via `Scope` enum (`global`, `user`, `route`, `ip`, `user_route`). Default is
  now `user_route` — idempotency keys cannot leak across users or endpoints.
  Custom `ScopeResolver` can be bound in the container.
- **Pluggable payload hashing** (`PayloadHasher` contract). Default:
  - SHA-256 with recursively sorted keys → order-independent.
  - File uploads fingerprinted (name, size, mime, content hash).
  - `payload.ignore` dot-paths stripped before hashing.
- **Pluggable key validation** (`KeyValidator` contract). Accepts `uuid`, `ulid`,
  a raw regex, or a class FQCN.
- **Portable response serialization** (`ResponseSerializer` contract). No more
  caching live `Response` objects — stores `{class, status, headers, content}`.
- **`idempotency:flush {key}`** artisan command.
- **`Idempotency` facade** and `IdempotencyManager`.
- `NullTelemetryDriver` as a named class.
- PHP 8.5 support, Laravel 13 support.
- Pest test suite, PHPStan level 6, Pint, GitHub Actions matrix.

### Fixed
- `alert.threshold` / `alerts.threshold` key mismatch — the alert threshold was
  silently inert.
- `size_warning` config key was never read. Renamed, wired up.
- `header_name` config was ignored — `Idempotency-Key` was hardcoded.
- TOCTOU race in `Cache::has` + `Cache::get` — now a single atomic `get`.
- `md5(json_encode(...))` was non-deterministic across key order. Replaced.
- `$request->user()->id` could NPE when the guard was rotated — now `?->`.
- `preg_match` truthy-cast bug on invalid regex.
- Streamed/binary responses no longer explode the cache.
- Useless `try/catch (\Exception $e) { throw $e; }` removed.
- Inspector is no longer a hard dependency — moved to `suggest`.

### Changed
- **Minimum PHP 8.3, minimum Laravel 11.** (Laravel 10 reached security EOL in August 2025; `pest-plugin-laravel ^3.0` requires Laravel 11+.)
- Config moved from `src/config/` to `config/`.
- `EventType` is now a backed enum.
- `IdempotencyAlertFired` is now `readonly` + `Dispatchable` and carries typed properties.
- TTL and processing TTL are now **seconds**, not minutes.
- Middleware alias registered as `idempotent`.
- Telemetry driver `null` is now a real class, not an anonymous one.
- Error codes: lock-inconsistency now returns **503** (transient) instead of 500.

### Removed
- Auto-caching of 4xx "generic" responses — configurable via `cacheable_status`.
  Defaults exclude `408/409/425/429`.
