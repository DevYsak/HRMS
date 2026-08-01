<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\EmployeeImportService;
use Illuminate\Support\Facades\Mail;

/**
 * Phase A of the HR data migration: hardening the employee importer against
 * the shapes real HR spreadsheets actually contain — trailing whitespace,
 * mixed/ordinal date formats, managers referenced by name rather than email,
 * a Company/Branch column, and a distinct Employee Code.
 */
function hrSheetService(): EmployeeImportService
{
    return app(EmployeeImportService::class);
}

test('company/branch resolves to the office and is stored on the employee', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $office = Office::factory()->create(['name' => 'Head Office']);
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS100', 'first_name' => 'Branch', 'email' => 'branch@example.com', 'joining_date' => '2026-07-01', 'company' => 'Head Office'],
    ]);
    $service->import($parsed, 'skip', $actor);

    $employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'branch@example.com'))->first();
    expect($employee->office_id)->toBe($office->id);
});

test('employee code is its own column and no longer piggybacks on the biometric pin', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS101', 'employee_code' => '5001', 'biometric_pin' => '77', 'first_name' => 'Coded', 'email' => 'coded@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor);

    $employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'coded@example.com'))->first();
    expect($employee->employee_code)->toBe(5001)
        ->and($employee->biometric_id)->toBe('77');
});

test('a sheet with only a biometric pin still populates employee code, for older templates', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS102', 'biometric_pin' => '88', 'first_name' => 'Legacy', 'email' => 'legacy@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor);

    $employee = Employee::whereHas('user', fn ($q) => $q->where('email', 'legacy@example.com'))->first();
    expect($employee->employee_code)->toBe(88);
});

test('master data matches despite trailing whitespace on either side', function () {
    // The HR sheet carries "UK Operations " and master data created from an
    // earlier import can carry the same trailing space.
    Department::factory()->create(['name' => 'UK Operations ']);
    ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30']);

    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS103', 'first_name' => 'Spaced', 'email' => 'spaced@example.com', 'joining_date' => '2026-07-01',
            'department' => '  UK Operations  ', 'shift' => ' IT Shift '],
    ]);

    expect($parsed['rows'][0]['warnings'])->toBeEmpty()
        ->and($parsed['rows'][0]['data']['employee']['department_id'])->not->toBeNull()
        ->and($parsed['rows'][0]['data']['employee']['shift_id'])->not->toBeNull();
});

test('the ordinal and day-first date formats HR actually uses all parse', function () {
    $rows = [];
    foreach (['11th Feb 2025', '18th June 2024', '23rd Sept 2024', '07-06-2010', '2026-07-01'] as $i => $date) {
        $rows[] = ['employee_id' => "CNS20{$i}", 'first_name' => "P{$i}", 'email' => "p{$i}@example.com", 'joining_date' => $date];
    }

    $parsed = hrSheetService()->parse($rows);

    expect(array_column($parsed['rows'], 'status'))->toBe(['new', 'new', 'new', 'new', 'new']);
    expect(array_map(fn ($r) => $r['data']['employee']['joining_date'], $parsed['rows']))
        ->toBe(['2025-02-11', '2024-06-18', '2024-09-23', '2010-06-07', '2026-07-01']);
});

test('dates PHP would silently corrupt are rejected rather than imported wrong', function () {
    // '2026' would become today's date with that year; '31-02-2025' would roll
    // over to 3 March. Both must block the row instead.
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS300', 'first_name' => 'BareYear', 'email' => 'bare@example.com', 'joining_date' => '2026'],
        ['employee_id' => 'CNS301', 'first_name' => 'Rollover', 'email' => 'roll@example.com', 'joining_date' => '31-02-2025'],
    ]);

    expect(array_column($parsed['rows'], 'status'))->toBe(['error', 'error']);
    expect(implode(' ', $parsed['rows'][0]['errors']))->toContain('not a valid date');
    expect(implode(' ', $parsed['rows'][1]['errors']))->toContain('not a valid date');
    expect($parsed['quality']['invalid_dates'])->toBe(2);
});

test('a reporting manager can be given by name or employee id, not just email', function () {
    $managerUser = User::factory()->create(['name' => 'Nikita Dalal', 'email' => 'nikita@example.com']);
    Employee::factory()->create(['user_id' => $managerUser->id, 'employee_id' => 'CNS007']);

    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS400', 'first_name' => 'ByName', 'email' => 'byname@example.com', 'joining_date' => '2026-07-01', 'reporting_manager' => 'Nikita Dalal '],
        ['employee_id' => 'CNS401', 'first_name' => 'ById', 'email' => 'byid@example.com', 'joining_date' => '2026-07-01', 'reporting_manager' => 'CNS007'],
        ['employee_id' => 'CNS402', 'first_name' => 'ByEmail', 'email' => 'byemail@example.com', 'joining_date' => '2026-07-01', 'reporting_manager' => 'nikita@example.com'],
    ]);

    foreach ($parsed['rows'] as $row) {
        expect($row['data']['employee']['manager_id'])->toBe($managerUser->id);
        expect($row['warnings'])->toBeEmpty();
    }
});

test('an ambiguous manager name warns instead of silently picking one', function () {
    User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi1@example.com']);
    User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi2@example.com']);

    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS500', 'first_name' => 'Ambiguous', 'email' => 'amb@example.com', 'joining_date' => '2026-07-01', 'reporting_manager' => 'Ravi Kumar'],
    ]);

    expect($parsed['rows'][0]['data']['employee']['manager_id'])->toBeNull()
        ->and($parsed['rows'][0]['status'])->toBe('new')
        ->and(implode(' ', $parsed['rows'][0]['warnings']))->toContain('matches 2 people');
});

test('the preview lists missing master data once, not once per row', function () {
    $rows = [];
    foreach (range(0, 2) as $i) {
        $rows[] = ['employee_id' => "CNS60{$i}", 'first_name' => "M{$i}", 'email' => "m{$i}@example.com",
            'joining_date' => '2026-07-01', 'department' => 'Nonexistent Dept', 'shift' => 'Ghost Shift'];
    }

    $parsed = hrSheetService()->parse($rows);

    expect($parsed['preflight']['Department'])->toBe(['Nonexistent Dept'])
        ->and($parsed['preflight']['Shift'])->toBe(['Ghost Shift']);
});

test('a missing email blocks the row and names the person so HR can fix it', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS700', 'first_name' => 'Nicholas', 'last_name' => 'Dsouza', 'joining_date' => '2026-07-01'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('error')
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('Nicholas Dsouza')
        ->and($parsed['quality']['missing_emails'])->toBe(1);
});

test('the live HR sheet headings import as-is, without renaming columns', function () {
    // Verbatim heading slugs from the Conexus-ns sheet: a single Employee Name
    // column, "Bio Code", "Manager", "Date of Joining", "DOB", "Mobile Number",
    // "Employment Status" — none of which match the template's own names.
    $parsed = hrSheetService()->parse([
        [
            'employee_id' => 'CNS005',
            'bio_code' => '28',
            'employee_name' => 'Gayatri Chagan Navlakhe',
            'department' => 'UK Operations ',
            'designation' => 'Senior Account Manager',
            'manager' => 'Nikita Dalal',
            'date_of_joining' => '07-06-2010',
            'dob' => '07-11-1989',
            'email' => 'Gayatri.navlakhe@gmail.com',
            'mobile_number' => '9821972873',
            'address' => '202, Saidarshan Apt',
            'shift' => '1PM to 10PM ',
            'employment_status' => 'Active',
        ],
    ]);

    $row = $parsed['rows'][0];
    $employee = $row['data']['employee'];

    expect($row['status'])->toBe('new')
        ->and($row['data']['name'])->toBe('Gayatri Chagan Navlakhe')
        ->and($row['data']['email'])->toBe('gayatri.navlakhe@gmail.com')
        ->and($employee['employee_code'])->toBe(28)
        ->and($employee['biometric_id'])->toBe('28')
        ->and($employee['phone'])->toBe('9821972873')
        ->and($employee['joining_date'])->toBe('2010-06-07')
        ->and($employee['date_of_birth'])->toBe('1989-11-07')
        ->and($employee['status'])->toBe('active');
});

test('a single full-name column is split into first, middle and last', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS900', 'employee_name' => 'Gayatri Chagan Navlakhe', 'email' => 'g@example.com', 'joining_date' => '2026-07-01'],
        ['employee_id' => 'CNS901', 'employee_name' => 'Mazhar Thakur', 'email' => 'm@example.com', 'joining_date' => '2026-07-01'],
        ['employee_id' => 'CNS902', 'employee_name' => 'Hasan', 'email' => 'h@example.com', 'joining_date' => '2026-07-01'],
    ]);

    expect(array_map(fn ($r) => $r['data']['name'], $parsed['rows']))
        ->toBe(['Gayatri Chagan Navlakhe', 'Mazhar Thakur', 'Hasan']);
    expect(array_column($parsed['rows'], 'status'))->toBe(['new', 'new', 'new']);
});

test('an explicit first/last name column still wins over a full-name column', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS903', 'first_name' => 'Real', 'last_name' => 'Name',
            'employee_name' => 'Ignored Entirely', 'email' => 'real@example.com', 'joining_date' => '2026-07-01'],
    ]);

    expect($parsed['rows'][0]['data']['name'])->toBe('Real Name');
});

test('managers that are rows in the same file link on a single import run', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS950', 'employee_name' => 'Top Boss', 'email' => 'boss@example.com', 'date_of_joining' => '2020-01-01'],
        ['employee_id' => 'CNS951', 'employee_name' => 'Middle Manager', 'email' => 'mid@example.com', 'date_of_joining' => '2021-01-01', 'manager' => 'Top Boss'],
        ['employee_id' => 'CNS952', 'employee_name' => 'Junior Person', 'email' => 'jun@example.com', 'date_of_joining' => '2022-01-01', 'manager' => 'CNS951'],
    ]);

    // Nobody exists yet, so nothing resolves at parse time — and that must not
    // produce a "manager not found" warning for someone present in the file.
    foreach ($parsed['rows'] as $row) {
        expect($row['data']['employee']['manager_id'])->toBeNull()
            ->and(implode(' ', $row['warnings']))->not->toContain('Reporting manager');
    }

    $service->import($parsed, 'skip', $actor);

    $boss = Employee::where('employee_id', 'CNS950')->first();
    $mid = Employee::where('employee_id', 'CNS951')->first();
    $jun = Employee::where('employee_id', 'CNS952')->first();

    expect($mid->manager_id)->toBe($boss->user_id)   // linked by name
        ->and($jun->manager_id)->toBe($mid->user_id) // linked by employee id
        ->and($boss->manager_id)->toBeNull();
});

test('a manager who is genuinely absent still warns', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS960', 'employee_name' => 'Orphan Row', 'email' => 'orphan@example.com',
            'joining_date' => '2026-07-01', 'manager' => 'Nobody At All'],
    ]);

    expect(implode(' ', $parsed['rows'][0]['warnings']))->toContain("Reporting manager 'Nobody At All' was not found");
});

test('duplicate employee codes within the file are caught', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS800', 'employee_code' => '9001', 'first_name' => 'First', 'email' => 'f1@example.com', 'joining_date' => '2026-07-01'],
        ['employee_id' => 'CNS801', 'employee_code' => '9001', 'first_name' => 'Second', 'email' => 'f2@example.com', 'joining_date' => '2026-07-01'],
    ]);

    expect($parsed['rows'][1]['status'])->toBe('error')
        ->and(implode(' ', $parsed['rows'][1]['errors']))->toContain('Duplicate Employee Code')
        ->and($parsed['quality']['duplicate_employee_codes'])->toBe(1);
});
