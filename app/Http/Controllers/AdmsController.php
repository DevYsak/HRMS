<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\Biometric\BiometricSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AdmsController — receives attendance data pushed by eSSL / ZKTeco devices
 * using the ADMS (iclock) HTTP push protocol.
 *
 * Device config (set on device screen):
 *   Menu → Communication → Server / ADMS
 *     Server IP   : 192.168.0.128   (this machine)
 *     Server Port : 80
 *     Path        : /iclock
 */
class AdmsController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // GET /iclock/cdata?SN=TBDD253900118&options=all&...
    //
    // Device registers and requests its configuration.
    // We reply with a plain-text options block.
    // ──────────────────────────────────────────────────────────────────────
    public function options(Request $request): Response
    {
        $device = $this->resolveDevice($request);

        if (! $device) {
            Log::warning('ADMS check-in from unknown SN: '.$request->query('SN'));

            return $this->text("GET OPTION FROM: {$request->query('SN')}\r\nEncrypt=None\r\n");
        }

        $device->update([
            'last_ping_at' => now(),
            'last_ping_status' => 'online',
            'last_ping_error' => null,
        ]);

        // ATTLOGStamp tells the device to only send records newer than this timestamp.
        // Send 0 on first run to get all historical records.
        $stamp = $device->adms_stamp ?? 0;

        $body = implode("\r\n", [
            "GET OPTION FROM: {$device->serial_number}",
            "ATTLOGStamp={$stamp}",
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=0',
            'ErrorDelay=30',
            'Delay=10',
            'TransTimes=00:00;23:59',
            'TransInterval=1',
            'TransFlag=TransData AttLog OpLog',
            'TimeZone=5.5',
            'Realtime=1',
            'Encrypt=None',
        ])."\r\n";

        return $this->text($body);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /iclock/cdata?SN=...&table=ATTLOG&Stamp=...&Count=N
    //
    // Device uploads attendance records.
    // Body: data=UserID\tTime\tStatus\tVerify\tWorkCode\tReserved\n...
    // ──────────────────────────────────────────────────────────────────────
    public function upload(Request $request): Response
    {
        $device = $this->resolveDevice($request);

        if (! $device) {
            return $this->text('OK: 0');
        }

        $table = strtoupper($request->query('table', ''));

        if ($table !== 'ATTLOG') {
            // OPERLOG, PHOTO etc. — acknowledge and ignore for now
            return $this->text('OK: 0');
        }

        $rawData = $request->input('data', '');
        $records = $this->parseAttlog($rawData);
        $inserted = $this->storeRecords($device, $records);

        // Update stamp to the latest punch time so the device doesn't re-send old records.
        $newStamp = $request->query('Stamp', $device->adms_stamp);
        $device->update([
            'adms_stamp' => max((int) $newStamp, $device->adms_stamp),
            'last_synced_at' => now(),
            'last_sync_count' => count($records),
            'last_ping_at' => now(),
            'last_ping_status' => 'online',
        ]);

        // Apply any newly inserted logs to the attendances table immediately.
        app(BiometricSyncService::class)->applyPendingLogs($device);

        Log::info("ADMS: {$device->name} pushed ".count($records)." records, {$inserted} new.");

        return $this->text('OK: '.count($records));
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /iclock/getrequest?SN=...
    //
    // Device polls for pending commands. Return OK when nothing is queued.
    // ──────────────────────────────────────────────────────────────────────
    public function getRequest(Request $request): Response
    {
        $this->resolveDevice($request)?->update([
            'last_ping_at' => now(),
            'last_ping_status' => 'online',
        ]);

        return $this->text('OK');
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /iclock/devicecmd?SN=...&CMD=...&Return=0&Msg=OK
    //
    // Device reports the result of an executed command.
    // ──────────────────────────────────────────────────────────────────────
    public function deviceCmd(Request $request): Response
    {
        return $this->text('OK');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function resolveDevice(Request $request): ?BiometricDevice
    {
        $sn = $request->query('SN') ?: $request->input('SN');

        if (! $sn) {
            return null;
        }

        return BiometricDevice::where('serial_number', $sn)->first();
    }

    /**
     * Parse the tab-separated ATTLOG data block.
     *
     * Each line: UserID \t PunchTime \t Status \t Verify \t WorkCode \t Reserved
     *
     * @return array<int, array{device_user_id:string, punched_at:string, state:int, verify:int}>
     */
    private function parseAttlog(string $raw): array
    {
        $records = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 4) {
                continue;
            }

            [$userId, $punchTime, $status, $verify] = $parts;

            $records[] = [
                'device_user_id' => trim($userId),
                'punched_at' => trim($punchTime),
                'state' => (int) trim($status),
                'verify' => (int) trim($verify),
            ];
        }

        return $records;
    }

    /**
     * Upsert raw ATTLOG records into biometric_logs.
     * Skips duplicates via unique(device_id, device_user_id, punched_at).
     */
    private function storeRecords(BiometricDevice $device, array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $punchTypes = config('biometric.punch_types', [0 => 'check_in', 1 => 'check_out']);
        $employeeMap = Employee::whereNotNull('biometric_id')
            ->pluck('id', 'biometric_id')
            ->toArray();

        $rows = [];

        foreach ($records as $raw) {
            $rows[] = [
                'device_id' => $device->id,
                'device_user_id' => $raw['device_user_id'],
                'employee_id' => $employeeMap[$raw['device_user_id']] ?? null,
                'punched_at' => $raw['punched_at'],
                'punch_type' => $punchTypes[$raw['state']] ?? 'unknown',
                'verify_type' => $raw['verify'],
                'is_processed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return DB::table('biometric_logs')->insertOrIgnore($rows);
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain']);
    }
}
