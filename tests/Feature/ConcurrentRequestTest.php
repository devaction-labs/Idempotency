<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * These tests simulate concurrent scenarios by holding the cache lock externally
 * (as a second "in-flight" worker would) and setting lock.wait=0 so the middleware
 * gives up immediately. True parallelism is not required — we manipulate the shared
 * cache state to drive each code path deterministically.
 */
beforeEach(function () {
    config([
        'idempotency.scope' => 'global', // simplifies key computation: no user/route prefix
        'idempotency.lock.wait' => 0,    // fail-fast so the test doesn't hang
    ]);

    Route::middleware(EnsureIdempotency::class)
        ->post('/order', fn () => response()->json(['ok' => true], 201));
});

$key = '123e4567-e89b-12d3-a456-426614174099';

it('returns 409 with Retry-After when the same key is being processed concurrently', function () use ($key) {
    // Hold the lock to simulate an in-flight worker.
    $lock = Cache::lock('idempotency_lock:'.$key, 30);
    expect($lock->acquire())->toBeTrue();

    // Seed the processing marker so the middleware knows a request is in-flight.
    Cache::put('idempotency:'.$key.':processing', true, 300);

    try {
        $this->postJson('/order', ['amount' => 1], ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertHeader('Retry-After')
            ->assertJson(['error' => 'A request with this idempotency key is currently being processed']);
    } finally {
        $lock->release();
    }
});

it('returns 503 with Retry-After on lock timeout when no processing marker exists', function () use ($key) {
    // Hold the lock without seeding a processing marker — simulates a worker that
    // acquired the lock but died before writing the processing flag (inconsistency).
    $lock = Cache::lock('idempotency_lock:'.$key, 30);
    expect($lock->acquire())->toBeTrue();

    try {
        $this->postJson('/order', ['amount' => 1], ['Idempotency-Key' => $key])
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJson(['error' => 'Could not acquire idempotency lock. Please retry.']);
    } finally {
        $lock->release();
    }
});
