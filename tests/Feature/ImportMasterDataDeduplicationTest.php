<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\EmployeeImportService;

/**
 * Auto-created master data must be deduplicated across rows.
 *
 * A 28-row org import references the same designation many times — six of them
 * appear twice in the Conexus roster alone. If each row created its own record,
 * a single import would leave the Job Titles master full of near-identical
 * entries, and employees who share a job would point at different ids.
 *
 * The dataset here mirrors the real roster's shape: 5 departments, 22 unique
 * designations, 6 of which repeat.
 */
function imdRows(): array
{
    $roster = [
        ['Director', 'Executive Director'],
        ['UK Operations', 'Head of UK Operations'],
        ['UK Operations', 'Senior Account Manager'],
        ['IT Operations', 'Head of IT Operations'],
        ['IT Operations', 'Head of Technology'],
        ['Accounts', 'Head of Accounts'],
        ['UK Operations', 'Account Manager'],
        ['IT Operations', 'Sr. Web Developer'],
        ['IT Operations', 'Sr. Web Developer'],
        ['UK Operations', 'Business Sales Consultant'],
        ['IT Operations', 'WordPress Developer'],
        ['IT Operations', 'Creative Designer'],
        ['IT Operations', 'Project Coordinator'],
        ['Human Resources', 'HR Executive'],
        ['IT Operations', 'SEO Executive'],
        ['IT Operations', 'Flutter Developer'],
        ['IT Operations', 'Software Developer'],
        ['IT Operations', 'Software Developer'],
        ['IT Operations', 'SEO Executive'],
        ['IT Operations', 'Digital Marketing Executive'],
        ['IT Operations', 'Quality Analyst'],
        ['IT Operations', 'Social Media Executive'],
        ['IT Operations', 'Flutter Developer'],
        ['Human Resources', 'HR Executive'],
        ['UK Operations', 'Sales Executive'],
        ['IT Operations', 'IT Support'],
        ['IT Operations', 'Social Media Executive'],
        ['IT Operations', 'UI/UX Executive'],
    ];

    return collect($roster)->values()->map(fn (array $pair, int $i) => [
        'employee_id' => sprintf('DEMO%03d', $i + 1),
        'first_name' => 'Demo',
        'last_name' => sprintf('Employee%03d', $i + 1),
        'email' => sprintf('demo%03d@example.invalid', $i + 1),
        'department' => $pair[0],
        'designation' => $pair[1],
        'shift' => '10.30 AM to 7.30 PM',
        'joining_date' => '2026-01-01',
        'status' => 'inactive',
        'role' => 'employee',
    ])->all();
}

test('the demo dataset has the shape the test depends on', function () {
    $rows = imdRows();

    expect($rows)->toHaveCount(28)
        ->and(collect($rows)->pluck('department')->unique())->toHaveCount(5)
        ->and(collect($rows)->pluck('designation')->unique())->toHaveCount(22);

    $repeated = collect($rows)->countBy('designation')->filter(fn (int $n) => $n > 1);

    expect($repeated)->toHaveCount(6)
        ->and($repeated->keys()->sort()->values()->all())->toBe([
            'Flutter Developer', 'HR Executive', 'SEO Executive',
            'Social Media Executive', 'Software Developer', 'Sr. Web Developer',
        ]);
});

test('preview classifies all 28 demo rows as new with no errors', function () {
    $parsed = app(EmployeeImportService::class)->parse(imdRows());

    expect($parsed['summary']['total'])->toBe(28)
        ->and($parsed['summary']['new'])->toBe(28)
        ->and($parsed['summary']['update'])->toBe(0)
        ->and($parsed['summary']['error'])->toBe(0);
});

test('auto-create makes one master record per unique name, not one per row', function () {
    // The behaviour under test. 28 rows referencing 22 designations must
    // produce 22 job titles — not 28, and not 28 minus whatever happened to
    // already exist.
    $departmentsBefore = Department::count();
    $titlesBefore = JobTitle::count();
    $shiftsBefore = ShiftSetting::count();

    $service = app(EmployeeImportService::class);
    $parsed = $service->parse(imdRows());

    $log = $service->import($parsed, 'skip', User::factory()->create(), 'demo.xlsx', autoCreateMasterData: true);

    expect($log->imported)->toBe(28)
        ->and($log->failed)->toBe(0)
        ->and(Department::count() - $departmentsBefore)->toBe(5)
        ->and(JobTitle::count() - $titlesBefore)->toBe(22)
        // One shift name across all 28 rows.
        ->and(ShiftSetting::count() - $shiftsBefore)->toBe(1);
});

test('rows sharing a designation share the same job title id', function () {
    $service = app(EmployeeImportService::class);
    $parsed = $service->parse(imdRows());
    $service->import($parsed, 'skip', User::factory()->create(), 'demo.xlsx', autoCreateMasterData: true);

    // DEMO008 and DEMO009 are both Sr. Web Developer.
    $a = Employee::where('employee_id', 'DEMO008')->first();
    $b = Employee::where('employee_id', 'DEMO009')->first();

    expect($a->job_title_id)->not->toBeNull()
        ->and($a->job_title_id)->toBe($b->job_title_id)
        ->and(JobTitle::where('name', 'Sr. Web Developer')->count())->toBe(1);
});

test('every duplicated designation resolves to exactly one master record', function () {
    $service = app(EmployeeImportService::class);
    $parsed = $service->parse(imdRows());
    $service->import($parsed, 'skip', User::factory()->create(), 'demo.xlsx', autoCreateMasterData: true);

    foreach (['Sr. Web Developer', 'Software Developer', 'SEO Executive',
        'Flutter Developer', 'HR Executive', 'Social Media Executive'] as $name) {
        expect(JobTitle::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->count())
            ->toBe(1, "expected exactly one '{$name}'");
    }
});

test('every one of the 28 demo employees is mapped to a department and designation', function () {
    $service = app(EmployeeImportService::class);
    $parsed = $service->parse(imdRows());
    $service->import($parsed, 'skip', User::factory()->create(), 'demo.xlsx', autoCreateMasterData: true);

    $demo = Employee::where('employee_id', 'like', 'DEMO%')->get();

    expect($demo)->toHaveCount(28)
        ->and($demo->whereNull('department_id'))->toHaveCount(0)
        ->and($demo->whereNull('job_title_id'))->toHaveCount(0);
});

test('re-importing the same file creates no further master records', function () {
    // Idempotency of the master data, not of the employees: the second run
    // skips the rows but must not add another copy of any department or title.
    $service = app(EmployeeImportService::class);
    $actor = User::factory()->create();

    $service->import($service->parse(imdRows()), 'skip', $actor, 'demo.xlsx', autoCreateMasterData: true);

    $departments = Department::count();
    $titles = JobTitle::count();
    $shifts = ShiftSetting::count();

    $service->import($service->parse(imdRows()), 'skip', $actor, 'demo.xlsx', autoCreateMasterData: true);

    expect(Department::count())->toBe($departments)
        ->and(JobTitle::count())->toBe($titles)
        ->and(ShiftSetting::count())->toBe($shifts);
});

test('an existing master record is reused rather than duplicated', function () {
    // Requirement 1 and 2: detect what already exists.
    $existing = JobTitle::factory()->create(['name' => 'Quality Analyst']);
    $titlesBefore = JobTitle::count();

    $service = app(EmployeeImportService::class);
    $service->import($service->parse(imdRows()), 'skip', User::factory()->create(), 'demo.xlsx', autoCreateMasterData: true);

    expect(JobTitle::whereRaw('LOWER(TRIM(name)) = ?', ['quality analyst'])->count())->toBe(1)
        // 22 designations, one of which already existed.
        ->and(JobTitle::count() - $titlesBefore)->toBe(21)
        ->and(Employee::where('employee_id', 'DEMO021')->value('job_title_id'))->toBe($existing->id);
});
