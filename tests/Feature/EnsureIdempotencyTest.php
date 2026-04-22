<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Events\IdempotencyAlertFired;
use DevactionLabs\Idempotency\Logging\EventType;
use DevactionLabs\Idempotency\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(EnsureIdempotency::class)->post('/pay', function () {
        return response()->json(['id' => uniqid('p_', true)], 201);
    });
});

$uuid = fn () => '123e4567-e89b-12d3-a456-426614174000';

it('rejects a POST with no idempotency key', function () {
    $this->postJson('/pay', ['amount' => 10])
        ->assertStatus(400)
        ->assertJson(['error' => 'Missing Idempotency-Key header']);
});

it('rejects an invalid key format', function () {
    $this->postJson('/pay', ['amount' => 10], ['Idempotency-Key' => 'not-a-uuid'])
        ->assertStatus(400)
        ->assertJsonStructure(['error']);
});

it('returns the same response for the same key and payload', function () use ($uuid) {
    $headers = ['Idempotency-Key' => $uuid()];

    $first = $this->postJson('/pay', ['amount' => 10], $headers);
    $first->assertStatus(201)->assertHeader('Idempotency-Status', 'Original');

    $second = $this->postJson('/pay', ['amount' => 10], $headers);
    $second->assertStatus(201)->assertHeader('Idempotency-Status', 'Repeated');

    expect($second->json('id'))->toBe($first->json('id'));
});

it('returns the same response when payload key order differs', function () use ($uuid) {
    $headers = ['Idempotency-Key' => $uuid()];

    $first = $this->postJson('/pay', ['amount' => 10, 'currency' => 'USD'], $headers);
    $second = $this->postJson('/pay', ['currency' => 'USD', 'amount' => 10], $headers);

    expect($second->json('id'))->toBe($first->json('id'));
    $second->assertHeader('Idempotency-Status', 'Repeated');
});

it('returns 422 when the same key is used with a different payload', function () use ($uuid) {
    $headers = ['Idempotency-Key' => $uuid()];

    $this->postJson('/pay', ['amount' => 10], $headers)->assertStatus(201);
    $this->postJson('/pay', ['amount' => 20], $headers)
        ->assertStatus(422)
        ->assertJsonFragment(['error' => 'Idempotency key reused with a different request payload']);
});

it('skips non-applicable methods', function () {
    Route::middleware(EnsureIdempotency::class)->get('/status', fn () => ['ok' => true]);
    $this->getJson('/status')->assertOk()->assertJson(['ok' => true]);
});

it('does not cache 5xx responses', function () use ($uuid) {
    Route::middleware(EnsureIdempotency::class)->post('/boom', fn () => response()->json(['x' => 1], 500));

    $headers = ['Idempotency-Key' => $uuid()];
    $this->postJson('/boom', [], $headers)->assertStatus(500);
    // Second call processes fresh rather than replay.
    $this->postJson('/boom', [], $headers)
        ->assertStatus(500)
        ->assertHeader('Idempotency-Status', 'Original');
});

it('supports the optional middleware parameter', function () {
    Route::middleware(EnsureIdempotency::class.':optional')->post('/opt', fn () => ['ok' => true]);
    $this->postJson('/opt', ['a' => 1])->assertOk()->assertJson(['ok' => true]);
});

it('fires an alert after the configured hit threshold', function () use ($uuid) {
    config(['idempotency.alerts.hit_threshold' => 2]);
    Event::fake([IdempotencyAlertFired::class]);
    $headers = ['Idempotency-Key' => $uuid()];

    $this->postJson('/pay', ['amount' => 10], $headers);
    $this->postJson('/pay', ['amount' => 10], $headers);
    $this->postJson('/pay', ['amount' => 10], $headers);

    Event::assertDispatched(
        IdempotencyAlertFired::class,
        fn (IdempotencyAlertFired $e) => $e->eventType === EventType::RESPONSE_DUPLICATE,
    );
});

it('honors the configured header name', function () use ($uuid) {
    config(['idempotency.header_name' => 'X-Idempotency-Key']);
    Route::middleware(EnsureIdempotency::class)->post('/custom', fn () => response()->json(['ok' => true], 201));

    $this->postJson('/custom', [], ['X-Idempotency-Key' => $uuid()])
        ->assertStatus(201)
        ->assertHeader('X-Idempotency-Key', $uuid())
        ->assertHeader('Idempotency-Status', 'Original');
});
