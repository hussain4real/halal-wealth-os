<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordRecovery
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('password.email', 'password.update')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'password-recovery');
        }

        return $next($request);
    }
}
