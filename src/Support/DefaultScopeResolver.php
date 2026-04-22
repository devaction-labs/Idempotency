<?php

declare(strict_types=1);

namespace DevactionLabs\Idempotency\Support;

use DevactionLabs\Idempotency\Contracts\ScopeResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class DefaultScopeResolver implements ScopeResolver
{
    /**
     * We only warn once per process — otherwise misconfigured apps spam their
     * own logs every request. Octane workers re-emit after each reload, which
     * is the desired behaviour.
     */
    private static bool $globalCollisionWarned = false;

    public function __construct(private readonly Scope $scope) {}

    public function resolve(Request $request): string
    {
        $user = $request->user();
        $authenticated = $user instanceof Authenticatable;

        if ($this->scope === Scope::GLOBAL && $authenticated && ! self::$globalCollisionWarned) {
            self::$globalCollisionWarned = true;
            Log::warning(
                'Idempotency scope is "global" but requests are authenticated. '
                .'Keys will collide across users — set IDEMPOTENCY_SCOPE to '
                .'"user_route" (or another user-aware scope) in production.',
                ['authenticated_user_id' => $user->getAuthIdentifier()],
            );
        }

        return match ($this->scope) {
            Scope::GLOBAL => '',
            Scope::USER => $this->userId($user),
            Scope::ROUTE => $this->routeId($request),
            Scope::IP => (string) $request->ip(),
            Scope::USER_ROUTE => trim($this->userId($user).':'.$this->routeId($request), ':'),
        };
    }

    /** @internal For testing only. */
    public static function resetWarningState(): void
    {
        self::$globalCollisionWarned = false;
    }

    private function userId(?Authenticatable $user): string
    {
        if ($user === null) {
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
