# Security Policy

## Supported Versions

| Version | Status |
| --- | --- |
| 2.x | Actively supported |
| 1.x | Superseded — no security backports |

## Reporting a Vulnerability

If you've found a vulnerability in this package, please **do not open a
public issue**. Instead, report it privately through GitHub:

- Go to https://github.com/devaction-labs/Idempotency/security/advisories/new
- Describe the issue with enough detail to reproduce it
- Include affected versions and a proof of concept if possible

We aim to acknowledge reports within **two business days** and to ship a fix
or mitigation within **14 days** for high-severity issues.

## Scope

This policy covers the middleware's handling of idempotency keys, payload
hashing, cache scoping, and any path that could lead to:

- Cross-user replay (one user receiving another user's cached response)
- Cache poisoning via crafted payloads or headers
- Denial-of-service on the cache store via unbounded growth
- Lock-starvation attacks

Out of scope: vulnerabilities in Laravel core, the underlying cache driver,
or your application's business logic. Report those upstream.
