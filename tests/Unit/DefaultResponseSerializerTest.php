<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Infinitypaul\Idempotency\Support\DefaultResponseSerializer;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('serializes and deserializes a Response round-trip', function () {
    $s = new DefaultResponseSerializer;
    $original = (new Response('hello', 201))->header('X-Custom', 'yes');

    $restored = $s->deserialize($s->serialize($original));

    expect($restored->getStatusCode())->toBe(201)
        ->and($restored->getContent())->toBe('hello')
        ->and($restored->headers->get('X-Custom'))->toBe('yes');
});

it('serializes JsonResponse preserving status and body', function () {
    $s = new DefaultResponseSerializer;
    $original = new JsonResponse(['ok' => true], 202);

    $restored = $s->deserialize($s->serialize($original));

    expect($restored->getStatusCode())->toBe(202)
        ->and(json_decode($restored->getContent(), true))->toBe(['ok' => true]);
});

it('marks streamed responses as not cacheable', function () {
    $streamed = new StreamedResponse(fn () => print ('x'));

    expect(DefaultResponseSerializer::isCacheable($streamed))->toBeFalse();
});
