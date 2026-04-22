<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Middleware;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Infinitypaul\Idempotency\Contracts\KeyValidator;
use Infinitypaul\Idempotency\Contracts\PayloadHasher;
use Infinitypaul\Idempotency\Contracts\ResponseSerializer;
use Infinitypaul\Idempotency\Contracts\ScopeResolver;
use Infinitypaul\Idempotency\Contracts\TelemetryDriver;
use Infinitypaul\Idempotency\Logging\AlertDispatcher;
use Infinitypaul\Idempotency\Logging\EventType;
use Infinitypaul\Idempotency\Support\ConfigAccess;
use Infinitypaul\Idempotency\Support\DefaultResponseSerializer;
use Infinitypaul\Idempotency\Telemetry\TelemetryManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureIdempotency
{
    use ConfigAccess;

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cacheFactory,
        private readonly KeyValidator $keyValidator,
        private readonly PayloadHasher $payloadHasher,
        private readonly ScopeResolver $scopeResolver,
        private readonly ResponseSerializer $responseSerializer,
        private readonly TelemetryManager $telemetryManager,
        private readonly AlertDispatcher $alerts,
    ) {}

    /**
     * Accepts middleware parameters in key=value form:
     *   ':optional'           → missing key is allowed, request passes through
     *   ':ttl=600'            → override ttl (seconds) for this route
     *   ':scope=user_route'   → override scope
     */
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        if (! $this->configBool($this->config, 'idempotency.enabled')) {
            return $next($request);
        }

        /** @var list<string> $params */
        $opts = $this->parseParams($params);
        $startTime = microtime(true);
        $telemetry = $this->telemetryManager->driver();
        $segment = $telemetry->startSegment('idempotency', 'Idempotency Middleware');
        $telemetry->recordMetric('requests.total');
        $telemetry->addSegmentContext($segment, 'method', $request->method());
        $telemetry->addSegmentContext($segment, 'path', $request->path());

        if (! $this->methodApplies($request)) {
            $telemetry->addSegmentContext($segment, 'skipped', 'method_not_applicable');
            $telemetry->recordMetric('requests.skipped');
            $telemetry->endSegment($segment);

            return $next($request);
        }

        $headerName = $this->configStr($this->config, 'idempotency.header_name', 'Idempotency-Key');
        $idempotencyKey = $request->header($headerName);

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            if ($opts['optional']) {
                $telemetry->addSegmentContext($segment, 'skipped', 'optional_missing_key');
                $telemetry->endSegment($segment);

                return $next($request);
            }

            return $this->reject($telemetry, $segment, 'errors.missing_key', EventType::MISSING_KEY, 400,
                'Missing '.$headerName.' header');
        }

        if (! $this->keyValidator->isValid($idempotencyKey)) {
            return $this->reject($telemetry, $segment, 'errors.invalid_key', EventType::INVALID_KEY_FORMAT, 400,
                'Invalid '.$headerName.' format');
        }

        $scopePrefix = $this->scopeResolver->resolve($request);
        $keys = $this->keysFor($idempotencyKey, $scopePrefix);
        $ttl = $opts['ttl'] ?? $this->configInt($this->config, 'idempotency.ttl', 86_400);
        $store = $this->store();

        $cached = $store->get($keys['response']);
        if ($cached !== null) {
            return $this->handleCached($cached, $keys, $idempotencyKey, $request, $ttl, $startTime,
                $telemetry, $segment);
        }

        return $this->handleNew($keys, $idempotencyKey, $request, $next, $ttl, $telemetry, $segment);
    }

    /**
     * @param  list<string>  $params
     * @return array{optional:bool, ttl:?int, scope:?string}
     */
    private function parseParams(array $params): array
    {
        $opts = ['optional' => false, 'ttl' => null, 'scope' => null];

        foreach ($params as $raw) {
            if ($raw === 'optional') {
                $opts['optional'] = true;

                continue;
            }

            if (! str_contains($raw, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $raw, 2);

            if ($k === 'ttl') {
                $opts['ttl'] = (int) $v;
            } elseif ($k === 'scope') {
                $opts['scope'] = $v;
            }
        }

        return $opts;
    }

    private function methodApplies(Request $request): bool
    {
        $methods = $this->configArr($this->config, 'idempotency.methods', []);
        $upper = array_map(static fn ($m): string => is_string($m) ? strtoupper($m) : '', $methods);

        return in_array(strtoupper($request->method()), $upper, true);
    }

    private function reject(
        TelemetryDriver $telemetry,
        mixed $segment,
        string $metric,
        EventType $eventType,
        int $status,
        string $message,
    ): JsonResponse {
        $telemetry->recordMetric($metric);
        $telemetry->addSegmentContext($segment, 'error', $eventType->value);
        $telemetry->endSegment($segment);

        return new JsonResponse(['error' => $message], $status);
    }

    /** @return array{response:string, processing:string, metadata:string, lock:string, payload_hash:string} */
    private function keysFor(string $key, string $scope): array
    {
        $prefix = 'idempotency:'.($scope === '' ? '' : $scope.':').$key;

        return [
            'response' => $prefix.':response',
            'processing' => $prefix.':processing',
            'metadata' => $prefix.':metadata',
            'lock' => 'idempotency_lock:'.($scope === '' ? '' : $scope.':').$key,
            'payload_hash' => $prefix.':payload_hash',
        ];
    }

    private function store(): CacheRepository
    {
        $name = $this->config->get('idempotency.cache_store');
        $store = $this->cacheFactory->store(is_string($name) ? $name : null);

        if (! $store instanceof CacheRepository) {
            throw new \RuntimeException(
                'Idempotency requires a cache store backed by Illuminate\\Cache\\Repository '.
                '(needs lock support). Got: '.$store::class
            );
        }

        return $store;
    }

    /**
     * @param  array{response:string, processing:string, metadata:string, lock:string, payload_hash:string}  $keys
     */
    private function handleCached(
        mixed $cached,
        array $keys,
        string $idempotencyKey,
        Request $request,
        int $ttl,
        float $startTime,
        TelemetryDriver $telemetry,
        mixed $segment,
    ): Response {
        $store = $this->store();
        $storedHash = $store->get($keys['payload_hash']);
        $currentHash = $this->payloadHasher->hash($request);

        if (is_string($storedHash) && ! hash_equals($storedHash, $currentHash)) {
            $telemetry->recordMetric('errors.payload_mismatch');
            $telemetry->addSegmentContext($segment, 'error', 'payload_mismatch');
            $telemetry->endSegment($segment);

            $this->alerts->dispatch(EventType::PAYLOAD_MISMATCH, [
                'idempotency_key' => $idempotencyKey,
                'endpoint' => $request->path(),
            ]);

            return new JsonResponse([
                'error' => 'Idempotency key reused with a different request payload',
            ], 422);
        }

        $telemetry->recordMetric('cache.hit');
        $metadata = $this->touchMetadata($keys['metadata'], $ttl);
        $this->maybeAlertThreshold($metadata, $idempotencyKey, $request);

        $duration = (microtime(true) - $startTime) * 1000;
        $telemetry->recordTiming('duplicate_handling_time', $duration);
        $telemetry->addSegmentContext($segment, 'status', 'duplicate');
        $telemetry->addSegmentContext($segment, 'hit_count', $metadata['hit_count']);
        $telemetry->endSegment($segment);

        return $this->rehydrate($cached, $idempotencyKey, 'Repeated');
    }

    /**
     * @param  array{response:string, processing:string, metadata:string, lock:string, payload_hash:string}  $keys
     */
    private function handleNew(
        array $keys,
        string $idempotencyKey,
        Request $request,
        Closure $next,
        int $ttl,
        TelemetryDriver $telemetry,
        mixed $segment,
    ): Response {
        $store = $this->store();
        $lockTimeout = $this->configInt($this->config, 'idempotency.lock.timeout', 30);
        $lockWait = $this->configInt($this->config, 'idempotency.lock.wait', 5);

        /** @var Lock $lock */
        $lock = $store->lock($keys['lock'], $lockTimeout);
        $lockAcquired = false;
        $lockStart = microtime(true);

        try {
            $lockAcquired = $lock->block($lockWait);
            $telemetry->recordTiming('lock_acquisition_time', (microtime(true) - $lockStart) * 1000);

            $cached = $store->get($keys['response']);
            if ($cached !== null) {
                $telemetry->recordMetric('cache.late_hit');
                $telemetry->addSegmentContext($segment, 'status', 'late_duplicate');
                $telemetry->endSegment($segment);

                return $this->rehydrate($cached, $idempotencyKey, 'Repeated');
            }

            if (! $lockAcquired) {
                if ($store->has($keys['processing'])) {
                    $this->alerts->dispatch(EventType::CONCURRENT_CONFLICT, [
                        'idempotency_key' => $idempotencyKey,
                        'endpoint' => $request->path(),
                    ]);
                    $telemetry->recordMetric('responses.concurrent_conflict');
                    $telemetry->endSegment($segment);

                    return new JsonResponse([
                        'error' => 'A request with this idempotency key is currently being processed',
                    ], 409);
                }

                $this->alerts->dispatch(EventType::LOCK_INCONSISTENCY, [
                    'idempotency_key' => $idempotencyKey,
                    'endpoint' => $request->path(),
                ]);
                $telemetry->recordMetric('errors.lock_inconsistency');
                $telemetry->endSegment($segment);

                return new JsonResponse([
                    'error' => 'Could not acquire idempotency lock. Please retry.',
                ], 503);
            }

            return $this->process($keys, $idempotencyKey, $request, $next, $ttl, $telemetry, $segment);
        } catch (Throwable $e) {
            $this->alerts->dispatch(EventType::EXCEPTION_THROWN, [
                'idempotency_key' => $idempotencyKey,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        } finally {
            $store->forget($keys['processing']);
            if ($lockAcquired) {
                $lock->release();
            }
        }
    }

    /**
     * @param  array{response:string, processing:string, metadata:string, lock:string, payload_hash:string}  $keys
     */
    private function process(
        array $keys,
        string $idempotencyKey,
        Request $request,
        Closure $next,
        int $ttl,
        TelemetryDriver $telemetry,
        mixed $segment,
    ): Response {
        $store = $this->store();
        $processingTtl = $this->configInt($this->config, 'idempotency.processing_ttl', 300);

        $store->put($keys['processing'], true, $processingTtl);
        $store->put($keys['payload_hash'], $this->payloadHasher->hash($request), $ttl);

        $user = $request->user();
        $userId = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        $store->put($keys['metadata'], [
            'created_at' => time(),
            'hit_count' => 0,
            'endpoint' => $request->path(),
            'user_id' => $userId,
            'client_ip' => $request->ip(),
        ], $ttl);

        $telemetry->recordMetric('requests.original');
        $processingStart = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $processingTime = (microtime(true) - $processingStart) * 1000;
        $telemetry->recordTiming('request_processing_time', $processingTime);

        $this->addHeaders($response, $idempotencyKey, 'Original');

        if ($this->shouldCache($response)) {
            $this->cacheResponse($store, $keys['response'], $response, $request, $ttl, $telemetry);
        } else {
            $telemetry->recordMetric('responses.not_cached');
        }

        $telemetry->addSegmentContext($segment, 'status', 'original');
        $telemetry->addSegmentContext($segment, 'status_code', $response->getStatusCode());
        $telemetry->addSegmentContext($segment, 'processing_time_ms', $processingTime);
        $telemetry->endSegment($segment);

        return $response;
    }

    private function shouldCache(Response $response): bool
    {
        if (! DefaultResponseSerializer::isCacheable($response)) {
            return false;
        }

        $status = $response->getStatusCode();
        $min = $this->configInt($this->config, 'idempotency.cacheable_status.min', 200);
        $max = $this->configInt($this->config, 'idempotency.cacheable_status.max', 499);
        $exclude = $this->configArr($this->config, 'idempotency.cacheable_status.exclude', []);

        return $status >= $min && $status <= $max && ! in_array($status, $exclude, true);
    }

    private function cacheResponse(
        CacheRepository $store,
        string $cacheKey,
        Response $response,
        Request $request,
        int $ttl,
        TelemetryDriver $telemetry,
    ): void {
        try {
            $serialized = $this->responseSerializer->serialize($response);
            $store->put($cacheKey, $serialized, $ttl);

            $size = strlen((string) $response->getContent());
            $telemetry->recordSize('response_size', $size);

            $warnAt = $this->configInt($this->config, 'idempotency.size_warning', 1_048_576);
            if ($warnAt > 0 && $size > $warnAt) {
                $this->alerts->dispatch(EventType::SIZE_WARNING, [
                    'size_bytes' => $size,
                    'endpoint' => $request->path(),
                ]);
            }
        } catch (Throwable $e) {
            $telemetry->recordMetric('errors.cache_failed');
            $this->alerts->dispatch(EventType::EXCEPTION_THROWN, [
                'message' => 'Failed to cache response: '.$e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /** @return array{created_at:int, hit_count:int, last_hit_at?:int} */
    private function touchMetadata(string $metadataKey, int $ttl): array
    {
        $store = $this->store();
        $raw = $store->get($metadataKey);

        /** @var array{created_at:int, hit_count:int, last_hit_at?:int} $metadata */
        $metadata = is_array($raw)
            ? ($raw + ['created_at' => time(), 'hit_count' => 0])
            : ['created_at' => time(), 'hit_count' => 0];

        $metadata['hit_count']++;
        $metadata['last_hit_at'] = time();

        $store->put($metadataKey, $metadata, $ttl);

        return $metadata;
    }

    /** @param array{created_at:int, hit_count:int, last_hit_at?:int} $metadata */
    private function maybeAlertThreshold(array $metadata, string $idempotencyKey, Request $request): void
    {
        $threshold = $this->configInt($this->config, 'idempotency.alerts.hit_threshold', 5);

        if ($metadata['hit_count'] < $threshold) {
            return;
        }

        $this->alerts->dispatch(EventType::RESPONSE_DUPLICATE, [
            'idempotency_key' => $idempotencyKey,
            'hit_count' => $metadata['hit_count'],
            'endpoint' => $request->path(),
            'method' => $request->method(),
        ]);
    }

    private function rehydrate(mixed $cached, string $idempotencyKey, string $status): Response
    {
        if (is_array($cached)) {
            /** @var array<string,mixed> $cached */
            $response = $this->responseSerializer->deserialize($cached);
        } elseif ($cached instanceof Response) {
            $response = $cached;
        } else {
            // Backwards-compat with any pre-v2 entries still in cache.
            $response = new \Illuminate\Http\Response(
                is_scalar($cached) ? (string) $cached : ''
            );
        }

        $this->addHeaders($response, $idempotencyKey, $status);

        return $response;
    }

    private function addHeaders(Response $response, string $idempotencyKey, string $status): void
    {
        $headerName = $this->configStr($this->config, 'idempotency.header_name', 'Idempotency-Key');
        $response->headers->set($headerName, $idempotencyKey);
        $response->headers->set('Idempotency-Status', $status);
    }
}
