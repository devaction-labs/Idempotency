<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Infinitypaul\Idempotency\Support\DefaultPayloadHasher;

it('produces the same hash regardless of key order', function () {
    $hasher = new DefaultPayloadHasher('sha256', sortKeys: true);

    $a = Request::create('/x', 'POST', ['amount' => 10, 'currency' => 'USD']);
    $b = Request::create('/x', 'POST', ['currency' => 'USD', 'amount' => 10]);

    expect($hasher->hash($a))->toBe($hasher->hash($b));
});

it('differentiates different payloads', function () {
    $hasher = new DefaultPayloadHasher;

    $a = Request::create('/x', 'POST', ['amount' => 10]);
    $b = Request::create('/x', 'POST', ['amount' => 11]);

    expect($hasher->hash($a))->not->toBe($hasher->hash($b));
});

it('strips ignored paths before hashing', function () {
    $hasher = new DefaultPayloadHasher(ignore: ['timestamp']);

    $a = Request::create('/x', 'POST', ['amount' => 10, 'timestamp' => 1]);
    $b = Request::create('/x', 'POST', ['amount' => 10, 'timestamp' => 999]);

    expect($hasher->hash($a))->toBe($hasher->hash($b));
});
