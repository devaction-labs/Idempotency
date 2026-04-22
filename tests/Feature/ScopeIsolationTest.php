<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;

it('isolates cached responses per route when scope=route', function () {
    config(['idempotency.scope' => 'route']);

    Route::middleware(EnsureIdempotency::class)
        ->post('/a', fn () => response()->json(['from' => 'a'], 201));
    Route::middleware(EnsureIdempotency::class)
        ->post('/b', fn () => response()->json(['from' => 'b'], 201));

    $headers = ['Idempotency-Key' => '123e4567-e89b-12d3-a456-426614174000'];

    $a = $this->postJson('/a', ['amount' => 1], $headers);
    $b = $this->postJson('/b', ['amount' => 1], $headers);

    expect($a->json('from'))->toBe('a')
        ->and($b->json('from'))->toBe('b');

    $aReplay = $this->postJson('/a', ['amount' => 1], $headers);
    $aReplay->assertHeader('Idempotency-Status', 'Repeated')
        ->assertJson(['from' => 'a']);
});
