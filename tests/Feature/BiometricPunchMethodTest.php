<?php

use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Services\Biometric\BiometricSyncService;

/**
 * Create a device + a matched employee and push two logs (in/out) for a day.
 */
function seedPunch(int $inVerify, int $outVerify): array
{
    $device = BiometricDevice::create(['name' => 'AIFACE', 'ip_address' => '10.0.0.1', 'port' => 4370, 'timeout_seconds' => 5]);
    $employee = Employee::factory()->create();

    BiometricLog::create([
        'device_id' => $device->id, 'device_user_id' => (string) $employee->id, 'employee_id' => $employee->id,
        'punched_at' => '2026-06-20 09:05:00', 'punch_type' => 'check_in', 'verify_type' => $inVerify, 'is_processed' => false,
    ]);
    BiometricLog::create([
        'device_id' => $device->id, 'device_user_id' => (string) $employee->id, 'employee_id' => $employee->id,
        'punched_at' => '2026-06-20 18:10:00', 'punch_type' => 'check_out', 'verify_type' => $outVerify, 'is_processed' => false,
    ]);

    return [$device, $employee];
}

test('device verify codes are mapped to punch methods on the attendance (face in, card out)', function () {
    // AIFACE-MAGNUM: 4 = Face, 3 = Card (per config/biometric.php verify_methods).
    [$device, $employee] = seedPunch(inVerify: 4, outVerify: 3);

    app(BiometricSyncService::class)->applyPendingLogs($device);

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();
    expect($att->check_in_method)->toBe('face');
    expect($att->check_out_method)->toBe('id_card');
});

test('a fingerprint punch is mapped and a PIN punch shows no method', function () {
    [$device, $employee] = seedPunch(inVerify: 1, outVerify: 2); // 1 = Fingerprint, 2 = PIN (untracked)

    app(BiometricSyncService::class)->applyPendingLogs($device);

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();
    expect($att->check_in_method)->toBe('fingerprint');
    expect($att->check_out_method)->toBeNull();
});

test('an Other verify code (15) shows no chip', function () {
    [$device, $employee] = seedPunch(inVerify: 15, outVerify: 15);

    app(BiometricSyncService::class)->applyPendingLogs($device);

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();
    expect($att->check_in_method)->toBeNull();
});
