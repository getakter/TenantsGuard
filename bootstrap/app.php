<?php

use App\Exceptions\TenantBusyException;
use App\Http\Middleware\CanonicalHost;
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
        $middleware->web(prepend: [CanonicalHost::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A busy tenant is a normal, recoverable situation: show a friendly message.
        $exceptions->render(function (TenantBusyException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['status' => 'error', 'message' => $e->getMessage()], 409)
                : back()->withErrors(['busy' => $e->getMessage()]);
        });
    })->create();
