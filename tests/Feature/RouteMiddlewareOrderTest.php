<?php

use App\Http\Middleware\AuthenticateSessionForGuard;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\SortedMiddleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

it('sorts ResolveTenant before the authentication check on the admin panel middleware stack', function () {
    // Router::$middlewarePriority is only synced from the HTTP Kernel once
    // a real request has been through it — an artisan/tinker context (or a
    // test that never issues a request) leaves it empty, which would make
    // this assertion pass vacuously regardless of whether the fix exists.
    $this->get('/up');

    // Mirrors the real, fully-expanded middleware stack for a protected
    // admin route (web group members + route-specific middleware), as
    // registered in production — independent of any dev-only tooling
    // (e.g. Laravel Boost) that can alter the local "web" group makeup
    // and mask this ordering bug in local testing.
    $middleware = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        SubstituteBindings::class,
        ResolveTenant::class,
        Authenticate::class.':web,super_admin',
        AuthenticateSessionForGuard::class.':web',
    ];

    $sorted = (new SortedMiddleware(app('router')->middlewarePriority, $middleware))->all();

    $resolveTenantPosition = array_search(ResolveTenant::class, $sorted, true);
    $authenticatePosition = collect($sorted)->search(
        fn ($name) => str_starts_with($name, Authenticate::class)
    );

    expect($resolveTenantPosition)->not->toBeFalse()
        ->and($authenticatePosition)->not->toBeFalse()
        ->and($resolveTenantPosition)->toBeLessThan($authenticatePosition);
});
