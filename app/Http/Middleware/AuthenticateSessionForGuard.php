<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

class AuthenticateSessionForGuard
{
    public function __construct(private readonly AuthFactory $auth) {}

    public function handle(Request $request, Closure $next, string $guardName): mixed
    {
        $guard = $this->auth->guard($guardName);

        if (! $request->hasSession() || ! $guard->user()?->getAuthPassword()) {
            return $next($request);
        }

        $sessionKey = "password_hash_{$guardName}";
        $currentHash = $guard->user()->getAuthPassword();
        $storedHash = $request->session()->get($sessionKey);

        if ($storedHash === null) {
            $request->session()->put($sessionKey, $currentHash);
        } elseif (! hash_equals($currentHash, $storedHash)) {
            $guard->logout();
            $request->session()->flush();

            throw new AuthenticationException('Unauthenticated.', [$guardName]);
        }

        return tap($next($request), function () use ($request, $guard, $sessionKey): void {
            if ($guard->check()) {
                $request->session()->put($sessionKey, $guard->user()->getAuthPassword());
            }
        });
    }
}
