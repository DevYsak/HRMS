<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicate shifts, and how they got there.
 *
 * The importer treated a shift as its name. An HR sheet whose shift column read
 * "10.30 AM to 7.30 PM" did not match a shift called "IT Shift", so a second
 * one was created for the identical 10:30–19:30 window — and every employee on
 * that column landed on the copy. Two shifts, one schedule, and a dropdown that
 * asks HR to guess.
 *
 * A shift defines working time and nothing else. It must never be read as a
 * holiday calendar, a country, or an entitlement.
 */
function sdcShift(string $name, string $start, string $end, int $grace = 5): ShiftSetting
{
    return ShiftSetting::create([
        'name' => $name,
        'start_time' => $start,
        'end_time' => $end,
        'grace_minutes' => $grace,
    ]);
}

function sdcEmployee(ShiftSetting $shift): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'shift_id' => $shift->id,
        'status' => 'active',
    ]);
}

// ── 1. Finding them ────────────────────────────────────────────────────────

test('the audit finds shifts that share a schedule under different names', function () {
    sdcShift('IT Shift', '10:30', '19:30');
    sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    sdcShift('UK Sales Shift', '13:00', '22:00');

    $this->artisan('shifts:audit')
        ->expectsOutputToContain('duplicate schedule group')
        ->assertSuccessful();
});

test('the audit reports nothing when every schedule is distinct', function () {
    sdcShift('IT Shift', '10:30', '19:30');
    sdcShift('UK Sales Shift', '13:00', '22:00');

    $this->artisan('shifts:audit')
        ->expectsOutputToContain('No duplicate schedules found')
        ->assertSuccessful();
});

test('the audit writes nothing', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    $employee = sdcEmployee($duplicate);

    $this->artisan('shifts:audit')->assertSuccessful();

    expect($employee->fresh()->shift_id)->toBe($duplicate->id)
        ->and(ShiftSetting::count())->toBe(2)
        ->and($canonical->fresh()->trashed())->toBeFalse();
});

// ── 2. Merging ─────────────────────────────────────────────────────────────

test('a dry run changes nothing', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    $employee = sdcEmployee($duplicate);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
    ])->expectsOutputToContain('DRY RUN')->assertSuccessful();

    expect($employee->fresh()->shift_id)->toBe($duplicate->id)
        ->and($duplicate->fresh()->trashed())->toBeFalse();
});

test('applying reassigns employees and archives the duplicate', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    $employee = sdcEmployee($duplicate);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($employee->fresh()->shift_id)->toBe($canonical->id)
        ->and($duplicate->fresh()->trashed())->toBeTrue()
        ->and($canonical->fresh()->trashed())->toBeFalse();
});

test('the duplicate is archived, never deleted', function () {
    // A hard delete would fire the SET NULL foreign key and strip the shift
    // from anyone the reassignment missed.
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect(ShiftSetting::withTrashed()->find($duplicate->id))->not->toBeNull()
        ->and(ShiftSetting::find($duplicate->id))->toBeNull();
});

test('an archived shift is no longer offered for assignment', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    $selectable = ShiftSetting::pluck('name');

    expect($selectable)->toContain('IT Shift')
        ->and($selectable)->not->toContain('10.30 AM to 7.30 PM');
});

test('merging two genuinely different schedules is refused', function () {
    // Attendance does not store the shift it was scored against, so moving
    // somebody between real schedules silently rescores their history.
    $morning = sdcShift('IT Shift', '10:30', '19:30');
    $evening = sdcShift('UK Sales Shift', '13:00', '22:00');

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $morning->id,
        '--duplicate' => $evening->id,
    ])->expectsOutputToContain('not the same schedule')->assertFailed();

    expect($evening->fresh()->trashed())->toBeFalse();
});

test('a differing grace period also blocks the merge', function () {
    $a = sdcShift('IT Shift', '10:30', '19:30', grace: 5);
    $b = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30', grace: 15);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $a->id,
        '--duplicate' => $b->id,
    ])->assertFailed();

    expect($b->fresh()->trashed())->toBeFalse();
});

test('the merge is audited against each employee moved', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');
    $employee = sdcEmployee($duplicate);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    $log = AuditLog::where('action', 'shift.reassigned')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->old_values['shift_id'])->toBe($duplicate->id)
        ->and($log->new_values['shift_id'])->toBe($canonical->id)
        ->and($log->subject_employee_id)->toBe($employee->id)
        ->and(AuditLog::where('action', 'shift.archived')->exists())->toBeTrue();
});

test('an approver scoped to the duplicate is repointed, not silently narrowed', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');

    $approver = User::factory()->create([
        'role' => UserRole::Manager,
        'scope_shifts' => [$duplicate->id],
    ]);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($approver->fresh()->scope_shifts)->toBe([$canonical->id]);
});

// ── 3. The importer must stop creating them ────────────────────────────────

test('import does not create a second shift for an existing schedule', function () {
    sdcShift('IT Shift', '10:30', '19:30');

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([[
        'employee_id' => 'CNS900',
        'first_name' => 'Schedule',
        'email' => 'schedule@conexus-ns.com',
        'joining_date' => '2026-07-01',
        'shift' => '10.30 AM to 7.30 PM',
    ]]);

    $service->import($parsed, 'skip', User::factory()->create(), null, autoCreateMasterData: true);

    expect(ShiftSetting::count())->toBe(1)
        ->and(ShiftSetting::first()->name)->toBe('IT Shift');
});

test('import resolves a spelled-out shift to the canonical one', function () {
    $canonical = sdcShift('IT Shift', '10:30', '19:30');

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([[
        'employee_id' => 'CNS901',
        'first_name' => 'Resolved',
        'email' => 'resolved@conexus-ns.com',
        'joining_date' => '2026-07-01',
        'shift' => '10.30 AM to 7.30 PM',
    ]]);

    $service->import($parsed, 'skip', User::factory()->create(), null, autoCreateMasterData: true);

    $employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'resolved@conexus-ns.com'))->first();

    expect($employee)->not->toBeNull()
        ->and($employee->shift_id)->toBe($canonical->id);
});

test('a genuinely new schedule is still created', function () {
    sdcShift('IT Shift', '10:30', '19:30');

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse([[
        'employee_id' => 'CNS902',
        'first_name' => 'Night',
        'email' => 'night@conexus-ns.com',
        'joining_date' => '2026-07-01',
        'shift' => '10:00 PM to 6:00 AM',
    ]]);

    $service->import($parsed, 'skip', User::factory()->create(), null, autoCreateMasterData: true);

    expect(ShiftSetting::count())->toBe(2);
});

// ── 4. A shift is working time and nothing else ────────────────────────────

test('merging shifts does not touch the holiday calendar', function () {
    // Shift, holiday calendar, working pattern and leave policy stay
    // independent. The calendar resolves employee → office → company, and no
    // part of this cleanup may reach into it.
    $canonical = sdcShift('IT Shift', '10:30', '19:30');
    $duplicate = sdcShift('10.30 AM to 7.30 PM', '10:30', '19:30');

    $employee = sdcEmployee($duplicate);
    $employee->update(['holiday_calendar' => 'UK']);

    $this->artisan('shifts:merge-duplicates', [
        '--canonical' => $canonical->id,
        '--duplicate' => $duplicate->id,
        '--apply' => true,
    ])->assertSuccessful();

    expect($employee->fresh()->holiday_calendar)->toBe('UK')
        ->and($employee->fresh()->shift_id)->toBe($canonical->id);
});

test('the shift table carries no calendar or country of its own', function () {
    // Structural: if a shift ever grows one of these, something will read it.
    $columns = collect(Schema::getColumnListing('shift_settings'));

    expect($columns)->not->toContain('holiday_calendar')
        ->and($columns)->not->toContain('country')
        ->and($columns)->not->toContain('leave_policy_id');
});
