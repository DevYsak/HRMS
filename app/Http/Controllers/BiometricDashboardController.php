<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Standalone "v2" attendance dashboard — mirrors the external Python engine's
 * dashboard design but is served inside HRMS (auth-gated) and reads the engine's
 * live data through a same-origin proxy (so the browser never hits the engine's
 * http://host:5000 directly, avoiding mixed-content/CORS on the https HRMS site).
 *
 * Read-only and fully separate from the existing attendance pages.
 */
class BiometricDashboardController extends Controller
{
    /** Engine API paths the proxy is allowed to forward (no open proxy / SSRF). */
    private const ALLOWED = [
        'dashboard', 'calendar', 'device-status', 'pull-logs', 'set-time',
    ];

    /** The full-page dashboard (its own HTML; not the HRMS app layout). */
    public function index(): View
    {
        return view('attendance.biometric-dashboard');
    }

    /**
     * Same-origin proxy to the engine's read endpoints. Only the allowlisted
     * paths (plus employee/{id}) are forwarded, to the configured engine URL.
     */
    public function proxy(Request $request, string $path): JsonResponse
    {
        if (! in_array($path, self::ALLOWED, true) && ! preg_match('#^employee/\d+$#', $path)) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $baseUrl = rtrim((string) config('services.biometric_app.url'), '/');

        if ($baseUrl === '') {
            return response()->json(['error' => 'Attendance engine URL is not configured.'], 503);
        }

        try {
            $response = Http::timeout((int) config('services.biometric_app.timeout', 10))
                ->when(! config('services.biometric_app.verify_ssl', true), fn ($r) => $r->withoutVerifying())
                ->acceptJson()
                ->get("{$baseUrl}/api/{$path}", $request->query());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Attendance engine unreachable.'], 502);
        }

        return response()->json($response->json() ?? [], $response->status());
    }
}
