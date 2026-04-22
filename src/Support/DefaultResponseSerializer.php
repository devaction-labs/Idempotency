<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Infinitypaul\Idempotency\Contracts\ResponseSerializer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DefaultResponseSerializer implements ResponseSerializer
{
    private const STRIP_HEADERS = ['date', 'x-idempotency-replay-of'];

    /**
     * @return array{class:class-string,status:int,content:string,headers:array<string,list<string|null>>}
     */
    public function serialize(Response $response): array
    {
        /** @var array<string,list<string|null>> $headers */
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (in_array(strtolower($name), self::STRIP_HEADERS, true)) {
                continue;
            }
            $headers[$name] = $values;
        }

        return [
            'class' => $response::class,
            'status' => $response->getStatusCode(),
            'content' => (string) $response->getContent(),
            'headers' => $headers,
        ];
    }

    /** @param array<string,mixed> $payload */
    public function deserialize(array $payload): Response
    {
        $class = is_string($payload['class'] ?? null) ? $payload['class'] : LaravelResponse::class;
        $status = is_int($payload['status'] ?? null) ? $payload['status'] : 200;
        $content = is_string($payload['content'] ?? null) ? $payload['content'] : '';
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        $response = is_a($class, JsonResponse::class, true)
            ? new JsonResponse($content, $status, [], 0)
            : new LaravelResponse($content, $status);

        if ($response instanceof JsonResponse) {
            $response->setContent($content);
        }

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            if ($values === null || is_string($values)) {
                $response->headers->set($name, $values);

                continue;
            }

            if (is_array($values)) {
                /** @var list<string> $normalized */
                $normalized = array_values(array_filter($values, 'is_string'));
                $response->headers->set($name, $normalized);
            }
        }

        return $response;
    }

    public static function isCacheable(Response $response): bool
    {
        return ! ($response instanceof StreamedResponse)
            && ! ($response instanceof BinaryFileResponse)
            && $response->getContent() !== false;
    }
}
