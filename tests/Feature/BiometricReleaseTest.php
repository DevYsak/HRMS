<?php

use App\Enums\UserRole;
use App\Livewire\Onboarding\OffboardingManager;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Biometric\BiometricSyncService;
use Livewire\Livewire;

test('releasing an employee clears their biometric identity and frees the card', function () {
    $employee = Employee::factory()->create([
        'employee_code' => 4501,
        'biometric_user_id' => '4501',
        'sync_status' => 'synced',
        // no biometric_device_id → HRMS-side release only, no device call
    ]);

    $result = app(BiometricSyncService::class)->releaseEmployee($employee);

    $fresh = $employee->fresh();
    expect($result)->toBeFalse()                       // no device enrolment to remove
        ->and($fresh->employee_code)->toBeNull()
        ->and($fresh->biometric_user_id)->toBeNull()
        ->and($fresh->biometric_device_id)->toBeNull()
        ->and($fresh->sync_status)->toBe('removed');

    // The release is audited.
    expect(AuditLog::where('action', 'biometric_released')
        ->where('auditable_id', $employee->id)->exists())->toBeTrue();

    // The freed card code can be reassigned to a new hire — no collision.
    $newHire = Employee::factory()->create(['employee_code' => 4501]);
    expect((int) $newHire->fresh()->employee_code)->toBe(4501);
});

test('offboarding an employee past their last working day releases their card', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create([
        'employee_code' => 7788,
        'sync_status' => 'synced',
        'status' => 'active',
    ]);

    Livewire::actingAs($hr)->test(OffboardingManager::class)
        ->set('selectedEmployeeId', $employee->id)
        ->set('lastWorkingDay', today()->subDay()->toDateString())
        ->set('exitType', 'resignation')
        ->call('processOffboarding');

    $fresh = $employee->fresh();
    expect($fresh->status->value)->toBe('inactive')
        ->and($fresh->employee_code)->toBeNull()
        ->and($fresh->sync_status)->toBe('removed');
});
