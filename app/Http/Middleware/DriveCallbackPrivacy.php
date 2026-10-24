<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Runs before session/auth/throttle, which can respond without reaching the callback controller. */
final class DriveCallbackPrivacy
{
    private const string ATTRIBUTE = 'halalos.drive.callback';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('drive/callback')) {
            return $next($request);
        }
        $state = $request->query('state');
        $code = $request->query('code');
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
        $request->headers->set('Accept', 'application/json');
        if (is_string($state) && preg_match('/^[a-f0-9]{64}$/D', $state) && is_string($code) && preg_match('/^[\x20-\x7e]{1,2048}$/D', $code)) {
            $request->attributes->set(self::ATTRIBUTE, Crypt::encryptString(json_encode(['state' => $state, 'code' => $code], JSON_THROW_ON_ERROR)));
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** @return array{state:?string,code:?string} */
    public static function payload(Request $request): array
    {
        $cipher = $request->attributes->get(self::ATTRIBUTE);
        try {
            $data = is_string($cipher) ? json_decode(Crypt::decryptString($cipher), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            $data = null;
        }

        return ['state' => is_array($data) && is_string($data['state'] ?? null) ? $data['state'] : null,
            'code' => is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : null];
    }
}
