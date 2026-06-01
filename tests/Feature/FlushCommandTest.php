<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\CacheKeys;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

it('flushes all sub-keys for a given idempotency key', function () {
    $keys = CacheKeys::for('testkey');

    Cache::put($keys['response'], 'data', 60);
    Cache::put($keys['processing'], true, 60);
    Cache::put($keys['metadata'], [], 60);
    Cache::put($keys['payload_hash'], 'abc', 60);

    $this->artisan('idempotency:flush', ['key' => 'testkey'])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Flushed idempotency entries for testkey');

    expect(Cache::has($keys['response']))->toBeFalse()
        ->and(Cache::has($keys['processing']))->toBeFalse()
        ->and(Cache::has($keys['metadata']))->toBeFalse()
        ->and(Cache::has($keys['payload_hash']))->toBeFalse();
});

it('also removes the lock key', function () {
    $keys = CacheKeys::for('testkey2');

    Cache::put($keys['lock'], 'owner', 60);

    $this->artisan('idempotency:flush', ['key' => 'testkey2'])
        ->assertExitCode(Command::SUCCESS);

    expect(Cache::has($keys['lock']))->toBeFalse();
});

it('flushes with a scope prefix', function () {
    $keys = CacheKeys::for('mykey', 'user42');

    Cache::put($keys['response'], 'data', 60);
    Cache::put($keys['metadata'], [], 60);
    Cache::put($keys['lock'], 'owner', 60);

    $this->artisan('idempotency:flush', ['key' => 'mykey', '--scope' => 'user42'])
        ->assertExitCode(Command::SUCCESS);

    expect(Cache::has($keys['response']))->toBeFalse()
        ->and(Cache::has($keys['metadata']))->toBeFalse()
        ->and(Cache::has($keys['lock']))->toBeFalse();
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
