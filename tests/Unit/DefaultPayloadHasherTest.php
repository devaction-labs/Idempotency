<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\DefaultPayloadHasher;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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

it('hashes multipart payloads without encoding uploaded files directly', function () {
    $hasher = new DefaultPayloadHasher;

    $request = Request::create(
        '/x',
        'POST',
        ['amount' => 10],
        [],
        ['receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'alpha')]
    );

    expect($hasher->hash($request))->toBeString();
});

it('includes file fingerprints when configured to include files', function () {
    $hasher = new DefaultPayloadHasher(includeFiles: true);

    $a = Request::create('/x', 'POST', ['amount' => 10], [], [
        'receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'alpha'),
    ]);
    $b = Request::create('/x', 'POST', ['amount' => 10], [], [
        'receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'beta'),
    ]);

    expect($hasher->hash($a))->not->toBe($hasher->hash($b));
});

it('excludes uploaded files when configured not to include files', function () {
    $hasher = new DefaultPayloadHasher(includeFiles: false);

    $a = Request::create('/x', 'POST', ['amount' => 10], [], [
        'receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'alpha'),
    ]);
    $b = Request::create('/x', 'POST', ['amount' => 10], [], [
        'receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'beta'),
    ]);

    expect($hasher->hash($a))->toBe($hasher->hash($b));
});
