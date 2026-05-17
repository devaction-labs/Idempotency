<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

it('flushes all sub-keys for a given idempotency key', function () {
    Cache::put('idempotency:testkey:response', 'data', 60);
    Cache::put('idempotency:testkey:processing', true, 60);
    Cache::put('idempotency:testkey:metadata', [], 60);
    Cache::put('idempotency:testkey:payload_hash', 'abc', 60);

    $this->artisan('idempotency:flush', ['key' => 'testkey'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Flushed idempotency entries for testkey');

    expect(Cache::has('idempotency:testkey:response'))->toBeFalse()
        ->and(Cache::has('idempotency:testkey:processing'))->toBeFalse()
        ->and(Cache::has('idempotency:testkey:metadata'))->toBeFalse()
        ->and(Cache::has('idempotency:testkey:payload_hash'))->toBeFalse();
});

it('also removes the lock key', function () {
    Cache::put('idempotency_lock:testkey2', 'owner', 60);

    $this->artisan('idempotency:flush', ['key' => 'testkey2'])
        ->assertExitCode(Command::SUCCESS);

    expect(Cache::has('idempotency_lock:testkey2'))->toBeFalse();
});

it('flushes with a scope prefix', function () {
    Cache::put('idempotency:user42:mykey:response', 'data', 60);
    Cache::put('idempotency:user42:mykey:metadata', [], 60);
    Cache::put('idempotency_lock:user42:mykey', 'owner', 60);

    $this->artisan('idempotency:flush', ['key' => 'mykey', '--scope' => 'user42'])
        ->assertExitCode(Command::SUCCESS);

    expect(Cache::has('idempotency:user42:mykey:response'))->toBeFalse()
        ->and(Cache::has('idempotency:user42:mykey:metadata'))->toBeFalse()
        ->and(Cache::has('idempotency_lock:user42:mykey'))->toBeFalse();
});

it('fails and shows an error when no key is provided', function () {
    $this->artisan('idempotency:flush')
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Provide an idempotency key');
});

it('is a no-op for a key that does not exist', function () {
    $this->artisan('idempotency:flush', ['key' => 'nonexistent-key'])
        ->assertExitCode(Command::SUCCESS);
});
