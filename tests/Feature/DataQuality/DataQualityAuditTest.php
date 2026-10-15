<?php

use App\Console\Commands\QaRecordDataQuality;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\JobTitle;
use App\Models\Permission;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Models\WorkMode;
use App\Services\DataQuality\DataQualityAuditor;
use App\Services\DataQuality\DataQualityIssue;
use App\Services\Qa\QaReadiness;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * Phase 2 — org structure & data quality. The auditor and hrms:data-quality
 * are read-only; these pin each detection and that nothing is written.
 */
function dqUser(string $slug, array $attributes = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, ...$attributes]);
}

/** A complete, active employee: every HR field set. */
function dqEmployee(Department $department, ?User $manager, string $role = 'employee', array $attributes = [], array $userAttributes = []): Employee
{
    $user = dqUser($role, $userAttributes);

    return Employee::factory()->create([
        'user_id' => $user->id,
        'department_id' => $department->id,
        'manager_id' => $manager?->id,
        'status' => 'active',
        'shift_id' => test()->shift->id,
        'job_title_id' => test()->jobTitle->id,
        'employment_type_id' => test()->employmentType->id,
        'work_mode_id' => test()->workMode->id,
        'salary_cycle_id' => null,
        ...$attributes,
    ])->fresh();
}

/** @return Collection<int, DataQualityIssue> */
function dqIssues(string $check): Collection
{
    return app(DataQualityAuditor::class)->issues()->where('check', $check)->values();
}

beforeEach(function () {
    $this->shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00']);
    $this->jobTitle = JobTitle::factory()->create();
    $this->employmentType = EmploymentType::create(['name' => 'DQ Full-time', 'slug' => 'dq-full-time', 'is_active' => true]);
    $this->workMode = WorkMode::create(['name' => 'DQ Office', 'slug' => 'dq-office', 'is_active' => true]);
    $this->ops = Department::factory()->create(['name' => 'DQ Operations']);
    $this->sales = Department::factory()->create(['name' => 'DQ Sales']);
    $this->manager = dqEmployee($this->ops, null, 'manager')->user;
    $this->ops->update(['head_id' => $this->manager->id]);
});

test('a complete employee raises no employee-level issue', function () {
    $employee = dqEmployee($this->ops, $this->manager);

    $mine = app(DataQualityAuditor::class)->issues()->where('employeeId', $employee->id);
    expect($mine->pluck('check')->all())->toBe([]);
});

test('missing manager, department, shift and job title are detected', function () {
    $noManager = dqEmployee($this->ops, null);
    $noDepartment = dqEmployee($this->ops, $this->manager, attributes: ['department_id' => null]);
    $noShift = dqEmployee($this->ops, $this->manager, attributes: ['shift_id' => null]);
    $noTitle = dqEmployee($this->ops, $this->manager, attributes: ['job_title_id' => null]);

    expect(dqIssues('no_manager')->pluck('employeeId'))->toContain($noManager->id)
        ->and(dqIssues('no_department')->pluck('employeeId'))->toContain($noDepartment->id)
        ->and(dqIssues('no_shift')->pluck('employeeId'))->toContain($noShift->id)
        ->and(dqIssues('no_job_title')->pluck('employeeId'))->toContain($noTitle->id)
        ->and(dqIssues('no_manager')->first()->severity)->toBe('high');
});

test('a company default shift covers employees without their own shift', function () {
    $this->shift->update(['is_default' => true]);
    $employee = dqEmployee($this->ops, $this->manager, attributes: ['shift_id' => null]);

    expect(dqIssues('no_shift')->pluck('employeeId'))->not->toContain($employee->id);
});

test('Directors and Super Admins at the top need no manager', function () {
    $director = dqEmployee($this->ops, null, 'director');
    $admin = dqEmployee($this->ops, null, 'super_admin');

    expect(dqIssues('no_manager')->pluck('employeeId'))->not->toContain($director->id)->not->toContain($admin->id);
});

test('the database itself refuses a dangling department and a duplicate employee code', function () {
    $employee = dqEmployee($this->ops, $this->manager, attributes: ['employee_code' => 4242]);

    expect(fn () => DB::table('employees')->where('id', $employee->id)->update(['department_id' => 999999]))
        ->toThrow(QueryException::class)
        ->and(fn () => dqEmployee($this->ops, $this->manager, attributes: ['employee_code' => 4242]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an employee who reports to themselves is critical', function () {
    $employee = dqEmployee($this->ops, null);
    $employee->update(['manager_id' => $employee->user_id]);

    expect(dqIssues('self_manager')->pluck('employeeId'))->toContain($employee->id)
        ->and(dqIssues('self_manager')->first()->severity)->toBe('critical');
});

test('a circular reporting chain is detected once', function () {
    $a = dqEmployee($this->ops, null, 'manager');
    $b = dqEmployee($this->ops, $a->user, 'manager');
    $c = dqEmployee($this->ops, $b->user, 'manager');
    $a->update(['manager_id' => $c->user_id]);

    $loops = dqIssues('circular_chain');
    expect($loops)->toHaveCount(1)
        ->and($loops->first()->message)->toContain($a->user->name)->toContain($b->user->name)->toContain($c->user->name);
});

test('reporting to a deleted or departed manager is flagged', function () {
    $gone = dqEmployee($this->ops, null, 'manager');
    $left = dqEmployee($this->ops, null, 'manager');
    $toGone = dqEmployee($this->ops, $gone->user);
    $toLeft = dqEmployee($this->ops, $left->user);
    $gone->user->delete();
    $left->update(['status' => 'resigned']);

    expect(dqIssues('inactive_manager')->pluck('employeeId')->all())->toContain($toGone->id, $toLeft->id);
});

test('a cross-department manager is REVIEW NEEDED when they can approve, INVALID when they cannot', function () {
    $salesManager = dqEmployee($this->sales, null, 'manager')->user;
    $plainEmployee = dqEmployee($this->sales, null)->user;
    $cross = dqEmployee($this->ops, $salesManager);
    $toPlain = dqEmployee($this->ops, $plainEmployee);
    $same = dqEmployee($this->ops, $this->manager);

    $auditor = app(DataQualityAuditor::class);
    expect($auditor->managerLine($same))->toBe('VALID')
        ->and($auditor->managerLine($cross))->toBe('REVIEW NEEDED')
        ->and($auditor->managerLine($toPlain))->toBe('INVALID')
        ->and(dqIssues('manager_invalid')->pluck('employeeId'))->toContain($toPlain->id)
        ->and(dqIssues('manager_cross_department')->pluck('employeeId'))->toContain($cross->id);
});

test('departments without a head, with an inactive head, or with several Department Heads are flagged', function () {
    $headless = Department::factory()->create(['name' => 'DQ Headless']);
    dqEmployee($headless, $this->manager);
    $formerHead = dqEmployee($this->sales, null, 'department_head');
    $this->sales->update(['head_id' => $formerHead->user_id]);
    $formerHead->user->delete();
    dqEmployee($headless, $this->manager, 'department_head');
    dqEmployee($headless, $this->manager, 'department_head');

    expect(dqIssues('department_no_head')->pluck('subjectId'))->toContain($headless->id)
        ->and(dqIssues('department_head_inactive')->pluck('subjectId'))->toContain($this->sales->id)
        ->and(dqIssues('department_multiple_heads')->pluck('subjectId'))->toContain($headless->id)
        ->and(dqIssues('department_head_no_department')->count())->toBeGreaterThanOrEqual(2);
});

test('role drift compares the database with the built-in defaults, without resetting anything', function () {
    $role = Role::where('slug', 'manager')->first();
    $removed = Permission::where('key', 'approve_wfh')->first();
    $added = Permission::where('key', 'run_payroll')->first();
    $role->permissions()->detach($removed->id);
    $role->permissions()->attach($added->id);
    $role->flushPermissionCache();

    $drift = collect(app(DataQualityAuditor::class)->roleDrift())->firstWhere('slug', 'manager');
    expect($drift['missing'])->toBe(['approve_wfh'])
        ->and($drift['extra'])->toBe(['run_payroll'])
        ->and(dqIssues('role_drift')->pluck('subjectLabel'))->toContain($role->name)
        ->and($role->fresh()->permissions->pluck('key'))->toContain('run_payroll')->not->toContain('approve_wfh');
});

test('per-user overrides are listed, and a privileged grant is high', function () {
    $hr = dqEmployee($this->ops, $this->manager, 'hr_admin')->user;
    UserPermissionOverride::create(['user_id' => $hr->id, 'permission_id' => Permission::where('key', 'manage_roles')->value('id'), 'effect' => 'grant']);
    UserPermissionOverride::create(['user_id' => $this->manager->id, 'permission_id' => Permission::where('key', 'view_reports')->value('id'), 'effect' => 'grant']);

    expect(dqIssues('user_override_privileged')->pluck('subjectId'))->toContain($hr->id)
        ->and(dqIssues('user_override_privileged')->first()->severity)->toBe('high')
        ->and(dqIssues('user_override')->pluck('subjectId'))->toContain($this->manager->id)
        ->and(collect(app(DataQualityAuditor::class)->overrides())->pluck('permission'))->toContain('manage_roles', 'view_reports');
});

test('a department-scoped grant with no departments is flagged', function () {
    $role = Role::where('slug', 'manager')->first();
    $permission = Permission::where('key', 'approve_leave')->first();
    $role->permissions()->updateExistingPivot($permission->id, ['scope' => 'selected_departments', 'department_ids' => json_encode([])]);
    $role->flushPermissionCache();

    expect(dqIssues('scope_without_departments')->pluck('subjectLabel'))->toContain($role->name);
});

test('a manager with no reports and a Department Head heading nothing are flagged', function () {
    $idle = dqEmployee($this->sales, null, 'manager')->user;
    $headOfNothing = dqEmployee($this->sales, null, 'department_head')->user;

    expect(dqIssues('manager_no_reports')->pluck('subjectId'))->toContain($idle->id)
        ->and(dqIssues('department_head_no_department')->pluck('subjectId'))->toContain($headOfNothing->id);
});

test('approval routing: every request type resolves, and a first approver without rights is flagged', function () {
    dqUser('hr_admin');
    $plain = dqEmployee($this->sales, null)->user;
    $employee = dqEmployee($this->sales, $plain);

    $row = collect(app(DataQualityAuditor::class)->routing())->firstWhere('employee_id', $employee->id);
    expect($row['first_approver'])->toBe($plain->name)
        ->and(collect($row['types'])->every(fn ($t) => $t['decider'] !== null))->toBeTrue()
        ->and(collect($row['types'])->every(fn ($t) => $t['first_can_decide'] === false))->toBeTrue()
        ->and(dqIssues('routing_first_line')->pluck('employeeId'))->toContain($employee->id)
        ->and(dqIssues('routing_unresolved'))->toBeEmpty();
});

test('an HR admin is never the first approver of their own request', function () {
    $hr = dqEmployee($this->ops, null, 'hr_admin');
    dqUser('hr_admin');

    $row = collect(app(DataQualityAuditor::class)->routing())->firstWhere('employee_id', $hr->id);
    expect($row['first_approver'])->not->toBe($hr->user->name)
        ->and(collect($row['types'])->pluck('decider'))->not->toContain($hr->user->name);
});

test('a custom role with no users and no purpose is flagged', function () {
    Role::create(['name' => 'Someone Sakpal', 'slug' => 'someone-sakpal', 'is_system' => false, 'is_active' => true]);

    expect(dqIssues('custom_role_unused')->pluck('subjectLabel'))->toContain('Someone Sakpal')
        ->and(dqIssues('custom_role_no_purpose')->pluck('subjectLabel'))->toContain('Someone Sakpal');
});

test('hrms:data-quality is read-only: no writes reach the database', function () {
    dqEmployee($this->ops, null);
    $tables = ['users', 'employees', 'departments', 'attendances', 'leave_requests', 'payrolls', 'roles', 'role_permission', 'user_permission_overrides', 'audit_logs'];
    $tables = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('hrms:data-quality')->assertSuccessful()->expectsOutputToContain('Nothing was changed');
    $this->artisan('hrms:data-quality', ['--json' => true])->assertSuccessful();
    $this->artisan('hrms:data-quality', ['--csv' => true, '--severity' => 'high'])->assertSuccessful();

    expect($writes)->toBe([])
        ->and(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before)
        // There is no --fix mode.
        ->and(fn () => Artisan::call('hrms:data-quality', ['--fix' => true]))->toThrow(InvalidOptionException::class);
});

test('hrms:data-quality filters by department, employee and severity', function () {
    $noManager = dqEmployee($this->sales, null, attributes: ['employee_code' => 7781]);

    $this->artisan('hrms:data-quality', ['--employee' => '7781'])->assertSuccessful()->expectsOutputToContain('Active employee without reporting manager');
    $this->artisan('hrms:data-quality', ['--department' => 'DQ Sales', '--severity' => 'critical'])->assertSuccessful()->doesntExpectOutputToContain('Active employee without reporting manager');
    $this->artisan('hrms:data-quality', ['--severity' => 'nonsense'])->assertExitCode(2);
    $this->artisan('hrms:data-quality', ['--employee' => 'nobody-here'])->assertExitCode(2);
});

test('qa:record-data-quality files data issues as HR corrections, never as code bugs, and verifies them once fixed', function () {
    $employee = dqEmployee($this->ops, null);
    $employeesBefore = Employee::count();
    $codeCountsBefore = (new QaReadiness)->summary()['counts'];

    $this->artisan('qa:record-data-quality', ['--environment' => 'staging'])->assertSuccessful();

    // Data checks are HR corrections, not code results: the test counts and scores do not move.
    $after = (new QaReadiness)->summary();
    expect([$after['counts']['total'], $after['counts']['failed'], $after['scores']['overall']])
        ->toBe([$codeCountsBefore['total'], $codeCountsBefore['failed'], 100])
        ->and($after['open_blockers']['hr_corrections'])->toBeGreaterThan(0)
        ->and($after['latest_run']->name)->toBe('2026-10-15 Phase 1 RBAC Regression');

    $code = QaRecordDataQuality::code('no_manager');
    $finding = QaFinding::where('code', $code)->firstOrFail();
    expect($finding->kind)->toBe('data')
        ->and($finding->status->value)->toBe('hr_correction_required')
        ->and($finding->description)->toContain($employee->user->name)
        ->and(QaTestCase::where('code', $code)->first()->status->value)->toBe('fail')
        ->and(QaTestCase::where('code', QaRecordDataQuality::code('self_manager'))->first()->status->value)->toBe('pass')
        ->and(Employee::count())->toBe($employeesBefore);

    // HR sets the manager (outside the tracker); the next run verifies the finding.
    Employee::query()->where('status', 'active')->whereNull('manager_id')->update(['manager_id' => $this->manager->id]);
    Employee::query()->where('id', $this->manager->employee->id)->update(['manager_id' => null]);
    $this->manager->update(['role' => UserRole::Director, 'role_id' => Role::where('slug', 'director')->value('id')]);
    $this->artisan('qa:record-data-quality', ['--environment' => 'staging'])->assertSuccessful();

    expect($finding->fresh()->status->value)->toBe('verified')
        ->and(QaTestCase::where('code', $code)->first()->results()->count())->toBe(2);
});
