<?php

namespace App\Services\Biometric;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the biometric-app attendance API.
 *
 * The biometric-app is the single source of truth for attendance.
 * This service fetches pre-calculated sessions; HRMS never re-calculates.
 */
class BiometricApiService
{
    private string $baseUrl;

    private ?string $apiKey;

    private int $timeout;

    private bool $verifySsl;

    public function __construct()
    {
        $cfg = config('services.biometric_app');
        $this->baseUrl = rtrim($cfg['url'] ?? 'http://localhost:8080', '/');
        $this->apiKey = $cfg['key'] ?? null;
        $this->timeout = $cfg['timeout'] ?? 10;
        $this->verifySsl = $cfg['verify_ssl'] ?? true;
    }

    /**
     * Attendance for all active employees for today.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function forToday(): array
    {
        return $this->get('/api/attendance/today');
    }

    /**
     * Attendance for all active employees on a specific date.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function forDate(string $date): array
    {
        return $this->get("/api/attendance/date/{$date}");
    }

    /**
     * Attendance history for a single employee.
     *
     * @param  string  $code  employee_code (biometric PIN)
     * @param  string|null  $from  Y-m-d  (default: last 30 days)
     * @param  string|null  $to  Y-m-d  (default: today)
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function forEmployee(string $code, ?string $from = null, ?string $to = null): array
    {
        $query = array_filter(['from' => $from, 'to' => $to]);

        return $this->get("/api/attendance/employee/{$code}", $query);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function get(string $path, array $query = []): array
    {
        try {
            $req = Http::timeout($this->timeout)
                ->acceptJson()
                ->when(! $this->verifySsl, fn ($r) => $r->withoutVerifying());

            if ($this->apiKey) {
                $req = $req->withHeaders(['X-Api-Key' => $this->apiKey]);
            }

            $response = $req->get($this->baseUrl.$path, $query);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json(), 'error' => null];
            }

            $error = $response->json('error') ?? "HTTP {$response->status()}";
            Log::warning("BiometricApiService: {$path} returned {$response->status()}: {$error}");

            return ['success' => false, 'data' => null, 'error' => $error];

        } catch (ConnectionException $e) {
            Log::error("BiometricApiService: cannot reach biometric-app at {$this->baseUrl}{$path} — {$e->getMessage()}");

            return ['success' => false, 'data' => null, 'error' => 'Biometric server unreachable.'];

        } catch (\Throwable $e) {
            Log::error("BiometricApiService: unexpected error for {$path} — {$e->getMessage()}");

            return ['success' => false, 'data' => null, 'error' => 'Unexpected error fetching attendance.'];
        }
    }
}
