<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard browser security headers on every page:
 *  - X-Frame-Options: no other site may show our pages in a frame (stops clickjacking, e.g.
 *    tricking a signed-in user into clicking "Buy credits" or "Generate" under a disguise).
 *  - X-Content-Type-Options: browsers must not guess file types (stops content sniffing).
 *  - Referrer-Policy: other sites only learn our origin, never full URLs.
 *  - Permissions-Policy: the app never needs camera, microphone or location.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
