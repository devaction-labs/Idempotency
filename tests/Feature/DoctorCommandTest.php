<?php

declare(strict_types=1);

use Illuminate\Console\Command;

it('passes when idempotency configuration is valid', function () {
    $this->artisan('idempotency:doctor')
        ->expectsOutputToContain('Idempotency configuration looks valid.')
        ->assertExitCode(Command::SUCCESS);
});

it('fails when the hash algorithm is invalid', function () {
    config(['idempotency.payload.algo' => 'not-a-real-hash']);

    $this->artisan('idempotency:doctor')
        ->expectsOutputToContain('idempotency.payload.algo must be a valid hash algorithm.')
        ->assertExitCode(Command::FAILURE);
});

it('fails when processing ttl is shorter than lock timeout', function () {
    config([
        'idempotency.processing_ttl' => 10,
        'idempotency.lock.timeout' => 30,
    ]);

    $this->artisan('idempotency:doctor')
        ->expectsOutputToContain('idempotency.processing_ttl must be greater than or equal to idempotency.lock.timeout.')
        ->assertExitCode(Command::FAILURE);
});
