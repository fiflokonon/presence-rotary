<?php

use App\Http\Middleware\AuthenticateSessionForGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.session.guard' => AuthenticateSessionForGuard::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
            ? route('super-admin.login')
            : route('admin.login'));

        $middleware->redirectUsersTo(fn (Request $request) => $request->getHost() === config('tenancy.super_admin_host')
            ? route('super-admin.dashboard')
            : route('admin.sessions.index'));

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
