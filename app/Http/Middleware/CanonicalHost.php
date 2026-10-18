<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Microsoft redirects back to the host in APP_URL. Browser cookies are per host, so a session
 * started on 127.0.0.1 is invisible on localhost. Send GET requests to the APP_URL host.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($canonical && $request->isMethod('GET') && strcasecmp($request->getHost(), $canonical) !== 0
            && in_array($request->getHost(), ['127.0.0.1', 'localhost', '[::1]', '::1'], true)) {
            return redirect()->to(rtrim((string) config('app.url'), '/').$request->getRequestUri());
        }

        return $next($request);
    }
}
