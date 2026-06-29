<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the /api/v1/* attendance-sync endpoints with a shared secret.
 *
 * The Python attendance engine must send the key as either:
 *   X-Api-Key: <key>            (preferred, matches BiometricApiService convention)
 *   Authorization: Bearer <key> (fallback)
 *
 * Returns 503 when no key is configured server-side so a missing env var fails
 * closed (deny) rather than silently allowing every request.
 */
class VerifyBiometricApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('biometric.api.key');

        if (empty($expected)) {
            return response()->json([
                'message' => 'Attendance sync API is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $provided = $request->header('X-Api-Key') ?: $request->bearerToken();

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid or missing API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
