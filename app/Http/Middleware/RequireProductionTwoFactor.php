<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireProductionTwoFactor
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->user()?->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('security.edit')->with('status', 'Confirm two-factor authentication before personal production use.');
        }

        return $next($request);
    }
}
