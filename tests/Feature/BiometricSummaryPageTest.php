<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\BiometricSummary;
use App\Models\AttendanceDailySummary;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function syncedSummary(int $code, string $status = 'present', array $overrides = []): AttendanceDailySummary
{
    // manager_id null so another employee's name never leaks into the Manager column.
    $employee = Employee::factory()->create(['employee_code' => $code, 'status' => 'active', 'manager_id' => null]);

    return AttendanceDailySummary::create(array_merge([
        'employee_id' => $employee->id,
        'employee_code' => $code,
        'date' => now()->toDateString(),
        'first_punch' => now()->toDateString().' 10:32:00',
        'last_punch' => now()->toDateString().' 19:35:00',
        'break_minutes' => 50,
        'working_hours' => 8.2,
        'late_minutes' => 2,
        'overtime_minutes' => 5,
        'status' => $status,
        'raw_punch_count' => 4,
        'synced_at' => now(),
    ], $overrides));
}

test('biometric summary page renders synced attendance for an authorised user', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $admin->id, 'status' => 'active']);

    $summary = syncedSummary(31);

    $this->withoutVite()->actingAs($admin)
        ->get(route('attendance.biometric-summary'))
        ->assertOk()
        ->assertSee('Biometric Attendance Summary')
        ->assertSee($summary->employee->user->name)
        ->assertSee('PIN 31');
});

test('biometric summary page is forbidden for a plain employee', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employee->id, 'status' => 'active']);

    $this->withoutVite()->actingAs($employee)
        ->get(route('attendance.biometric-summary'))
        ->assertForbidden();
});

test('biometric summary filters rows by status', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    syncedSummary(41, 'present');
    syncedSummary(42, 'absent');

    Livewire::test(BiometricSummary::class)
        ->set('status', 'absent')
        ->assertSee('PIN 42')
        ->assertDontSee('PIN 41');
});

test('biometric summary only shows the selected day', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    syncedSummary(51);
    syncedSummary(52, 'present', ['date' => now()->subDay()->toDateString()]);

    Livewire::test(BiometricSummary::class)
        ->assertSee('PIN 51')
        ->assertDontSee('PIN 52')
        ->call('previousDay')
        ->assertSee('PIN 52')
        ->assertDontSee('PIN 51');
});

test('quick scan pulls the latest attendance on demand', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    Employee::factory()->create(['employee_code' => 17, 'manager_id' => null]);

    Http::fake(['*/api/dashboard*' => Http::response(['table' => [
        ['emp_id' => '17', 'first_punch' => '10:31:00', 'last_punch' => '17:40:00',
            'working_min' => 273, 'break_min' => 157, 'overtime_min' => 0, 'late' => true, 'delay_min' => 1,
            'punch_count' => 10, 'status' => 'Completed Shift'],
    ]], 200)]);

    Livewire::test(BiometricSummary::class)
        ->set('date', '2026-06-29')
        ->assertDontSee('PIN 17')   // nothing synced yet
        ->call('syncNow')
        ->assertSee('PIN 17');      // appears immediately after a quick scan

    expect(AttendanceDailySummary::where('employee_code', 17)->where('date', '2026-06-29')->exists())->toBeTrue();
});
