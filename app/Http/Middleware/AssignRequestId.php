<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One correlation id per request. It is added to every log entry (Laravel
 * Context), stored on audit rows, returned as the X-Request-Id header and
 * printed on the error page — so a user can quote it and support can find
 * the exact log lines, without the page ever showing what went wrong.
 *
 * An incoming X-Request-Id (from a proxy) is kept when it is a sane token.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The current request's id (null outside a request, e.g. in a queued job). */
    public static function current(): ?string
    {
        return Context::get('request_id');
    }
}
