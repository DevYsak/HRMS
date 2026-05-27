<?php

namespace App\Livewire\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\ZKTecoService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class BiometricSync extends Component
{
    public string $activeTab = 'sync';

    public ?int $selectedDeviceId = null;

    public ?string $flashMessage = null;

    public ?string $flashType = null;

    // ── Device Users tab ──────────────────────────────────────────────────
    public ?int $usersDeviceId = null;

    public array $deviceUsers = [];   // fetched from device

    public bool $usersFetched = false;

    // mapping modal state
    public ?string $mappingDeviceUserId = null;

    public ?string $mappingDeviceUserName = null;

    public string $mappingEmployeeSearch = '';

    public ?int $mappingEmployeeId = null;

    // ──────────────────────────────────────────────────────────────────────

    #[Computed]
    public function devices()
    {
        return BiometricDevice::orderBy('name')->get();
    }

    #[Computed]
    public function recentLogs()
    {
        return BiometricLog::with(['employee.user', 'device'])
            ->latest('punched_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function unmatchedCount(): int
    {
        return BiometricLog::whereNull('employee_id')->distinct('device_user_id')->count('device_user_id');
    }

    #[Computed]
    public function unprocessedCount(): int
    {
        return BiometricLog::where('is_processed', false)->whereNotNull('employee_id')->count();
    }

    /** All employees with their biometric_id for the mapping table. */
    #[Computed]
    public function employees()
    {
        return Employee::with('user')
            ->whereHas('user')
            ->orderBy('id')
            ->get(['id', 'user_id', 'employee_id', 'biometric_id']);
    }

    /** Employees matching the search input in the mapping modal. */
    #[Computed]
    public function employeeSearchResults()
    {
        if (strlen($this->mappingEmployeeSearch) < 1) {
            return collect();
        }

        return Employee::with('user')
            ->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$this->mappingEmployeeSearch}%"))
            ->orWhere('employee_id', 'like', "%{$this->mappingEmployeeSearch}%")
            ->limit(10)
            ->get(['id', 'user_id', 'employee_id', 'biometric_id']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Sync tab actions
    // ──────────────────────────────────────────────────────────────────────

    public function pingDevices(): void
    {
        $results = $this->makeSyncService()->pingAllDevices();
        $online = collect($results)->where('status', 'online')->count();

        $this->flash('Pinged '.count($results)." device(s) — {$online} online.", $online === count($results) ? 'success' : 'error');
        unset($this->devices);
    }

    public function syncDevice(): void
    {
        if (! $this->selectedDeviceId) {
            $this->flash('Please select a device first.', 'error');

            return;
        }

        $device = BiometricDevice::find($this->selectedDeviceId);

        if (! $device) {
            $this->flash('Device not found.', 'error');

            return;
        }

        try {
            $result = $this->makeSyncService()->syncDevice($device);
            $this->flash(
                "Sync complete — {$result['fetched']} fetched, {$result['inserted']} new, {$result['applied']} applied.",
                'success',
            );
        } catch (Throwable $e) {
            $this->flash("Sync failed: {$e->getMessage()}", 'error');
        }

        unset($this->recentLogs, $this->devices, $this->unmatchedCount, $this->unprocessedCount);
    }

    public function syncAll(): void
    {
        $results = $this->makeSyncService()->syncAllDevices();
        $success = collect($results)->whereNull('error')->count();
        $failed = collect($results)->whereNotNull('error')->count();
        $total = count($results);
        $type = $failed > 0 ? ($success > 0 ? 'info' : 'error') : 'success';

        $this->flash("Synced {$success}/{$total} device(s).".($failed > 0 ? " {$failed} failed." : ''), $type);
        unset($this->recentLogs, $this->devices, $this->unmatchedCount, $this->unprocessedCount);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Device Users tab actions
    // ──────────────────────────────────────────────────────────────────────

    public function fetchDeviceUsers(): void
    {
        if (! $this->usersDeviceId) {
            $this->flash('Please select a device first.', 'error');

            return;
        }

        $device = BiometricDevice::find($this->usersDeviceId);

        if (! $device) {
            $this->flash('Device not found.', 'error');

            return;
        }

        try {
            $zk = new ZKTecoService($device->ip_address, $device->port, $device->timeout_seconds);
            $zk->connect();
            $users = $zk->getUsers();
            $zk->disconnect();

            // Attach current HRMS mapping for each device user.
            $mappedIds = Employee::whereNotNull('biometric_id')
                ->pluck('id', 'biometric_id')
                ->toArray();

            $this->deviceUsers = array_map(function (array $u) use ($mappedIds) {
                $u['employee_id'] = $mappedIds[$u['device_user_id']] ?? null;

                return $u;
            }, $users);

            $this->usersFetched = true;
            $this->flash(count($users).' user(s) fetched from device.', 'success');
        } catch (Throwable $e) {
            $this->flash("Failed to fetch users: {$e->getMessage()}", 'error');
        }

        unset($this->employees);
    }

    /** Open the mapping modal for a specific device user. */
    public function openMapping(string $deviceUserId, string $deviceUserName): void
    {
        $this->mappingDeviceUserId = $deviceUserId;
        $this->mappingDeviceUserName = $deviceUserName;
        $this->mappingEmployeeSearch = '';
        $this->mappingEmployeeId = null;

        unset($this->employeeSearchResults);
    }

    /** Save: assign biometric_id on the selected employee. */
    public function saveMapping(): void
    {
        if (! $this->mappingDeviceUserId || ! $this->mappingEmployeeId) {
            $this->flash('Select an employee to map.', 'error');

            return;
        }

        $employee = Employee::find($this->mappingEmployeeId);

        if (! $employee) {
            $this->flash('Employee not found.', 'error');

            return;
        }

        // Unassign the old employee who had this biometric_id (if any).
        Employee::where('biometric_id', $this->mappingDeviceUserId)
            ->where('id', '!=', $employee->id)
            ->update(['biometric_id' => null]);

        $employee->update(['biometric_id' => $this->mappingDeviceUserId]);

        // Refresh the employee_id in the in-memory deviceUsers array.
        foreach ($this->deviceUsers as &$u) {
            if ($u['device_user_id'] === $this->mappingDeviceUserId) {
                $u['employee_id'] = $employee->id;
            }
        }

        $this->flash("Device user #{$this->mappingDeviceUserId} mapped to {$employee->user?->name}.", 'success');
        $this->closeMapping();

        unset($this->employees);
    }

    /** Remove the biometric_id mapping from an employee. */
    public function removeMapping(string $deviceUserId): void
    {
        Employee::where('biometric_id', $deviceUserId)->update(['biometric_id' => null]);

        foreach ($this->deviceUsers as &$u) {
            if ($u['device_user_id'] === $deviceUserId) {
                $u['employee_id'] = null;
            }
        }

        $this->flash("Mapping for device user #{$deviceUserId} removed.", 'info');
        unset($this->employees);
    }

    public function closeMapping(): void
    {
        $this->mappingDeviceUserId = null;
        $this->mappingDeviceUserName = null;
        $this->mappingEmployeeSearch = '';
        $this->mappingEmployeeId = null;

        unset($this->employeeSearchResults);
    }

    public function updatedMappingEmployeeSearch(): void
    {
        unset($this->employeeSearchResults);
    }

    // ──────────────────────────────────────────────────────────────────────

    public function render()
    {
        return view('livewire.attendance.biometric-sync');
    }

    private function makeSyncService(): BiometricSyncService
    {
        return new BiometricSyncService(app(AttendanceService::class));
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
