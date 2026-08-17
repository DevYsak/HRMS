<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The four defects the employee dashboard shipped with.
 *
 * Each of these rendered a confident-looking number that was wrong, which is
 * worse than rendering nothing: an employee had no way to tell.
 */
function edbEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + ['user_id' => $user->id, 'status' => 'active']);
}

// ── 1. Leave balances ──────────────────────────────────────────────────────

test('the dashboard shows the leave balances an employee actually holds', function () {
    // The bug: leave_balances.year is a smallint holding 2026, not a date, so
    // whereYear() compiled to YEAR(year) — and YEAR(2026) is NULL in MySQL.
    // Every employee saw an empty balance list.
    $employee = edbEmployee();
    LeaveBalance::where('employee_id', $employee->id)->delete();

    $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLX', 'category' => 'annual']);
    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 12,
        'used_days' => 3,
    ]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leaveBalances', fn ($b) => $b->count() === 1
            && (int) $b->first()->allocated_days === 12);
});

test('a balance from another year is not shown as this year', function () {
    $employee = edbEmployee();
    LeaveBalance::where('employee_id', $employee->id)->delete();

    $type = LeaveType::create(['name' => 'Casual Leave', 'code' => 'CLY', 'category' => 'annual']);
    LeaveBalance::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year - 1,
        'allocated_days' => 12,
        'used_days' => 0,
    ]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leaveBalances', fn ($b) => $b->isEmpty());
});

// ── 2. Holiday country ─────────────────────────────────────────────────────

test('an India employee is not shown a UK bank holiday', function () {
    $employee = edbEmployee();
    $today = Carbon::today();

    PublicHoliday::create(['date' => $today->copy()->addDays(3)->toDateString(), 'name' => 'UK Bank Holiday', 'country' => 'UK']);
    PublicHoliday::create(['date' => $today->copy()->addDays(10)->toDateString(), 'name' => 'Diwali', 'country' => 'IN']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        // The UK holiday falls first, so a country-blind query returned it.
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h?->name === 'Diwali');
});

test('a UK employee sees the UK calendar', function () {
    $office = Office::factory()->create(['country' => 'United Kingdom']);
    $employee = edbEmployee(['office_id' => $office->id]);
    $today = Carbon::today();

    PublicHoliday::create(['date' => $today->copy()->addDays(3)->toDateString(), 'name' => 'Diwali', 'country' => 'IN']);
    PublicHoliday::create(['date' => $today->copy()->addDays(10)->toDateString(), 'name' => 'UK Bank Holiday', 'country' => 'UK']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h?->name === 'UK Bank Holiday');
});

test('the upcoming list and the next holiday agree', function () {
    // They were two separate queries, so they could disagree — and did, because
    // only one of them filtered anything.
    $employee = edbEmployee();
    $today = Carbon::today();

    PublicHoliday::create(['date' => $today->copy()->addDays(2)->toDateString(), 'name' => 'UK Bank Holiday', 'country' => 'UK']);
    PublicHoliday::create(['date' => $today->copy()->addDays(5)->toDateString(), 'name' => 'Holi', 'country' => 'IN']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('upcomingHolidays', fn ($h) => $h->pluck('name')->all() === ['Holi'])
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h?->name === 'Holi');
});

test('an archived holiday is not offered as the next one', function () {
    $employee = edbEmployee();
    $today = Carbon::today();

    PublicHoliday::create(['date' => $today->copy()->addDay()->toDateString(), 'name' => 'Withdrawn', 'country' => 'IN', 'is_active' => false]);
    PublicHoliday::create(['date' => $today->copy()->addDays(7)->toDateString(), 'name' => 'Holi', 'country' => 'IN']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h?->name === 'Holi');
});

test('a past holiday is never the next holiday', function () {
    $employee = edbEmployee();
    PublicHoliday::create(['date' => Carbon::today()->subDay()->toDateString(), 'name' => 'Gone', 'country' => 'IN']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h === null);
});

test('a holiday today still counts as the next holiday', function () {
    $employee = edbEmployee();
    PublicHoliday::create(['date' => Carbon::today()->toDateString(), 'name' => 'Today Off', 'country' => 'IN']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('nextPublicHoliday', fn ($h) => $h?->name === 'Today Off');
});

test('the resolver puts a UK-shift employee on the UK calendar', function () {
    // The existing fallback for employees whose office is unset. Kept as-is by
    // the extraction — this pins it so it cannot drift silently.
    $shift = ShiftSetting::create([
        'name' => 'UK Sales', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 10,
    ]);
    $employee = edbEmployee(['shift_id' => $shift->id]);

    expect(app(HolidayResolver::class)->resolveCountry($employee))->toBe('UK');
});

test('the resolver defaults to India when there is no employee at all', function () {
    expect(app(HolidayResolver::class)->resolveCountry(null))->toBe(HolidayResolver::DEFAULT_COUNTRY)
        ->and(HolidayResolver::DEFAULT_COUNTRY)->toBe('IN');
});

// ── 3. Weekly off ──────────────────────────────────────────────────────────

test('attendance percentage counts the company week, not Carbon weekends', function () {
    // isWeekend() is Saturday+Sunday. An office whose only weekly off is Sunday
    // lost every Saturday from the denominator, overstating attendance.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SUNDAY],
    ]);

    $employee = edbEmployee();

    // A fixed month so the count is deterministic: 1–7 June 2026 is Mon–Sun.
    $this->travelTo(Carbon::parse('2026-06-07 18:00:00'));

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        // Mon–Sat are working days under a Sunday-only week: 6, not 5.
        ->assertViewHas('workingDaysElapsed', 6);
});

test('a public holiday is not counted as a working day', function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SUNDAY],
    ]);

    $employee = edbEmployee();
    PublicHoliday::create(['date' => '2026-06-03', 'name' => 'Mid-week Holiday', 'country' => 'IN']);

    $this->travelTo(Carbon::parse('2026-06-07 18:00:00'));

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('workingDaysElapsed', 5);
});

// ── 4. Clock in / out ──────────────────────────────────────────────────────

test('an employee can clock in from the dashboard', function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => false, 'requires_photo' => false]);

    $employee = edbEmployee();

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->call('clockIn');

    $attendance = Attendance::where('employee_id', $employee->id)->first();

    expect($attendance)->not->toBeNull()
        ->and($attendance->check_in)->not->toBeNull()
        ->and($attendance->date->toDateString())->toBe(Carbon::today()->toDateString());
});

test('clocking in twice does not open a second day', function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => false]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)->call('clockIn')->call('clockIn');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

test('an employee can clock out from the dashboard', function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => false]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)->call('clockIn')->call('clockOut');

    expect(Attendance::where('employee_id', $employee->id)->first()->check_out)->not->toBeNull();
});

test('clocking out without clocking in does nothing', function () {
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => false]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)->call('clockOut');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('a punch that needs a selfie is handed to the attendance page, not recorded blind', function () {
    // The dashboard has no camera. Punching anyway would defeat the control
    // rather than enforce it — and coordinates do not substitute for a photo.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_photo' => true, 'requires_location' => false]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->call('clockIn', 19.076, 72.877)
        ->assertRedirect(route('attendance.my'));

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('a location-required punch succeeds when the browser supplies coordinates', function () {
    // requires_location defaults to true, so this is the live configuration.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => true]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)->call('clockIn', 19.076, 72.877);

    $attendance = Attendance::where('employee_id', $employee->id)->first();

    expect($attendance)->not->toBeNull()
        ->and((float) $attendance->check_in_lat)->toBe(19.076)
        ->and((float) $attendance->check_in_lng)->toBe(72.877);
});

test('a location-required punch with no coordinates is handed to the attendance page', function () {
    // Employee declined the browser prompt. Recording the punch without the
    // location would silently void the requirement.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => true]);

    $employee = edbEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->call('clockIn')
        ->assertRedirect(route('attendance.my'));

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('clocking in without an employee profile does not create attendance', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($user)->test(Dashboard::class)->call('clockIn');

    expect(Attendance::count())->toBe(0);
});
