<?php

use App\Exports\EmployeesExport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\ProbationEngine;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

test('a missing email warns and names the person rather than blocking the row', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS700', 'first_name' => 'Nicholas', 'last_name' => 'Dsouza', 'joining_date' => '2026-07-01'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('new')
        ->and($parsed['rows'][0]['errors'])->toBeEmpty()
        ->and(implode(' ', $parsed['rows'][0]['warnings']))->toContain('Email Pending')
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

test('a row with no email imports with a generated address and an Email Pending flag', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS970', 'employee_name' => 'No Email Person', 'date_of_joining' => '2026-07-01'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('new')
        ->and($parsed['rows'][0]['data']['email'])->toBe('cns970@'.EmployeeImportService::PLACEHOLDER_EMAIL_DOMAIN)
        ->and($parsed['quality']['missing_emails'])->toBe(1);

    $service->import($parsed, 'skip', $actor);

    $employee = Employee::where('employee_id', 'CNS970')->first();
    expect($employee)->not->toBeNull()
        ->and($employee->has_placeholder_email)->toBeTrue()
        ->and($employee->dataFlags())->toContain('Email Pending');
});

test('importing a placeholder address mails nobody', function () {
    // A row with no email still gets a user record — the importer invents an
    // address so one can exist at all — and that address belongs to nobody.
    // Import mails no one now regardless, and the placeholder is additionally
    // refused at the invitation step; see EmployeeInvitationTest.
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS971', 'employee_name' => 'Placeholder Person', 'date_of_joining' => '2026-07-01'],
        ['employee_id' => 'CNS972', 'employee_name' => 'Real Person', 'email' => 'real.person@example.com', 'date_of_joining' => '2026-07-01'],
    ]);

    $service->import($parsed, 'skip', $actor, 'welcome.xlsx');

    Mail::assertNothingSent();
});

test('a row with no joining date imports and is flagged instead of rejected', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS973', 'employee_name' => 'No Date Person', 'email' => 'nodate@example.com'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('new')
        ->and($parsed['quality']['missing_joining_dates'])->toBe(1)
        ->and(implode(' ', $parsed['rows'][0]['warnings']))->toContain('Joining Date Missing');

    $service->import($parsed, 'skip', $actor);

    $employee = Employee::where('employee_id', 'CNS973')->first();
    expect($employee->joining_date)->toBeNull()
        ->and($employee->isMissingJoiningDate())->toBeTrue()
        ->and($employee->dataFlags())->toContain('Joining Date Missing');
});

test('probation is left unset for an employee with no joining date, not fabricated from today', function () {
    // Carbon::parse(null) silently returns now(), so without a guard this
    // employee would be given a probation period ending 90 days from today.
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id, 'joining_date' => null]);

    $engine = app(ProbationEngine::class);

    expect($engine->calculateEndDate($employee))->toBeNull();

    $engine->autoSetIfEnabled($employee);
    expect($employee->fresh()->probation_end_date)->toBeNull();
});

test('missing departments, designations and shifts are auto-created and linked when enabled', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS980', 'employee_name' => 'Auto Create', 'email' => 'auto@example.com', 'date_of_joining' => '2026-07-01',
            'department' => 'Brand New Dept', 'designation' => 'Brand New Role', 'shift' => '10.30 AM to 7.30 PM'],
    ]);

    $service->import($parsed, 'skip', $actor, null, autoCreateMasterData: true);

    $employee = Employee::with(['department', 'jobTitle', 'shift'])->where('employee_id', 'CNS980')->first();

    expect($employee->department?->name)->toBe('Brand New Dept')
        ->and($employee->jobTitle?->name)->toBe('Brand New Role')
        ->and($employee->shift?->name)->toBe('10.30 AM to 7.30 PM');

    // Shift hours are read out of the HR label rather than guessed.
    expect($employee->shift->start_time)->toStartWith('10:30')
        ->and($employee->shift->end_time)->toStartWith('19:30');
});

test('master data is left alone when auto-create is off', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $parsed = $service->parse([
        ['employee_id' => 'CNS981', 'employee_name' => 'No Auto', 'email' => 'noauto@example.com', 'date_of_joining' => '2026-07-01',
            'department' => 'Never Created Dept'],
    ]);

    $service->import($parsed, 'skip', $actor, null, autoCreateMasterData: false);

    expect(Department::where('name', 'Never Created Dept')->exists())->toBeFalse();
    expect(Employee::where('employee_id', 'CNS981')->first()->department_id)->toBeNull();
});

test('a duplicate bio code blocks the row, since punches would attach to the wrong person', function () {
    $parsed = hrSheetService()->parse([
        ['employee_id' => 'CNS990', 'bio_code' => '42', 'employee_name' => 'First Person', 'email' => 'b1@example.com', 'date_of_joining' => '2026-07-01'],
        ['employee_id' => 'CNS991', 'bio_code' => '42', 'employee_name' => 'Second Person', 'email' => 'b2@example.com', 'date_of_joining' => '2026-07-01'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('new')
        ->and($parsed['rows'][1]['status'])->toBe('error')
        ->and(implode(' ', $parsed['rows'][1]['errors']))->toContain('Duplicate Bio Code')
        ->and($parsed['quality']['duplicate_bio_codes'])->toBe(1);
});

test('exporting employees, adding new rows and re-importing updates and creates without duplicating', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    // Two existing employees: one with a real address, one imported without.
    $service->import($service->parse([
        ['employee_id' => 'RT001', 'employee_name' => 'Real Email Person', 'email' => 'rt.real@example.com', 'date_of_joining' => '2026-01-01'],
        ['employee_id' => 'RT002', 'employee_name' => 'No Email Person', 'date_of_joining' => '2026-01-02'],
    ]), 'skip', $actor);

    // Export in the import layout, exactly as the "Export existing" button does.
    $export = new EmployeesExport;
    $slugs = array_map(fn ($h) => Str::slug($h, '_'), $export->headings());
    $rows = array_values(array_filter($export->rows(), fn ($r) => in_array($r[0], ['RT001', 'RT002'], true)));

    // HR edits an existing row and appends a new hire.
    $rows[0][array_search('phone', $slugs)] = '9111111111';
    $newHire = array_fill(0, count($slugs), '');
    $newHire[array_search('employee_id', $slugs)] = 'RT003';
    $newHire[array_search('first_name', $slugs)] = 'Brand';
    $newHire[array_search('last_name', $slugs)] = 'New Hire';
    $newHire[array_search('email', $slugs)] = 'rt.new@example.com';
    $newHire[array_search('joining_date', $slugs)] = '2026-08-01';
    $rows[] = $newHire;

    $parsed = $service->parse(array_map(fn ($r) => array_combine($slugs, $r), $rows));
    expect($parsed['summary']['update'])->toBe(2)
        ->and($parsed['summary']['new'])->toBe(1)
        ->and($parsed['summary']['error'])->toBe(0);

    $log = $service->import($parsed, 'update', $actor);
    expect($log->updated)->toBe(2)->and($log->imported)->toBe(1);

    expect(Employee::whereIn('employee_id', ['RT001', 'RT002', 'RT003'])->count())->toBe(3);
    expect(Employee::where('employee_id', 'RT001')->first()->phone)->toBe('9111111111');
});

test('a generated email coming back through export keeps its Email Pending flag', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = hrSheetService();

    $service->import($service->parse([
        ['employee_id' => 'RT100', 'employee_name' => 'Placeholder Person', 'date_of_joining' => '2026-01-01'],
    ]), 'skip', $actor);

    $employee = Employee::with('user')->where('employee_id', 'RT100')->first();
    expect($employee->has_placeholder_email)->toBeTrue();

    // Re-import the row with the generated address still in the file — it is
    // not a real mailbox, so the flag must survive the round trip.
    $parsed = $service->parse([
        ['employee_id' => 'RT100', 'employee_name' => 'Placeholder Person', 'email' => $employee->user->email, 'date_of_joining' => '2026-01-01'],
    ]);
    $service->import($parsed, 'update', $actor);

    expect(Employee::where('employee_id', 'RT100')->first()->has_placeholder_email)->toBeTrue();

    // Giving them a genuine address clears it.
    $service->import($service->parse([
        ['employee_id' => 'RT100', 'employee_name' => 'Placeholder Person', 'email' => 'rt100.real@example.com', 'date_of_joining' => '2026-01-01'],
    ]), 'update', $actor);

    expect(Employee::where('employee_id', 'RT100')->first()->has_placeholder_email)->toBeFalse();
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
