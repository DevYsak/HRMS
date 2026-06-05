<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\Biometric\BiometricSyncService;
use Illuminate\Console\Command;

/**
 * biometric:push-employees
 *
 * Pushes employee records FROM HRMS TO biometric devices.
 * HRMS is the master — this command enrolls or updates users on the device.
 *
 * Targets employees where:
 *   - employee_code is set (device user number)
 *   - biometric_device_id is set (which device to push to)
 *   - sync_status is 'pending' or 'failed'
 *
 * Usage:
 *   php artisan biometric:push-employees
 *   php artisan biometric:push-employees --dry-run
 *   php artisan biometric:push-employees --force       # re-push even 'synced' employees
 *   php artisan biometric:push-employees --device=1   # push to a specific device only
 */
class PushEmployeesToBiometric extends Command
{
    protected $signature = 'biometric:push-employees
                            {--dry-run  : Preview without writing to the device}
                            {--force    : Push all employees regardless of sync_status}
                            {--device=  : Restrict push to a specific device ID}';

    protected $description = 'Push HRMS employees to biometric devices (HRMS is master).';

    public function handle(BiometricSyncService $syncService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');
        $deviceId = $this->option('device');

        if ($isDryRun) {
            $this->warn('  DRY RUN — no changes will be written to the device.');
            $this->newLine();
        }

        $query = Employee::with(['user', 'biometricDevice'])
            ->whereNotNull('employee_code')
            ->whereNotNull('biometric_device_id');

        if (! $isForce) {
            $query->whereIn('sync_status', ['pending', 'failed']);
        }

        if ($deviceId) {
            $device = BiometricDevice::find($deviceId);

            if (! $device) {
                $this->error("  Device #{$deviceId} not found.");

                return self::FAILURE;
            }

            $query->where('biometric_device_id', $deviceId);
        }

        $employees = $query->get();

        if ($employees->isEmpty()) {
            $this->info('  Nothing to push — all employees are already synced.');

            return self::SUCCESS;
        }

        $this->line("  Found {$employees->count()} employee(s) to push.");
        $this->newLine();

        $pushed = 0;
        $failed = 0;
        $errors = [];

        foreach ($employees as $employee) {
            $label = "code={$employee->employee_code}  {$employee->user?->name}  [{$employee->biometricDevice?->name}]";

            if ($isDryRun) {
                $action = match ($employee->sync_status) {
                    'pending' => 'ENROL',
                    'failed' => 'RETRY',
                    default => 'UPDATE',
                };
                $this->line("  [{$action}] {$label}");

                continue;
            }

            try {
                $syncService->pushEmployee($employee, $employee->biometricDevice);
                $pushed++;
                $this->line("  <fg=green>PUSHED</> {$label}");
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "  {$label}: {$e->getMessage()}";
                $this->line("  <fg=red>FAILED</> {$label}");
            }
        }

        $this->newLine();

        if (! $isDryRun) {
            $this->info("  Done — {$pushed} pushed, {$failed} failed.");
        }

        if ($errors) {
            $this->newLine();
            $this->error('  Errors:');

            foreach ($errors as $err) {
                $this->line($err);
            }
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
