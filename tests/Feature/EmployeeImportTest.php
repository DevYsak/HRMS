<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeImport;
use App\Mail\WelcomeEmployeeMail;
use App\Models\Employee;
use App\Models\EmployeeImportLog;
use App\Models\User;
use App\Services\EmployeeImportService;
use App\Services\SpreadsheetService;
use Illuminate\Support\Facades\Mail;
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

test('new hires receive a welcome email with generated credentials when enabled', function () {
    Mail::fake();
    $actor = User::factory()->create();
    $service = app(EmployeeImportService::class);

    $parsed = $service->parse([
        ['employee_id' => 'EMP-M', 'first_name' => 'Mail', 'email' => 'mail@example.com', 'joining_date' => '2026-07-01'],
    ]);
    $service->import($parsed, 'skip', $actor, null, true);

    Mail::assertSent(WelcomeEmployeeMail::class, fn ($mail) => $mail->hasTo('mail@example.com'));
});

test('admin can open the import page and download the template', function () {
    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($admin)->test(EmployeeImport::class)
        ->assertSee('Import Employees')
        ->call('downloadTemplate')
        ->assertFileDownloaded('employee-import-template.xlsx');
});
