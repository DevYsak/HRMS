<?php

use App\Livewire\Attendance\LiveAttendance;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\LiveAttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;

/**
 * Live Attendance: view_live_attendance decides WHAT opens, its scope WHO is
 * shown (company / department / team / own), filters only narrow inside the
 * scope, and every figure comes from the canonical attendance services.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));   // a Tuesday
    $this->shift = ShiftSetting::create([
        'name' => 'IT Shift', 'code' => 'LA_IT', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);
    $this->sales = Department::factory()->create(['name' => 'UK Sales']);
    $this->production = Department::factory()->create(['name' => 'Production']);
});

function laUser(string $slug, Department $department, ?User $manager = null, ?string $name = null): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, 'name' => $name ?? fake()->name()]);
    Employee::factory()->create([
        'user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active',
        'manager_id' => $manager?->id, 'shift_id' => test()->shift->id, 'joining_date' => '2024-01-01',
    ]);

    return $user->fresh();
}

function laPunch(User $user, string $time, string $method, string $source = 'biometric'): void
{
    AttendancePunch::create([
        'employee_id' => $user->employee->id, 'punched_at' => "2026-07-14 {$time}", 'punch_date' => '2026-07-14',
        'method' => $method, 'source' => $source, 'direction' => $source === 'regularisation' ? ($method === 'face' ? 'in' : 'out') : null,
    ]);
    app(AttendanceDayRebuilder::class)->rebuild($user->employee, Carbon::parse('2026-07-14'));
}

/** @return array<int, string> names on the Today Status list */
function laNames(User $viewer, array $filters = []): array
{
    return collect(app(LiveAttendanceService::class)->snapshot($viewer, $filters)['rows'])->pluck('name')->sort()->values()->all();
}

test('the permission is in Roles & Permissions under Attendance, scoped', function () {
    $permission = Permission::where('key', 'view_live_attendance')->firstOrFail();

    expect($permission->label)->toBe('View Live Attendance')
        ->and($permission->module)->toBe('Attendance')
        ->and((bool) $permission->is_scoped)->toBeTrue();
});

test('HR (company scope) sees every department', function () {
    $hr = laUser('hr_admin', $this->sales, name: 'Hana HR');
    laUser('employee', $this->sales, name: 'Sam Sales');
    laUser('employee', $this->production, name: 'Pat Production');

    $snapshot = app(LiveAttendanceService::class)->snapshot($hr);
    expect(laNames($hr))->toContain('Sam Sales', 'Pat Production')
        ->and($snapshot['departments']->pluck('name')->all())->toContain('UK Sales', 'Production');
});

test('a Department Head sees only the department they head', function () {
    $head = laUser('department_head', $this->sales, name: 'Nikita Head');
    $this->sales->update(['head_id' => $head->id]);
    laUser('employee', $this->sales, name: 'Sam Sales');
    laUser('employee', $this->production, name: 'Pat Production');

    $snapshot = app(LiveAttendanceService::class)->snapshot($head);
    expect(laNames($head))->toContain('Sam Sales')->not->toContain('Pat Production')
        ->and($snapshot['departments']->pluck('name')->all())->toBe(['UK Sales']);
});

test('a manager sees their own team only', function () {
    $manager = laUser('manager', $this->sales, name: 'Mona Manager');
    laUser('employee', $this->sales, $manager, 'Ari Report');
    laUser('employee', $this->sales, name: 'Other Colleague');

    expect(laNames($manager))->toContain('Ari Report')->not->toContain('Other Colleague');
});

test('a coordinator follows the scope configured in Roles & Permissions', function () {
    $coordinator = laUser('coordinator', $this->sales, name: 'Cora Coordinator');
    laUser('employee', $this->sales, name: 'Sam Sales');
    laUser('employee', $this->production, name: 'Pat Production');

    // Default for the role: their department.
    expect(laNames($coordinator))->toContain('Sam Sales')->not->toContain('Pat Production');

    // An admin reconfigures the grant to Production only.
    $role = Role::where('slug', 'coordinator')->first();
    DB::table('role_permission')->where('role_id', $role->id)
        ->where('permission_id', Permission::where('key', 'view_live_attendance')->value('id'))
        ->update(['scope' => 'selected_departments', 'department_ids' => json_encode([$this->production->id])]);
    $role->flushPermissionCache();
    Once::flush();

    expect(laNames($coordinator->fresh()))->toContain('Pat Production')->not->toContain('Sam Sales');
});

test('an employee without the permission gets 403, by URL and component', function () {
    $employee = laUser('employee', $this->sales);

    $this->actingAs($employee)->get(route('attendance.live'))->assertForbidden();
    Livewire::actingAs($employee)->test(LiveAttendance::class)->assertForbidden();
});

test('a user-level override grants the panel with its own scope', function () {
    $employee = laUser('employee', $this->sales, name: 'Owen Override');
    laUser('employee', $this->sales, name: 'Sam Sales');
    UserPermissionOverride::create([
        'user_id' => $employee->id, 'permission_id' => Permission::where('key', 'view_live_attendance')->value('id'),
        'effect' => 'grant', 'scope' => 'own',
    ]);

    $this->actingAs($employee)->get(route('attendance.live'))->assertOk();
    expect(laNames($employee))->toBe(['Owen Override']);
});

test('a department filter in the URL cannot reach a department outside the scope', function () {
    $head = laUser('department_head', $this->sales, name: 'Nikita Head');
    $this->sales->update(['head_id' => $head->id]);
    laUser('employee', $this->production, name: 'Pat Production');

    expect(laNames($head, ['department' => $this->production->id]))->toBe([]);

    Livewire::actingAs($head)->withQueryParams(['department' => (string) $this->production->id, 'tab' => 'status'])
        ->test(LiveAttendance::class)
        ->assertOk()
        ->assertDontSee('Pat Production')
        ->assertSee('No employees match these filters.');
});

test('new biometric activity appears on the next poll', function () {
    $hr = laUser('hr_admin', $this->sales);
    $sam = laUser('employee', $this->sales, name: 'Sam Sales');

    $component = Livewire::actingAs($hr)->test(LiveAttendance::class, ['tab' => 'activity'])
        ->assertSee('No attendance activity yet today.');

    laPunch($sam, '10:42:18', 'face');

    $component->call('$refresh')
        ->assertSee('Sam Sales')
        ->assertSee('10:42:18 AM')
        ->assertSeeHtml('wire:poll.30s');
});

test('late follows the assigned shift and its grace period', function () {
    $hr = laUser('hr_admin', $this->sales);
    $onTime = laUser('employee', $this->sales, name: 'On Time Olly');
    $late = laUser('employee', $this->sales, name: 'Late Lara');
    laPunch($onTime, '10:35:00', 'face');   // 10:30 + 5 min grace
    laPunch($late, '10:36:00', 'face');

    $activity = collect(app(LiveAttendanceService::class)->snapshot($hr)['activity'])->keyBy('employee');
    $rows = collect(app(LiveAttendanceService::class)->snapshot($hr)['rows'])->keyBy('name');

    expect($activity['On Time Olly']['status'])->toBe('On Time')
        ->and($activity['Late Lara']['status'])->toBe('Late')
        ->and($rows['Late Lara']['current'])->toBe('Late')
        ->and($rows['On Time Olly']['current'])->toBe('Working');
});

test('an OUT followed by a return is a break; the final OUT is checked out', function () {
    $this->travelTo(Carbon::parse('2026-07-14 20:00:00'));
    $hr = laUser('hr_admin', $this->sales);
    $sam = laUser('employee', $this->sales, name: 'Sam Sales');
    foreach (['10:25:00' => 'face', '13:30:00' => 'id_card', '14:05:00' => 'face', '19:40:00' => 'id_card'] as $t => $m) {
        laPunch($sam, $t, $m);
    }

    $events = collect(app(LiveAttendanceService::class)->snapshot($hr)['activity'])->keyBy('time');

    expect($events['01:30:00 PM']['event'])->toBe('BREAK')
        ->and($events['02:05:00 PM']['status'])->toBe('Back from break')
        ->and($events['07:40:00 PM']['event'])->toBe('OUT')
        ->and($events['07:40:00 PM']['status'])->toBe('Checked Out')
        ->and($events['07:40:00 PM']['source'])->toBe('ID Card');
});

test('an auto checkout is identified as such', function () {
    $hr = laUser('hr_admin', $this->sales);
    $sam = laUser('employee', $this->sales, name: 'Sam Sales');
    laPunch($sam, '10:25:00', 'face');

    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    $snapshot = app(LiveAttendanceService::class)->snapshot($hr);
    $auto = collect($snapshot['activity'])->firstWhere('event', 'AUTO CHECKOUT');

    expect($auto)->not->toBeNull()
        ->and($auto['time'])->toBe('07:30:00 PM')
        ->and($auto['source'])->toBe('System')
        ->and(collect($snapshot['rows'])->firstWhere('name', 'Sam Sales')['current'])->toBe('Auto Checkout');
});

test('a regularised punch is identified as such', function () {
    $hr = laUser('hr_admin', $this->sales);
    $sam = laUser('employee', $this->sales, name: 'Sam Sales');
    laPunch($sam, '10:25:00', 'face', 'regularisation');

    $event = collect(app(LiveAttendanceService::class)->snapshot($hr)['activity'])->firstWhere('employee', 'Sam Sales');

    expect($event['event'])->toBe('REGULARISED');
});

test('a double scan shows once', function () {
    $hr = laUser('hr_admin', $this->sales);
    $sam = laUser('employee', $this->sales, name: 'Sam Sales');
    laPunch($sam, '10:25:00', 'face');
    laPunch($sam, '10:25:40', 'face');   // the reader fired twice

    $events = collect(app(LiveAttendanceService::class)->snapshot($hr)['activity'])->where('employee', 'Sam Sales');

    expect($events)->toHaveCount(1)
        ->and($events->pluck('key')->unique())->toHaveCount(1);
});

test('the activity list is capped at 20 until "View more activity"', function () {
    $hr = laUser('hr_admin', $this->sales);
    foreach (range(1, 11) as $i) {
        $u = laUser('employee', $this->sales);
        laPunch($u, sprintf('10:%02d:00', 10 + $i), 'face');
        laPunch($u, sprintf('11:%02d:00', 10 + $i), 'id_card');
    }

    $component = Livewire::actingAs($hr)->test(LiveAttendance::class, ['tab' => 'activity']);
    expect(substr_count($component->html(), 'data-live-event='))->toBe(20);
    $component->assertSee('View more activity')->call('viewMoreActivity');
    expect(substr_count($component->html(), 'data-live-event='))->toBe(22);
});

test('a poll costs a bounded number of queries, whatever the headcount', function () {
    $hr = laUser('hr_admin', $this->sales);
    $count = function () use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(LiveAttendanceService::class)->snapshot($hr);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    foreach (range(1, 3) as $i) {
        laPunch(laUser('employee', $this->sales), '10:20:00', 'face');
    }
    $few = $count();

    foreach (range(1, 12) as $i) {
        laPunch(laUser('employee', $i % 2 ? $this->sales : $this->production), '10:20:00', 'face');
    }
    $many = $count();

    expect($many - $few)->toBeLessThanOrEqual(3);
});

test('Team View and the attendance pages offer the Live Attendance button to permission holders only', function () {
    $hr = laUser('hr_admin', $this->sales);
    $employee = laUser('employee', $this->sales);

    $this->actingAs($hr)->get(route('attendance.employees'))->assertOk()->assertSee('data-live-attendance-button', false);
    $this->actingAs($employee)->get(route('attendance.my'))->assertDontSee('data-live-attendance-button', false);
});
