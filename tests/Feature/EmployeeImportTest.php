<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeImport;
use App\Models\Employee;
use App\Models\EmployeeImportLog;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\SpreadsheetService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

test('spreadsheet reader maps headers to slugged keys', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp_').'.csv';
    file_put_contents($path, "Employee ID,First Name,Email,Joining Date\nEMP-9,Zed,zed@example.com,2026-07-01\n");

    $rows = app(SpreadsheetService::class)->read($path, 'csv');
    @unlink($path);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['employee_id'])->toBe('EMP-9');
    expect($rows[0]['first_name'])->toBe('Zed');
    expect($rows[0]['email'])->toBe('zed@example.com');
});

test('parse classifies new, update and error rows', function () {
    $existing = Employee::factory()->create();
    $service = app(EmployeeImportService::class);

    $result = $service->parse([
        ['employee_id' => 'EMP-A', 'first_name' => 'Asha', 'email' => 'asha@example.com', 'joining_date' => '2026-07-01'],
        ['first_name' => 'Bob', 'email' => $existing->user->email, 'joining_date' => '2026-07-01'],
        ['employee_id' => 'EMP-C', 'first_name' => 'Cara', 'email' => 'bad-email', 'joining_date' => '2026-07-01'],
    ]);

    expect(array_column($result['rows'], 'status'))->toBe(['new', 'update', 'error']);
    expect($result['summary']['new'])->toBe(1);
    expect($result['summary']['update'])->toBe(1);
    expect($result['summary']['error'])->toBe(1);
});

test('an unknown employment type or manager warns but still imports the row', function () {
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-W', 'first_name' => 'Warned', 'email' => 'warned@example.com', 'joining_date' => '2026-07-01', 'employment_type' => 'Full Time', 'reporting_manager' => 'nobody@example.com'],
    ]);

    expect($parsed['rows'][0]['status'])->toBe('new');          // not blocked
    expect($parsed['rows'][0]['warnings'])->not->toBeEmpty();

    $log = $service->import($parsed, 'skip', User::factory()->create());

    expect($log->imported)->toBe(1);
    expect(Employee::whereHas('user', fn ($q) => $q->where('email', 'warned@example.com'))->exists())->toBeTrue();
});

test('new employees require a unique employee id', function () {
    $result = app(EmployeeImportService::class)->parse([
        ['first_name' => 'NoId', 'email' => 'noid@example.com', 'joining_date' => '2026-07-01'],
    ]);

    expect($result['rows'][0]['status'])->toBe('error');
    expect(implode(' ', $result['rows'][0]['errors']))->toContain('Employee ID is required');
});

test('import creates new employees and skips existing in skip mode', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $existing = Employee::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-100', 'first_name' => 'Asha', 'email' => 'asha@example.com', 'joining_date' => '2026-07-01'],
        ['first_name' => 'Bob', 'email' => $existing->user->email, 'joining_date' => '2026-07-01'],
    ]);
    $log = $service->import($parsed, 'skip', $actor, 'test.xlsx');

    expect($log->imported)->toBe(1);
    expect($log->skipped)->toBe(1);
    expect(Employee::whereHas('user', fn ($q) => $q->where('email', 'asha@example.com'))->exists())->toBeTrue();
});

test('import updates existing employees in update mode', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $employee = Employee::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['first_name' => 'Renamed', 'last_name' => 'Person', 'email' => $employee->user->email, 'joining_date' => '2026-07-01'],
    ]);
    $log = $service->import($parsed, 'update', $actor);

    expect($log->updated)->toBe(1);
    expect($employee->user->fresh()->name)->toBe('Renamed Person');
});

test('error rows are never imported and are counted as failed', function () {
    Mail::fake();
    $actor = User::factory()->create();

    $parsed = app(EmployeeImportService::class)->parse([
        ['employee_id' => 'EMP-X', 'first_name' => 'Bad', 'email' => 'not-email', 'joining_date' => '2026-07-01'],
    ]);
    $log = app(EmployeeImportService::class)->import($parsed, 'skip', $actor);

    expect($log->failed)->toBe(1);
    expect($log->imported)->toBe(0);
    expect(User::where('name', 'Bad')->exists())->toBeFalse();
});

test('an import log records the run', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-L', 'first_name' => 'Log', 'email' => 'log@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor, 'run.xlsx');

    expect(EmployeeImportLog::where('filename', 'run.xlsx')->where('imported', 1)->exists())->toBeTrue();
});

test('import never emails credentials to anybody', function () {
    // Import creates records; it does not create access. It used to be able to
    // mail a temporary password to everyone it created, which meant a
    // half-finished row, a duplicate, or somebody with a generated placeholder
    // address could all be handed a live login before a human looked at them.
    // Access is now issued per employee from the employee list, after HR has
    // checked the record. See EmployeeInvitationTest.
    Mail::fake();
    $actor = User::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-N', 'first_name' => 'Silent', 'email' => 'silent@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor);

    expect(User::where('email', 'silent@example.com')->exists())->toBeTrue();

    Mail::assertNothingSent();
});

test('import still creates the account, it just does not announce it', function () {
    // The account has to exist for HR to be able to invite it afterwards.
    Mail::fake();
    $actor = User::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-Q', 'first_name' => 'Quiet', 'email' => 'quiet@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor);

    $user = User::where('email', 'quiet@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->last_login_at)->toBeNull()
        ->and(Employee::where('user_id', $user->id)->exists())->toBeTrue();

    Mail::assertNothingSent();
});

test('admin can open the import page and download the template', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($admin)->test(EmployeeImport::class)
        ->assertSee('Import Employees')
        ->call('downloadTemplate')
        ->assertFileDownloaded('employee-import-template.xlsx');
});

// ── The dropped must_change_password column ───────────────────────────────

test('importing a new employee does not write a must_change_password column', function () {
    // The regression: the importer set `must_change_password` on every new
    // user. When the forced first-login change was withdrawn the column went
    // away, and the import died with
    // "Unknown column 'must_change_password' in 'field list'" — a whole
    // transaction rolled back because of a flag nothing read.
    expect(Schema::hasColumn('users', 'must_change_password'))->toBeFalse();

    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'CNS018', 'employee_code' => '16', 'first_name' => 'Mayuresh', 'last_name' => 'Mhatre', 'email' => 'mayuresh.mhatre@conexus-ns.com', 'joining_date' => '2020-09-01'],
        ['employee_id' => 'CNS021', 'employee_code' => '17', 'first_name' => 'Yogesh', 'last_name' => 'Sapkal', 'email' => 'yogesh.sapkal@conexus-ns.com', 'joining_date' => '2020-12-17'],
    ]);

    expect(array_column($parsed['rows'], 'status'))->toBe(['new', 'new'])
        ->and($parsed['summary']['error'])->toBe(0);

    $log = $service->import($parsed, 'skip', User::factory()->create());

    expect($log->imported)->toBe(2)
        ->and($log->failed)->toBe(0)
        ->and($log->errors)->toBeEmpty();

    $mayuresh = Employee::where('employee_id', 'CNS018')->first();
    $yogesh = Employee::where('employee_id', 'CNS021')->first();

    expect($mayuresh)->not->toBeNull()
        ->and((int) $mayuresh->employee_code)->toBe(16)
        ->and($yogesh)->not->toBeNull()
        ->and((int) $yogesh->employee_code)->toBe(17);
});

test('an imported employee gets a real password, not a guessable one', function () {
    // Dropping the flag must not weaken what replaced it: the generated
    // credential still has to be unguessable.
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'CNS099', 'first_name' => 'Pass', 'last_name' => 'Check', 'email' => 'pass.check@conexus-ns.com', 'joining_date' => '2026-07-01'],
    ]);

    $service->import($parsed, 'skip', User::factory()->create());

    $user = User::where('email', 'pass.check@conexus-ns.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and(Hash::check('Password@123', $user->password))->toBeFalse();
});

test('an existing manager is resolved, not re-created as a new row', function () {
    // Mazhar already exists; the sheet names him as the manager for both rows.
    // He must be linked, never imported again.
    $manager = User::factory()->create(['name' => 'Mazhar Thakur', 'email' => 'mazhar@conexus-ns.com']);
    Employee::factory()->create(['user_id' => $manager->id, 'employee_id' => 'CNS004']);

    $before = User::count();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'CNS018', 'first_name' => 'Mayuresh', 'last_name' => 'Mhatre', 'email' => 'mayuresh.mhatre@conexus-ns.com', 'joining_date' => '2020-09-01', 'reporting_manager' => 'mazhar@conexus-ns.com'],
        ['employee_id' => 'CNS021', 'first_name' => 'Yogesh', 'last_name' => 'Sapkal', 'email' => 'yogesh.sapkal@conexus-ns.com', 'joining_date' => '2020-12-17', 'reporting_manager' => 'mazhar@conexus-ns.com'],
    ]);

    $log = $service->import($parsed, 'skip', User::factory()->create());

    // Two new users only — the actor in the line above is the third.
    expect($log->imported)->toBe(2)
        ->and(User::where('email', 'mazhar@conexus-ns.com')->count())->toBe(1)
        ->and(Employee::where('employee_id', 'CNS004')->count())->toBe(1);

    $mayuresh = Employee::where('employee_id', 'CNS018')->first();

    expect($mayuresh->manager_id)->toBe($manager->id);
});
