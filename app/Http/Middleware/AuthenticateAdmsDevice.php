<?php

namespace App\Http\Middleware;

use App\Models\BiometricDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a biometric device on the ADMS push endpoint (/iclock).
 *
 * The ADMS protocol identifies a device only by the serial number in the
 * query string, and a device cannot sign requests or add headers. A serial
 * alone is therefore never enough: the device must be registered and active,
 * push from an address it is allowed to push from, and — when HR has issued
 * one — present its token (query `token` or header X-Device-Token, for relays
 * that can add it).
 *
 * The client address is the connection's own (no trusted proxies are
 * configured), so X-Forwarded-For cannot spoof it. Rejections are logged with
 * the reason, serial and address — never the token or the punch data.
 */
class AuthenticateAdmsDevice
{
    public const DEVICE_ATTRIBUTE = 'adms_device';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        $limiterKey = 'adms:'.$ip;
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int) config('biometric.adms.requests_per_minute', 120)))) {
            return $this->reject($request, 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($limiterKey, 60);

        $serial = (string) ($request->query('SN') ?: $request->input('SN', ''));
        if ($serial === '') {
            return $this->reject($request, 'missing_serial');
        }

        $device = BiometricDevice::where('serial_number', $serial)->first();
        if ($device === null) {
            return $this->reject($request, 'unknown_device');
        }

        if (! $device->is_active) {
            return $this->reject($request, 'inactive_device', device: $device);
        }

        $allowed = $device->admsAllowedAddresses();
        if ($allowed === [] || ! IpUtils::checkIp($ip, $allowed)) {
            return $this->reject($request, 'address_not_allowed', device: $device);
        }

        if ($device->requiresAdmsToken()) {
            $token = $request->query('token') ?: $request->header('X-Device-Token');

            if (! $device->admsTokenMatches(is_string($token) ? $token : null)) {
                return $this->reject($request, 'invalid_token', device: $device);
            }
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        return $next($request);
    }

    private function reject(Request $request, string $reason, int $status = Response::HTTP_FORBIDDEN, ?BiometricDevice $device = null): Response
    {
        Log::warning('ADMS request rejected', [
            'reason' => $reason,
            'serial' => mb_substr((string) $request->query('SN', ''), 0, 50),
            'device_id' => $device?->id,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        return response('UNAUTHORIZED', $status, ['Content-Type' => 'text/plain']);
    }
}
