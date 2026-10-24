<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CopilotPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('copilot', 'copilot/*')) {
            return $next($request);
        }
        if ($request->isMethod('POST')) {
            // Before auth/session/validation: even ordinary forms cannot flash
            // untrusted documents or question text into file-backed sessions.
            $request->headers->set('Accept', 'application/json');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
