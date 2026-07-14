<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NexflowApiService
 *
 * Nexcore's HTTP client for the NexBridge API hosted on Nexflow.
 * All methods are read-only. Data is cached locally to avoid
 * hammering Nexflow on every page load.
 *
 * Usage:
 *   $service = app(NexflowApiService::class);
 *   $data    = $service->getClockSummary('john@company.com', '2026-05-01', '2026-05-13');
 */
class NexflowApiService
{
    private string $baseUrl;

    private string $secret;

    private int $cacheTtl;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('nexbridge.nexflow_url', ''), '/');
        $this->secret = config('nexbridge.secret', '');
        $this->cacheTtl = config('nexbridge.cache_ttl', 900);
        $this->timeout = config('nexbridge.timeout', 10);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get daily clock-time summary (working hours + break time) for an employee.
     *
     * @param  string  $email  Employee's work email (must match Nexflow user)
     * @param  string  $from  Start date YYYY-MM-DD (defaults to start of month)
     * @param  string  $to  End date YYYY-MM-DD (defaults to today)
     * @return array|null Parsed response array, or null if unavailable
     */
    public function getClockSummary(string $email, ?string $from = null, ?string $to = null): ?array
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();

        $cacheKey = "nexbridge:clock_summary:{$email}:{$from}:{$to}";

        return $this->cachedGet($cacheKey, function () use ($email, $from, $to) {
            return $this->get("/employees/{$email}/clock-summary", [
                'from' => $from,
                'to' => $to,
            ]);
        });
    }

    /**
     * Get an employee's overtime records + summary for a period from Nexflow.
     * Mirrors the NexBridge GET /employees/{email}/ot-details contract.
     *
     * @param  string  $email  Employee's work email (must match Nexflow user)
     * @param  string|null  $from  Start date YYYY-MM-DD (defaults to start of month)
     * @param  string|null  $to  End date YYYY-MM-DD (defaults to today)
     * @param  string|null  $status  Filter: approved|pending|rejected, or null for all
     * @return array|null Parsed response array, or null if unavailable
     */
    public function getOtDetails(string $email, ?string $from = null, ?string $to = null, ?string $status = null): ?array
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();

        $params = ['from' => $from, 'to' => $to];
        if ($status !== null && $status !== '') {
            $params['status'] = $status;
        }

        $cacheKey = "nexbridge:ot_details:{$email}:{$from}:{$to}:".($status ?: 'all');

        return $this->cachedGet($cacheKey, fn () => $this->get("/employees/{$email}/ot-details", $params));
    }

    /**
     * Check if Nexflow is reachable and the credential is valid.
     * Useful for an admin "Connection Test" UI button.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        if (empty($this->baseUrl) || empty($this->secret)) {
            return ['ok' => false, 'message' => 'NexBridge not configured (check NEXBRIDGE_NEXFLOW_URL and NEXBRIDGE_SECRET in .env)'];
        }

        try {
            // Hit the clock-summary endpoint with a dummy email to test connectivity.
            // A 404 response (employee not found) is fine — it means Nexflow is up and auth passed.
            $response = Http::withToken($this->secret)
                ->timeout($this->timeout)
                ->get("{$this->baseUrl}/api/nexbridge/v1/employees/__connection_test__/clock-summary");

            if ($response->status() === 401) {
                return ['ok' => false, 'message' => 'Authentication failed — check NEXBRIDGE_SECRET matches Nexflow'];
            }

            if ($response->status() === 503) {
                return ['ok' => false, 'message' => 'Nexflow NexBridge is not configured on the server'];
            }

            // 200 or 404 both mean the bridge is alive and authenticated
            return ['ok' => true, 'message' => 'Connection successful'];

        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => "Cannot reach Nexflow: {$e->getMessage()}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Unexpected error: {$e->getMessage()}"];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a GET request, wrapping it in cache and error handling.
     */
    private function cachedGet(string $cacheKey, callable $fetcher): ?array
    {
        if ($this->cacheTtl > 0) {
            return Cache::remember($cacheKey, $this->cacheTtl, function () use ($fetcher, $cacheKey) {
                $result = $fetcher();
                // Don't cache nulls (errors) — let the next request retry Nexflow
                if ($result === null) {
                    Cache::forget($cacheKey);
                }

                return $result;
            });
        }

        return $fetcher();
    }

    /**
     * Make an authenticated GET request to Nexflow's NexBridge API.
     *
     * @param  string  $path  Relative path under /api/nexbridge/v1/
     * @param  array  $params  Query string parameters
     * @return array|null Decoded JSON body, or null on failure
     */
    private function get(string $path, array $params = []): ?array
    {
        if (empty($this->baseUrl) || empty($this->secret)) {
            Log::warning('[NexBridge] Not configured — skipping Nexflow API call.', ['path' => $path]);

            return null;
        }

        $url = "{$this->baseUrl}/api/nexbridge/v1{$path}";

        try {
            $response = Http::withToken($this->secret)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($url, $params);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 404) {
                // Employee not found in Nexflow — not an error worth logging loudly
                Log::info('[NexBridge] Employee not found in Nexflow.', ['path' => $path, 'params' => $params]);

                return null;
            }

            Log::error('[NexBridge] Nexflow API returned an error.', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;

        } catch (ConnectionException $e) {
            Log::warning('[NexBridge] Could not reach Nexflow.', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;

        } catch (RequestException $e) {
            Log::error('[NexBridge] HTTP request error.', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;

        } catch (\Throwable $e) {
            Log::error('[NexBridge] Unexpected error calling Nexflow.', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
