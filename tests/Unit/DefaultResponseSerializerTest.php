<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\DefaultResponseSerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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

it('strips sensitive response headers by default', function () {
    $s = new DefaultResponseSerializer;
    $original = (new Response('hello', 201))
        ->header('Set-Cookie', 'session=secret')
        ->header('Authorization', 'Bearer secret')
        ->header('X-Custom', 'yes');

    $payload = $s->serialize($original);

    expect($payload['headers'])->not->toHaveKey('set-cookie')
        ->and($payload['headers'])->not->toHaveKey('authorization')
        ->and($payload['headers'])->toHaveKey('x-custom');
});

it('strips configured response headers', function () {
    $s = new DefaultResponseSerializer(['X-Internal-Token']);
    $original = (new Response('hello', 201))
        ->header('X-Internal-Token', 'secret')
        ->header('X-Custom', 'yes');

    $payload = $s->serialize($original);

    expect($payload['headers'])->not->toHaveKey('x-internal-token')
        ->and($payload['headers'])->toHaveKey('x-custom');
});

it('marks streamed responses as not cacheable', function () {
    $streamed = new StreamedResponse(fn () => print ('x'));

    expect(DefaultResponseSerializer::isCacheable($streamed))->toBeFalse();
});
