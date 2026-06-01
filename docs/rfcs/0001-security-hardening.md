# RFC 0001: Security Hardening for Idempotency Middleware

Status: Proposed
Date: 2026-06-01
Owner: Devaction Labs

## Summary

This RFC proposes a focused security hardening pass for `devaction-labs/idempotency`.
The current implementation is generally sound for the default `user_route` scope
and UUID key validation, but the audit found one concurrency bug and several
defense-in-depth improvements worth scheduling before the next release.

## Goals

- Prevent concurrent requests from clearing another request's processing marker.
- Make cache key generation backend-safe and independent from raw user, route, or
  custom scope strings.
- Avoid replaying sensitive response headers unless explicitly intended.
- Reduce denial-of-service risk from oversized payloads, expensive file hashing,
  and unbounded idempotency key creation.
- Keep the public API compatible where possible.

## Non-Goals

- Replacing Laravel's cache lock implementation.
- Solving application-level authorization or rate limiting.
- Changing the default `user_route` scope semantics.
- Adding a new storage backend abstraction.

## Findings

### SEC-001: Non-owner requests can clear the processing marker

Severity: High

`EnsureIdempotency::handleNew()` always forgets `$keys['processing']` in `finally`.
That happens even when the current request did not acquire the lock. A concurrent
request that times out while another worker owns the lock can remove the owner's
processing marker.

Impact:

- Follow-up requests may stop receiving a deterministic `409` while the original
  request is still in flight.
- If the cache lock expires before the original handler returns, another request
  may acquire the lock and process the same operation concurrently.
- The current tests assert the `409` response but do not assert that the
  processing marker remains after the losing request exits.

Recommendation:

- Track ownership of the processing marker separately from `$lockAcquired`.
- Only the request that created the marker should clear it.
- Add regression tests for both lock-timeout paths.

### SEC-002: Cache keys are built from raw scope and idempotency key values

Severity: Medium

`keysFor()` uses raw scope and key values in cache keys. Defaults are safe enough
for UUID keys, but custom validators and raw `:scope=` middleware values can
produce long or delimiter-heavy cache keys. Route scopes may also include request
paths when no route object exists.

Impact:

- Memcached key length limits can break requests in production.
- Raw separators can make manual flush operations ambiguous.
- Custom scope values may leak user or route identifiers into cache keys.

Recommendation:

- Add canonical cache key derivation: keep a readable prefix, then hash the
  tuple `{scope, idempotency_key}` with SHA-256.
- Preserve existing keys during a migration window only if backward compatibility
  is required.
- Add tests for long custom scopes and long valid custom keys.

### SEC-003: Cached responses replay most headers, including `Set-Cookie`

Severity: Medium

`DefaultResponseSerializer` strips `Date` and `X-Idempotency-Replay-Of` only.
This means headers such as `Set-Cookie`, `Authorization`, `Proxy-Authenticate`,
or other application-specific secrets can be cached and replayed.

Impact:

- With default scoping this usually replays to the same user and route, but it can
  still reset tokens or replay sensitive per-response state.
- If an application intentionally uses `global` scope or a weak custom scope, the
  blast radius grows.

Recommendation:

- Introduce a default deny-list for sensitive and hop-by-hop headers.
- Support config for additional stripped headers.
- Document how to opt in to replaying specific headers with a custom serializer.

### SEC-004: Payload hashing can be resource-expensive

Severity: Medium

Payload hashing canonicalizes the full request payload and, by default, hashes
uploaded file contents. This is correct for strict idempotency, but public upload
endpoints can make the middleware spend CPU and disk I/O before application-level
rate limits or size limits reject the request.

Impact:

- Large multipart bodies can increase latency and CPU/I/O pressure.
- Attackers can create cache churn by sending many unique keys and large bodies.

Recommendation:

- Add configurable maximum hashable payload size and maximum hashable file size.
- Allow a safer file fingerprint mode using metadata only for endpoints that do
  not need content hashing.
- Document that route-level rate limiting must run before idempotency on public
  endpoints.

### SEC-005: Alert and telemetry context may include sensitive identifiers

Severity: Low

Events include idempotency keys, endpoint paths, user IDs, client IPs, and
exception messages. These are useful operational signals but may be sensitive in
centralized logs or telemetry systems.

Impact:

- Idempotency keys and exception messages can leak to downstream observability
  providers.
- Paths can contain identifiers when route names are unavailable.

Recommendation:

- Hash idempotency keys before emitting alert or telemetry context.
- Prefer route names over raw paths in all telemetry labels.
- Add config for context redaction.

### SEC-006: Configuration validation is permissive

Severity: Low

Invalid hash algorithms, unsafe cache stores, negative TTLs, and inconsistent
lock/processing TTL values are mostly handled implicitly. This can fail late at
request time instead of at boot or test time.

Impact:

- Misconfiguration may create availability issues that are hard to diagnose.
- A `lock.timeout` shorter than real handler execution can permit duplicate
  processing after lock expiry.

Recommendation:

- Add a config validator used by an Artisan command and optionally at boot.
- Validate hash algorithm, TTL ranges, cache store lock support, and
  `processing_ttl >= lock.timeout`.
- Document production-safe defaults.

## Proposed Implementation Plan

1. Fix `SEC-001` first because it is a concrete correctness and concurrency bug.
2. Add cache key canonicalization behind a small internal helper.
3. Add response header stripping configuration and tests.
4. Add payload/file hashing limits and documentation.
5. Add telemetry redaction and configuration validation.

## Compatibility Notes

- `SEC-001` should be backward compatible.
- Canonical cache keys can invalidate in-flight cache entries. Ship it in a minor
  release with release notes, or support old and new keys for one TTL window.
- Header stripping can change replayed responses. Use a conservative default
  deny-list and document custom serializers for applications that need more.

## Open Questions

- Should canonical key derivation be enabled by default immediately, or guarded by
  a config flag for one release?
- Should `Set-Cookie` be stripped by default, or should cookie replay remain
  opt-in per route through a custom serializer?
- Should payload size limits fail closed with `413`, or skip idempotency and pass
  through to the application?
