<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\ZKTecoService;
use Illuminate\Console\Command;

class SyncBiometricAttendance extends Command
{
    protected $signature = 'hrms:sync-biometric
                            {--device= : Sync only this device ID (defaults to all active devices)}
                            {--ping    : Only ping devices, do not sync attendance data}
                            {--clear   : Clear attendance logs from device AFTER a successful sync (use with caution)}
                            {--debug   : Dump raw ZKTeco packets to stdout for protocol debugging}';

    protected $description = 'Pull punch logs from eSSL/ZKTeco biometric devices and write to attendances.';

    public function handle(BiometricSyncService $syncService): int
    {
        if ($this->option('ping')) {
            return $this->runPing($syncService);
        }

        return $this->runSync($syncService);
    }

    // ──────────────────────────────────────────────────────────────────────

    private function runPing(BiometricSyncService $syncService): int
    {
        $this->info('Pinging biometric devices…');

        $results = $syncService->pingAllDevices();

        foreach ($results as $r) {
            $icon = match ($r['status']) {
                'online' => '<fg=green>✓</>',
                'offline' => '<fg=yellow>✗</>',
                default => '<fg=red>!</>',
            };

            $this->line(" {$icon} [{$r['id']}] {$r['name']} — {$r['status']}"
                .($r['error'] ? " ({$r['error']})" : ''));
        }

        return self::SUCCESS;
    }

    private function runSync(BiometricSyncService $syncService): int
    {
        $deviceId = $this->option('device');
        $doClear = (bool) $this->option('clear');

        if ($this->option('debug')) {
            $syncService->debug = true;
            $this->warn('Debug mode ON — raw packets will be printed to stdout.');
        }

        if ($deviceId) {
            $device = BiometricDevice::find((int) $deviceId);

            if (! $device) {
                $this->error("Device ID {$deviceId} not found.");

                return self::FAILURE;
            }

            $devices = collect([$device]);
        } else {
            $devices = BiometricDevice::where('is_active', true)->get();
        }

        if ($devices->isEmpty()) {
            $this->warn('No active biometric devices configured.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$devices->count()} device(s)…");

        $totalApplied = 0;
        $exitCode = self::SUCCESS;

        foreach ($devices as $device) {
            $this->line('');
            $this->line("  → <comment>{$device->name}</comment> ({$device->ip_address}:{$device->port})");

            try {
                $result = $syncService->syncDevice($device);

                $this->line("     Fetched  : {$result['fetched']} raw punches");
                $this->line("     Inserted : {$result['inserted']} new logs");
                $this->line("     Applied  : {$result['applied']} attendance records");

                if ($result['errors'] > 0) {
                    $this->warn("     Errors   : {$result['errors']} logs could not be applied");
                }

                $totalApplied += $result['applied'];

                // Optionally clear device logs only when the sync was fully successful.
                if ($doClear && $result['fetched'] > 0 && $result['errors'] === 0) {
                    $cleared = app(BiometricSyncService::class); // re-use to avoid re-connecting
                    $this->warn("     ⚠ Clearing attendance logs from device {$device->name}…");
                    // Re-connect to clear (sync already disconnected).
                    $zk = new ZKTecoService(
                        $device->ip_address, $device->port, $device->timeout_seconds
                    );
                    $zk->connect();
                    $zk->clearAttendance();
                    $zk->disconnect();
                    $this->line('     Device logs cleared.');
                }
            } catch (\Throwable $e) {
                $this->error("     FAILED: {$e->getMessage()}");
                $exitCode = self::FAILURE;
            }
        }

        $this->line('');
        $this->info("Done. Total attendance records applied: {$totalApplied}");

        return $exitCode;
    }
}
