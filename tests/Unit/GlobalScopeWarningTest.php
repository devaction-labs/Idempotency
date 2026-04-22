<?php

declare(strict_types=1);

use DevactionLabs\Idempotency\Support\DefaultScopeResolver;
use DevactionLabs\Idempotency\Support\Scope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    DefaultScopeResolver::resetWarningState();
});

it('warns once when scope is global and the request is authenticated', function () {
    Log::spy();

    $request = Request::create('/charge', 'POST');
    $request->setUserResolver(fn () => new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier()
        {
            return 42;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    });

    $resolver = new DefaultScopeResolver(Scope::GLOBAL);

    $resolver->resolve($request);
    $resolver->resolve($request);
    $resolver->resolve($request);

    Log::shouldHaveReceived('warning')->once();
});

it('does not warn when scope is global but the request is unauthenticated', function () {
    Log::spy();

    $request = Request::create('/charge', 'POST');
    $resolver = new DefaultScopeResolver(Scope::GLOBAL);

    $resolver->resolve($request);

    Log::shouldNotHaveReceived('warning');
});

it('does not warn when scope is not global', function () {
    Log::spy();

    $request = Request::create('/charge', 'POST');
    $request->setUserResolver(fn () => new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier()
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    });

    $resolver = new DefaultScopeResolver(Scope::USER_ROUTE);

    $resolver->resolve($request);

    Log::shouldNotHaveReceived('warning');
});
