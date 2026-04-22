<?php

declare(strict_types=1);

use Infinitypaul\Idempotency\Support\DefaultKeyValidator;

it('validates UUIDs', function () {
    $v = new DefaultKeyValidator('uuid', 255);

    expect($v->isValid('123e4567-e89b-12d3-a456-426614174000'))->toBeTrue()
        ->and($v->isValid('not-a-uuid'))->toBeFalse()
        ->and($v->isValid(''))->toBeFalse();
});

it('validates ULIDs', function () {
    $v = new DefaultKeyValidator('ulid', 255);

    expect($v->isValid('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBeTrue()
        ->and($v->isValid('foo'))->toBeFalse();
});

it('validates against custom regex', function () {
    $v = new DefaultKeyValidator('/^[A-Z]{3}-\d+$/', 255);

    expect($v->isValid('PAY-42'))->toBeTrue()
        ->and($v->isValid('pay-42'))->toBeFalse();
});

it('rejects keys over max length', function () {
    $v = new DefaultKeyValidator('uuid', 10);

    expect($v->isValid('123e4567-e89b-12d3-a456-426614174000'))->toBeFalse();
});
