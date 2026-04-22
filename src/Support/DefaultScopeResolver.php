<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Infinitypaul\Idempotency\Contracts\ScopeResolver;

final class DefaultScopeResolver implements ScopeResolver
{
    public function __construct(private readonly Scope $scope) {}

    public function resolve(Request $request): string
    {
        return match ($this->scope) {
            Scope::GLOBAL => '',
            Scope::USER => $this->userId($request),
            Scope::ROUTE => $this->routeId($request),
            Scope::IP => (string) $request->ip(),
            Scope::USER_ROUTE => trim($this->userId($request).':'.$this->routeId($request), ':'),
        };
    }

    private function userId(Request $request): string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return 'guest';
        }

        $id = $user->getAuthIdentifier();

        if (is_int($id) || is_string($id)) {
            return 'u'.$id;
        }

        return 'guest';
    }

    private function routeId(Request $request): string
    {
        $route = $request->route();

        if (! is_object($route)) {
            return $request->method().' '.$request->path();
        }

        $name = method_exists($route, 'getName') ? $route->getName() : null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $uri = method_exists($route, 'uri') ? $route->uri() : $request->path();

        return $request->method().' '.(is_string($uri) ? $uri : $request->path());
    }
}
