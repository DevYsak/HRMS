<?php

namespace App\Services\Biometric;

use Illuminate\Support\Facades\Http;

/**
 * Reads one day of the attendance engine's log (GET /api/dashboard?date=,
 * the same call the live sync makes) and reports who punched — writes
 * nothing. Lets HR see what the logs hold, and which device PINs have no
 * HRMS employee, before and after a history sync.
 */
class EngineHistoryScanner
{
    /**
     * Every PIN with punches that day, with the name the engine knows it by.
     *
     * @return array{error: ?string, pins: array<int, array{name: ?string, punches: int}>}
     */
    public function day(string $date): array
    {
        $baseUrl = rtrim((string) config('services.biometric_app.url'), '/');

        if ($baseUrl === '') {
            return ['error' => 'Attendance engine URL (BIOMETRIC_APP_URL) is not configured.', 'pins' => []];
        }

        try {
            $response = Http::timeout((int) config('services.biometric_app.timeout', 10))
                ->when(! config('services.biometric_app.verify_ssl', true), fn ($r) => $r->withoutVerifying())
                ->acceptJson()
                ->get("{$baseUrl}/api/dashboard", ['date' => $date]);
        } catch (\Throwable) {
            return ['error' => 'Could not reach the attendance engine.', 'pins' => []];
        }

        if (! $response->successful()) {
            return ['error' => "Engine returned HTTP {$response->status()}.", 'pins' => []];
        }

        $pins = [];
        foreach ((array) ($response->json('table') ?? []) as $row) {
            if (! isset($row['emp_id']) || ! is_numeric($row['emp_id'])) {
                continue;
            }

            $punches = (int) ($row['punch_count'] ?? count((array) ($row['punches'] ?? [])));
            $pins[(int) $row['emp_id']] = [
                'name' => isset($row['name']) && $row['name'] !== '' ? (string) $row['name'] : null,
                'punches' => max(1, $punches),
            ];
        }

        return ['error' => null, 'pins' => $pins];
    }
}
