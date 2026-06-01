# Security Hardening Tasks

This task list implements RFC 0001 in small, reviewable changes. Each task should
ship with focused tests and release notes when behavior changes.

## P0

### TASK-001: Preserve processing marker ownership

Problem: A request that fails to acquire the lock can clear another request's
`processing` marker in `EnsureIdempotency::handleNew()`.

Implementation:

- Track whether the current request created the processing marker.
- Clear `$keys['processing']` only when the current request owns it.
- Keep lock release behavior unchanged.

Acceptance criteria:

- A lock-losing request returns `409` and leaves the existing processing marker in
  cache.
- A lock-losing request returns `503` and does not clear any existing processing
  marker.
- The original owner still clears the marker after success or exception.
- `vendor/bin/pest tests/Feature/ConcurrentRequestTest.php` passes.

## P1

### TASK-002: Canonicalize cache key storage names

Problem: Cache keys are built from raw scope and idempotency key values.

Implementation:

- Add an internal cache key builder that hashes `{scope, idempotency_key}`.
- Use the builder from middleware, manager, and flush command.
- Keep generated keys within common backend limits.

Acceptance criteria:

- Long valid custom idempotency keys do not create backend-invalid cache keys.
- Custom route/scope values with delimiters do not make flush ambiguous.
- Existing UUID default behavior remains covered by feature tests.

### TASK-003: Strip sensitive replay headers by default

Problem: Cached responses replay most headers, including potentially sensitive
ones.

Implementation:

- Extend the default stripped header list to include `set-cookie`,
  `authorization`, `proxy-authenticate`, `www-authenticate`, `connection`,
  `transfer-encoding`, `upgrade`, and `keep-alive`.
- Add `idempotency.response.strip_headers` config for application-specific names.
- Document custom serializer escape hatch.

Acceptance criteria:

- Replayed responses do not include default sensitive headers.
- Custom stripped headers are honored.
- Existing JSON response replay behavior stays unchanged.

### TASK-004: Add hash resource limits

Problem: Payload and file hashing can be expensive on public endpoints.

Implementation:

- Add config for maximum payload bytes considered by the hasher.
- Add config for maximum file bytes hashable by content.
- Define explicit behavior when limits are exceeded.

Acceptance criteria:

- Oversized request bodies and files follow documented behavior.
- Multipart hashing remains deterministic under the configured limits.
- Public upload guidance is added to the README.

## P2

### TASK-005: Redact alert and telemetry context

Problem: Alert and telemetry context may contain sensitive keys, paths, or
exception messages.

Implementation:

- Hash idempotency keys before emitting them.
- Prefer route names over raw paths in telemetry and alerts.
- Add config to suppress exception messages in alert context.

Acceptance criteria:

- Events no longer emit raw idempotency keys by default.
- Tests cover redacted event payloads.
- Documentation explains how to correlate redacted keys during incident response.

### TASK-006: Add configuration validation

Problem: Unsafe or inconsistent configuration fails late.

Implementation:

- Add an Artisan command such as `idempotency:doctor`.
- Validate hash algorithm, TTL ranges, cache store lock support, and
  `processing_ttl >= lock.timeout`.
- Optionally expose a boot-time strict mode.

Acceptance criteria:

- Invalid hash algorithms are reported before request handling.
- Unsafe TTL combinations are reported with actionable messages.
- The command exits non-zero for invalid production-critical settings.

## Suggested Order

1. TASK-001
2. TASK-002
3. TASK-003
4. TASK-004
5. TASK-005
6. TASK-006
