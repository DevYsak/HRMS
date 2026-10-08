<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Performance probe — opt-in, never part of the normal suite:
 *
 *   PERF_PROBE=1 PERF_LABEL=before DB_DATABASE=hrms_test_w1 \
 *     php -d memory_limit=-1 vendor/bin/pest tests/Feature/Performance/PageLoadProbeTest.php
 *
 * Seeds a company-sized data set (120 employees, 30 days of attendance and
 * punches, pending requests, notifications, audit rows), then loads the main
 * pages as each role and records query count, duplicate queries, time and
 * memory to storage/logs/perf-<label>.json for before/after comparison.
 */
beforeEach(function () {
    if (! env('PERF_PROBE')) {
        $this->markTestSkipped('Performance probe — set PERF_PROBE=1 to run.');
    }
});

function perfWorld(): array
{
    $departments = Department::factory()->count(6)->create();
    $today = Carbon::today();
    $now = now();

    $employees = collect();
    foreach (range(1, 120) as $i) {
        $employees->push(Employee::factory()->create([
            'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
            'department_id' => $departments[$i % 6]->id,
            'status' => 'active',
            'manager_id' => null,
            'joining_date' => $today->copy()->subYears(2)->toDateString(),
        ]));
    }

    $viewers = [];
    foreach (['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'finance', 'employee'] as $slug) {
        $role = Role::where('slug', $slug)->firstOrFail();
        $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
        $employees->push(Employee::factory()->create([
            'user_id' => $user->id, 'department_id' => $departments[0]->id, 'status' => 'active', 'manager_id' => null,
            'joining_date' => $today->copy()->subYears(2)->toDateString(),
        ]));
        $viewers[$slug] = $user->fresh();
    }

    $departments[0]->update(['head_id' => $viewers['department_head']->id]);
    Employee::whereIn('id', $employees->take(15)->pluck('id'))->update(['manager_id' => $viewers['manager']->id]);

    // 30 days of attendance and two punches a day per employee (bulk insert).
    $attendance = [];
    $punches = [];
    foreach ($employees as $employee) {
        for ($d = 30; $d >= 0; $d--) {
            $day = $today->copy()->subDays($d);
            if ($day->isWeekend()) {
                continue;
            }
            $in = $day->copy()->setTime(10, random_int(20, 45));
            $out = $d === 0 ? null : $day->copy()->setTime(19, random_int(30, 59));
            $attendance[] = [
                'employee_id' => $employee->id, 'date' => $day->toDateString(),
                'check_in' => $in, 'check_out' => $out, 'status' => $in->minute > 35 ? 'late' : 'on_time',
                'is_late' => $in->minute > 35, 'total_hours' => $out ? round($in->diffInMinutes($out) / 60, 2) : 0,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $punches[] = ['employee_id' => $employee->id, 'employee_code' => $employee->id, 'punched_at' => $in, 'punch_date' => $day->toDateString(), 'method' => 'face', 'direction' => 'in', 'source' => 'biometric', 'created_at' => $now, 'updated_at' => $now];
            if ($out) {
                $punches[] = ['employee_id' => $employee->id, 'employee_code' => $employee->id, 'punched_at' => $out, 'punch_date' => $day->toDateString(), 'method' => 'card', 'direction' => 'out', 'source' => 'biometric', 'created_at' => $now, 'updated_at' => $now];
            }
        }
    }
    foreach (array_chunk($attendance, 500) as $chunk) {
        DB::table('attendances')->insert($chunk);
    }
    foreach (array_chunk($punches, 500) as $chunk) {
        DB::table('attendance_punches')->insert($chunk);
    }

    // Pending requests, notifications and an activity log.
    $leaveType = DB::table('leave_types')->insertGetId(['name' => 'Probe Leave', 'code' => 'PRB', 'category' => 'annual', 'is_paid' => true, 'created_at' => $now, 'updated_at' => $now]);
    $leaves = [];
    foreach ($employees->take(60) as $employee) {
        $start = $today->copy()->addDays(random_int(3, 30));
        $leaves[] = ['employee_id' => $employee->id, 'leave_type_id' => $leaveType, 'start_date' => $start, 'end_date' => $start, 'days' => 1, 'reason' => 'Probe', 'status' => 'pending', 'requested_leave_status' => 'paid', 'created_at' => $now, 'updated_at' => $now];
    }
    DB::table('leave_requests')->insert($leaves);

    $notifications = [];
    foreach ($viewers as $viewer) {
        foreach (range(1, 40) as $n) {
            $notifications[] = ['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\ProbeNotification', 'notifiable_type' => User::class, 'notifiable_id' => $viewer->id,
                'data' => json_encode(['title' => "Probe {$n}", 'message' => 'Probe']), 'read_at' => $n % 3 ? null : $now, 'created_at' => $now, 'updated_at' => $now];
        }
    }
    DB::table('notifications')->insert($notifications);

    $audit = [];
    foreach (range(1, 3000) as $n) {
        $audit[] = ['user_id' => $viewers['hr_admin']->id, 'module' => 'employee', 'category' => 'employee', 'event' => 'EMPLOYEE_UPDATED', 'action' => 'updated',
            'auditable_type' => Employee::class, 'auditable_id' => $employees->random()->id, 'created_at' => $now->copy()->subMinutes($n)];
    }
    foreach (array_chunk($audit, 500) as $chunk) {
        DB::table('audit_logs')->insert($chunk);
    }

    return $viewers;
}

/** @return array{queries: int, duplicates: int, ms: float, mb: float, status: int} */
function perfMeasure(callable $request): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    $t = hrtime(true);

    $status = $request();

    $ms = (hrtime(true) - $t) / 1e6;
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $signatures = array_map(fn ($q) => $q['query'].'|'.json_encode($q['bindings']), $log);

    return [
        'queries' => count($log),
        'duplicates' => count($signatures) - count(array_unique($signatures)),
        'ms' => round($ms, 1),
        'mb' => round((memory_get_peak_usage() - $base) / 1048576, 1),
        'status' => $status,
        'top_repeated' => collect($log)->countBy(fn ($q) => Str::limit(preg_replace('/\s+/', ' ', $q['query']), 140))->sortDesc()->take(5)->all(),
    ];
}

test('page load probe', function () {
    $viewers = perfWorld();

    $pages = [
        'super_admin' => ['dashboard', 'employees.index', 'employees.directory', 'attendance.employees', 'settings.audit-log', 'notifications.index'],
        'hr_admin' => ['dashboard', 'employees.index', 'attendance.employees', 'attendance.command-center', 'time-off.leave-management'],
        'director' => ['dashboard.director'],
        'department_head' => ['dashboard.department', 'attendance.team'],
        'manager' => ['dashboard.manager', 'attendance.team', 'employees.directory'],
        'finance' => ['dashboard.finance'],
        'employee' => ['dashboard', 'attendance.my', 'employees.directory', 'notifications.index'],
    ];

    $report = [];

    foreach ($pages as $slug => $routes) {
        $this->actingAs($viewers[$slug]);

        foreach ($routes as $route) {
            // Warm once (route/view compilation), then measure.
            $this->get(route($route));
            $report["{$slug} GET {$route}"] = perfMeasure(fn () => $this->get(route($route))->status());
        }
    }

    // A Livewire round-trip: the employee's My Attendance refresh.
    $this->actingAs($viewers['employee']);
    $component = Livewire::test(AttendanceTracker::class);
    $report['employee LIVEWIRE attendance.my $refresh'] = perfMeasure(function () use ($component) {
        $component->call('$refresh');

        return 200;
    });

    $label = env('PERF_LABEL', 'run');
    file_put_contents(storage_path("logs/perf-{$label}.json"), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $lines = collect($report)->map(fn ($r, $k) => sprintf('%-58s %4d q  %3d dup  %7.1f ms  %5.1f MB  [%d]', $k, $r['queries'], $r['duplicates'], $r['ms'], $r['mb'], $r['status']));
    fwrite(STDERR, "\n".$lines->implode("\n")."\n");

    expect($report)->not->toBeEmpty();
})->group('perf');
