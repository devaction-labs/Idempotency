<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Logging;

enum EventType: string
{
    case LOCK_INCONSISTENCY = 'lock.inconsistency';
    case SIZE_WARNING = 'size.warning';
    case CONCURRENT_CONFLICT = 'lock.concurrent_conflict';
    case CACHE_HIT = 'cache.hit';
    case CACHE_LATE_HIT = 'cache.late_hit';
    case RESPONSE_DUPLICATE = 'response.duplicate';
    case RESPONSE_ORIGINAL = 'response.original';
    case RESPONSE_ERROR = 'response.error';
    case PAYLOAD_MISMATCH = 'payload.mismatch';
    case EXCEPTION_THROWN = 'exception.thrown';
    case MISSING_KEY = 'header.missing_key';
    case INVALID_KEY_FORMAT = 'header.invalid_key';
}
