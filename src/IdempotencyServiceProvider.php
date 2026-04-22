<?php

declare(strict_types=1);

namespace Infinitypaul\Idempotency;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Infinitypaul\Idempotency\Console\FlushCommand;
use Infinitypaul\Idempotency\Contracts\KeyValidator;
use Infinitypaul\Idempotency\Contracts\PayloadHasher;
use Infinitypaul\Idempotency\Contracts\ResponseSerializer;
use Infinitypaul\Idempotency\Contracts\ScopeResolver;
use Infinitypaul\Idempotency\Middleware\EnsureIdempotency;
use Infinitypaul\Idempotency\Support\DefaultKeyValidator;
use Infinitypaul\Idempotency\Support\DefaultPayloadHasher;
use Infinitypaul\Idempotency\Support\DefaultResponseSerializer;
use Infinitypaul\Idempotency\Support\DefaultScopeResolver;
use Infinitypaul\Idempotency\Support\Scope;
use Infinitypaul\Idempotency\Telemetry\TelemetryManager;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/idempotency.php', 'idempotency');

        $this->app->singleton(
            TelemetryManager::class,
            static fn (Application $app): TelemetryManager => new TelemetryManager($app),
        );

        $this->app->singleton(IdempotencyManager::class);

        $this->app->bind(KeyValidator::class, static function (Application $app): KeyValidator {
            /** @var Config $config */
            $config = $app->make('config');
            $pattern = $config->get('idempotency.validation.pattern', 'uuid');
            $pattern = is_string($pattern) ? $pattern : 'uuid';

            if (class_exists($pattern)) {
                $instance = $app->make($pattern);
                if ($instance instanceof KeyValidator) {
                    return $instance;
                }
            }

            $maxLength = $config->get('idempotency.validation.max_key_length', 255);
            $maxLength = is_int($maxLength) ? $maxLength : 255;

            return new DefaultKeyValidator($pattern, $maxLength);
        });

        $this->app->bind(PayloadHasher::class, static function (Application $app): PayloadHasher {
            /** @var Config $config */
            $config = $app->make('config');

            $algo = $config->get('idempotency.payload.algo', 'sha256');
            $sortKeys = $config->get('idempotency.payload.sort_keys', true);
            $ignore = $config->get('idempotency.payload.ignore', []);
            $includeFiles = $config->get('idempotency.payload.include_files', true);

            return new DefaultPayloadHasher(
                is_string($algo) ? $algo : 'sha256',
                is_bool($sortKeys) ? $sortKeys : true,
                is_array($ignore) ? array_values(array_filter($ignore, 'is_string')) : [],
                is_bool($includeFiles) ? $includeFiles : true,
            );
        });

        $this->app->bind(ScopeResolver::class, static function (Application $app): ScopeResolver {
            /** @var Config $config */
            $config = $app->make('config');
            $scope = $config->get('idempotency.scope', Scope::USER_ROUTE->value);
            $scope = is_string($scope) ? $scope : Scope::USER_ROUTE->value;

            if (class_exists($scope)) {
                $instance = $app->make($scope);
                if ($instance instanceof ScopeResolver) {
                    return $instance;
                }
            }

            return new DefaultScopeResolver(Scope::tryFrom($scope) ?? Scope::USER_ROUTE);
        });

        $this->app->bind(ResponseSerializer::class, DefaultResponseSerializer::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/idempotency.php' => config_path('idempotency.php'),
        ], 'idempotency-config');

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class]);
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('idempotent', EnsureIdempotency::class);
    }

    /** @return array<int,string> */
    public function provides(): array
    {
        return [
            TelemetryManager::class,
            IdempotencyManager::class,
            KeyValidator::class,
            PayloadHasher::class,
            ScopeResolver::class,
            ResponseSerializer::class,
        ];
    }
}
