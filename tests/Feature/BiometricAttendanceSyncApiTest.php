<?php

use App\Models\AttendanceDailySummary;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;

const SYNC_KEY = 'test-sync-secret';

beforeEach(function () {
    config(['biometric.api.key' => SYNC_KEY]);
});

/** Headers carrying a valid shared API key. */
function apiKey(): array
{
    return ['X-Api-Key' => SYNC_KEY];
}

// ── Auth ────────────────────────────────────────────────────────────────────

test('sync endpoints reject a missing or wrong API key', function () {
    $this->getJson('/api/v1/employees')->assertStatus(401);
    $this->getJson('/api/v1/employees', ['X-Api-Key' => 'nope'])->assertStatus(401);
});

test('sync endpoints fail closed when no key is configured', function () {
    config(['biometric.api.key' => null]);

    $this->getJson('/api/v1/employees', apiKey())->assertStatus(503);
});

test('a valid key is accepted via X-Api-Key or Bearer token', function () {
    $this->getJson('/api/v1/employees', apiKey())->assertOk();
    $this->getJson('/api/v1/employees', ['Authorization' => 'Bearer '.SYNC_KEY])->assertOk();
});

// ── GET /api/v1/employees ─────────────────────────────────────────────────────

test('employees endpoint returns only PIN-coded employees with master fields', function () {
    $named = User::factory()->create(['name' => 'Asha Rao']);
    Employee::factory()->create(['user_id' => $named->id, 'employee_code' => 101, 'status' => 'active']);
    Employee::factory()->create(['employee_code' => null]); // codeless — excluded

    $response = $this->getJson('/api/v1/employees', apiKey())->assertOk();

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.employee_code', 101);
    $response->assertJsonPath('data.0.name', 'Asha Rao');
    $response->assertJsonStructure([
        'data' => [['employee_code', 'name', 'department', 'designation', 'shift', 'manager', 'status', 'is_active']],
    ]);
});

test('employees endpoint can include codeless employees with ?all=1', function () {
    Employee::factory()->create(['employee_code' => 102]);
    Employee::factory()->create(['employee_code' => null]);

    $this->getJson('/api/v1/employees?all=1', apiKey())
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── GET /api/v1/shifts ────────────────────────────────────────────────────────

test('shifts endpoint returns shift rules for the engine', function () {
    ShiftSetting::create([
        'name' => 'General Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_duration' => 60,
        'grace_minutes' => 10,
        'standard_hours' => 9,
        'ot_threshold_hours' => 9,
    ]);

    $this->getJson('/api/v1/shifts', apiKey())
        ->assertOk()
        ->assertJsonPath('data.0.name', 'General Shift')
        ->assertJsonPath('data.0.grace_minutes', 10)
        ->assertJsonStructure(['data' => [['id', 'name', 'start_time', 'end_time', 'grace_minutes', 'break_minutes', 'standard_hours', 'ot_threshold_hours']]]);
});

// ── GET /api/v1/holidays ──────────────────────────────────────────────────────

test('holidays endpoint defaults to the current year and supports filters', function () {
    PublicHoliday::create(['date' => now()->startOfYear()->addMonths(2)->toDateString(), 'name' => 'This Year Holiday', 'country' => 'IN']);
    PublicHoliday::create(['date' => now()->subYear()->toDateString(), 'name' => 'Last Year Holiday', 'country' => 'IN']);
    PublicHoliday::create(['date' => now()->startOfYear()->addMonths(3)->toDateString(), 'name' => 'UK Holiday', 'country' => 'UK']);

    $this->getJson('/api/v1/holidays', apiKey())
        ->assertOk()
        ->assertJsonCount(2, 'data') // both current-year holidays, last year excluded
        ->assertJsonFragment(['name' => 'This Year Holiday']);

    $this->getJson('/api/v1/holidays?country=UK', apiKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'UK Holiday');

    $this->getJson('/api/v1/holidays?year='.now()->subYear()->year, apiKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Last Year Holiday');
});

// ── GET /api/v1/leaves ────────────────────────────────────────────────────────

test('leaves endpoint returns approved leaves in-window keyed by employee_code', function () {
    $employee = Employee::factory()->create(['employee_code' => 201]);

    $type = LeaveType::create([
        'name' => 'Casual Leave',
        'code' => 'CL'.random_int(100, 999),
        'category' => 'annual',
        'is_paid' => true,
        'color' => '#10b981',
        'allow_paid_request' => true,
        'allow_unpaid_request' => false,
    ]);

    $day = now()->startOfMonth()->addDays(5);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $day, 'end_date' => $day, 'days' => 1,
        'reason' => 'Approved', 'status' => 'approved',
    ]);

    // Pending leave in window — must be excluded.
    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $day, 'end_date' => $day, 'days' => 1,
        'reason' => 'Pending', 'status' => 'pending',
    ]);

    $this->getJson('/api/v1/leaves', apiKey())
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_code', 201)
        ->assertJsonPath('data.0.status', 'approved');
});

// ── POST /api/v1/attendance/sync ──────────────────────────────────────────────

test('attendance sync stores a batch of daily summaries', function () {
    $a = Employee::factory()->create(['employee_code' => 301]);
    $b = Employee::factory()->create(['employee_code' => 302]);

    $payload = ['records' => [
        ['employee_code' => 301, 'date' => '2026-06-01', 'first_punch' => '2026-06-01 09:05:00', 'last_punch' => '2026-06-01 18:30:00', 'break_minutes' => 45, 'working_hours' => 8.75, 'late_minutes' => 5, 'overtime_minutes' => 30, 'status' => 'present', 'device_serial' => 'TBDD253900118', 'raw_punch_count' => 4],
        ['employee_code' => 302, 'date' => '2026-06-01', 'status' => 'absent'],
    ]];

    $this->postJson('/api/v1/attendance/sync', $payload, apiKey())
        ->assertOk()
        ->assertJsonPath('synced', 2)
        ->assertJsonPath('skipped', 0);

    expect(AttendanceDailySummary::count())->toBe(2);

    $row = AttendanceDailySummary::where('employee_id', $a->id)->first();
    expect($row->working_hours)->toBe('8.75');
    expect($row->late_minutes)->toBe(5);
    expect($row->raw_punch_count)->toBe(4);

    expect(AttendanceDailySummary::where('employee_id', $b->id)->first()->status)->toBe('absent');
});

test('attendance sync records the punch verification method', function () {
    $emp = Employee::factory()->create(['employee_code' => 310]);

    $payload = ['records' => [
        ['employee_code' => 310, 'date' => '2026-06-03', 'first_punch' => '2026-06-03 09:00:00', 'last_punch' => '2026-06-03 18:00:00', 'first_punch_method' => 'face', 'last_punch_method' => 'physical_card', 'status' => 'present'],
    ]];

    $this->postJson('/api/v1/attendance/sync', $payload, apiKey())->assertOk()->assertJsonPath('synced', 1);

    $row = AttendanceDailySummary::where('employee_id', $emp->id)->first();
    expect($row->first_punch_method)->toBe('face');
    expect($row->last_punch_method)->toBe('physical_card');
});

test('attendance sync accepts a single bare record and is idempotent', function () {
    $employee = Employee::factory()->create(['employee_code' => 303]);

    $single = ['employee_code' => 303, 'date' => '2026-06-02', 'working_hours' => 8, 'status' => 'present'];

    $this->postJson('/api/v1/attendance/sync', $single, apiKey())->assertOk()->assertJsonPath('synced', 1);

    // Re-sync the same day with updated figures — must update, not duplicate.
    $single['working_hours'] = 9;
    $this->postJson('/api/v1/attendance/sync', $single, apiKey())->assertOk();

    expect(AttendanceDailySummary::where('employee_id', $employee->id)->count())->toBe(1);
    expect(AttendanceDailySummary::where('employee_id', $employee->id)->first()->working_hours)->toBe('9.00');
});

test('attendance sync skips unknown employee codes and reports 207', function () {
    Employee::factory()->create(['employee_code' => 304]);

    $payload = ['records' => [
        ['employee_code' => 304, 'date' => '2026-06-03', 'status' => 'present'],
        ['employee_code' => 99999, 'date' => '2026-06-03', 'status' => 'present'],
    ]];

    $this->postJson('/api/v1/attendance/sync', $payload, apiKey())
        ->assertStatus(207)
        ->assertJsonPath('synced', 1)
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('skipped_records.0.employee_code', 99999);

    expect(AttendanceDailySummary::count())->toBe(1);
});

test('attendance sync rejects an invalid payload', function () {
    $this->postJson('/api/v1/attendance/sync', ['records' => [['date' => 'not-a-date']]], apiKey())
        ->assertStatus(422);
});
