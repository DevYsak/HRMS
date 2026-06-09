<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * biometric:test-connection
 *
 * Diagnoses the HRMS → biometric-app API connection.
 * Run this on the production server to pinpoint failures:
 *
 *   php artisan biometric:test-connection
 */
class TestBiometricConnection extends Command
{
    protected $signature = 'biometric:test-connection';

    protected $description = 'Diagnose the HRMS → biometric-app API connection.';

    public function handle(): int
    {
        $cfg = config('services.biometric_app');
        $baseUrl = rtrim($cfg['url'] ?? '', '/');
        $key = $cfg['key'] ?? null;
        $timeout = (int) ($cfg['timeout'] ?? 10);
        $verifySsl = (bool) ($cfg['verify_ssl'] ?? true);

        // ── 1. Config dump ────────────────────────────────────────────────────
        $this->info('── Config ───────────────────────────────────────────');
        $this->line("  BIOMETRIC_APP_URL        : {$baseUrl}");
        $this->line('  BIOMETRIC_APP_KEY        : '.($key ? str_repeat('*', strlen($key) - 4).substr($key, -4) : '<not set>'));
        $this->line("  BIOMETRIC_APP_TIMEOUT    : {$timeout}s");
        $this->line('  BIOMETRIC_APP_VERIFY_SSL : '.($verifySsl ? 'true' : 'false (SSL verification disabled)'));
        $this->newLine();

        if (empty($baseUrl)) {
            $this->error('  BIOMETRIC_APP_URL is empty. Set it in .env and run: php artisan config:clear');

            return self::FAILURE;
        }

        if (empty($key)) {
            $this->warn('  BIOMETRIC_APP_KEY is not set — requests will be sent without X-Api-Key header.');
        }

        // ── 2. DNS resolution ─────────────────────────────────────────────────
        $this->info('── DNS ──────────────────────────────────────────────');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $ip = gethostbyname($host);

        if ($ip === $host) {
            $this->error("  DNS resolution FAILED for {$host}");

            return self::FAILURE;
        }

        $this->line("  {$host} → {$ip}  ✓");
        $this->newLine();

        // ── 3. HTTP probe — /api/attendance/grid ─────────────────────────────
        $endpoint = "{$baseUrl}/api/attendance/grid?date=".now()->toDateString();
        $this->info('── HTTP probe ───────────────────────────────────────');
        $this->line("  GET {$endpoint}");

        try {
            $req = Http::timeout($timeout)
                ->acceptJson()
                ->when(! $verifySsl, fn ($r) => $r->withoutVerifying());

            if ($key) {
                $req = $req->withHeaders(['X-Api-Key' => $key]);
            }

            $response = $req->get($endpoint);

            $this->line("  Status : {$response->status()}");
            $this->line('  Headers: Content-Type = '.($response->header('Content-Type') ?: '<none>'));
            $this->newLine();

            if ($response->successful()) {
                $this->info('  ✓ Connection successful.');
                $this->line('  Response (truncated):');
                $this->line('  '.substr($response->body(), 0, 500));

                return self::SUCCESS;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                $this->error("  Auth failed ({$response->status()}) — BIOMETRIC_APP_KEY is wrong or missing.");
                $this->line('  Response: '.$response->body());

                return self::FAILURE;
            }

            if ($response->status() === 404) {
                $this->error('  404 — Route /api/attendance/grid does not exist on the biometric app.');
                $this->line('  Check that biometric-app routes are registered and the app is deployed.');

                return self::FAILURE;
            }

            $this->error("  Unexpected status {$response->status()}");
            $this->line('  Response: '.$response->body());

            return self::FAILURE;

        } catch (ConnectionException $e) {
            $this->error('  ConnectionException — server is unreachable.');
            $this->line('  Detail: '.$e->getMessage());
            $this->newLine();
            $this->line('  Possible causes:');
            $this->line('    • BIOMETRIC_APP_URL points to wrong host/port');
            $this->line('    • Firewall blocking outbound HTTPS from this server');
            $this->line('    • SSL certificate error on biometric-app');
            $this->line('    • biometric-app process is down');

            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error('  Unexpected error: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
