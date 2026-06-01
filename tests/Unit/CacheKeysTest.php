<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\CacheKeys;

it('builds bounded cache keys without raw scope or idempotency key values', function () {
    $key = str_repeat('key:', 80);
    $scope = str_repeat('scope:', 80);

    $keys = CacheKeys::for($key, $scope);

    expect($keys['response'])->toStartWith('idempotency:')
        ->and($keys['lock'])->toStartWith('idempotency_lock:')
        ->and(strlen($keys['response']))->toBeLessThanOrEqual(90)
        ->and(strlen($keys['lock']))->toBeLessThanOrEqual(90)
        ->and($keys['response'])->not->toContain($key)
        ->and($keys['response'])->not->toContain($scope)
        ->and($keys['lock'])->not->toContain($key)
        ->and($keys['lock'])->not->toContain($scope);
});

it('partitions cache keys by scope', function () {
    expect(CacheKeys::for('same-key', 'scope-a')['response'])
        ->not->toBe(CacheKeys::for('same-key', 'scope-b')['response']);
});
