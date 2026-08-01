<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DiagnosticLogger
{
    public function handle(Request $request, Closure $next, string $tag): Response
    {
        Log::info("diag.{$tag}", ['method' => $request->method(), 'path' => $request->path()]);

        return $next($request);
    }
}
