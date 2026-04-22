<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Support;

enum Scope: string
{
    case GLOBAL = 'global';
    case USER = 'user';
    case ROUTE = 'route';
    case IP = 'ip';
    case USER_ROUTE = 'user_route';
}
