<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\HistoricalBalances;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\HistoricalLeaveBalanceImportService as Importer;
use App\Services\LeaveBalanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Bulk migration of closed leave years.
 *
 * The years being migrated mostly reach us as a closing balance and nothing
 * else. Every test here is about that shape surviving: an absent "used" must
 * stay absent all the way to the stored row, because a zero would be the
 * system claiming the employee took no leave — and the carry-forward engine
 * would then derive an eligible amount from a figure nobody supplied.
 */
function hliYears(): array
{
    $prev = LeaveYear::firstOrCreate(['label' => '2025/26'], ['starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $curr = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return [$prev, $curr];
}

function hliType(): LeaveType
{
    return LeaveType::firstOrCreate(['code' => 'AL'], [
        'name' => 'Annual Leave', 'category' => 'annual', 'allow_paid_request' => true,
        'allow_carry_forward' => true,
    ]);
}

function hliEmployee(string $staffId): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'employee_id' => $staffId,
        'status' => 'active',
    ]);
}

function hliHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

function hliRow(string $code, string $year, string $type, string $closing, string $used, string $encashed, string $remarks = ''): array
{
    return [
        'employee_code' => $code, 'leave_year' => $year, 'leave_type' => $type,
        'closing_balance' => $closing, 'used' => $used, 'encashed' => $encashed, 'remarks' => $remarks,
    ];
}

function hliImporter(): Importer
{
    return app(Importer::class);
}

// ── Unknown usage stays unknown ────────────────────────────────────────────

test('a row with no usage figure is awaiting an HR decision, not calculable', function () {
    hliYears();
    hliType();
    hliEmployee('CNS018');

    $parsed = hliImporter()->parse([hliRow('CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available')]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_AWAITING_HR)
        ->and($parsed['rows'][0]['data']['used'])->toBeNull()
        ->and($parsed['rows'][0]['data']['used_label'])->toBe('Not Available')
        ->and($parsed['summary'][Importer::STATUS_AWAITING_HR])->toBe(1);
});

test('every spelling of "we do not have it" reads as unknown, never as zero', function () {
    hliYears();
    hliType();

    foreach (['Not Available', 'N/A', 'unknown', '-', ''] as $i => $token) {
        hliEmployee("CNS10{$i}");
        $parsed = hliImporter()->parse([hliRow("CNS10{$i}", '2025/26', 'AL', '10', $token, '0')]);

        expect($parsed['rows'][0]['data']['used'])->toBeNull("token '{$token}'");
    }
});

test('importing an unknown row stores it as unknown, not as zero used', function () {
    [$prev] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS018');

    $importer = hliImporter();
    $parsed = $importer->parse([hliRow('CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available')]);
    $result = $importer->import($parsed, hliHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    expect($result['imported'])->toBe(1)
        ->and($result['awaiting_decision'])->toBe(1)
        ->and((float) $balance->allocated_days)->toBe(10.0)
        ->and($balance->used_days_unknown)->toBeTrue()
        ->and($balance->encashed_days_unknown)->toBeTrue();
});

// ── Known usage is calculable ──────────────────────────────────────────────

test('a complete row is known and its figures are stored as given', function () {
    [$prev] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS021');

    $importer = hliImporter();
    $parsed = $importer->parse([hliRow('CNS021', '2025/26', 'AL', '28', '10', '0')]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_KNOWN);

    $importer->import($parsed, hliHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    expect((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(10.0)
        ->and($balance->used_days_unknown)->toBeFalse();
});

// ── The five preview statuses ──────────────────────────────────────────────

test('a row for an unknown employee, year or type is invalid and writes nothing', function () {
    hliYears();
    hliType();

    $importer = hliImporter();
    $parsed = $importer->parse([
        hliRow('NOBODY', '2025/26', 'AL', '10', '0', '0'),
        hliRow('CNS018', '1999/00', 'AL', '10', '0', '0'),
    ]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_INVALID)
        ->and($parsed['rows'][1]['status'])->toBe(Importer::STATUS_INVALID);

    $result = $importer->import($parsed, hliHr());

    expect($result['imported'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and(LeaveBalance::where('year', 2025)->count())->toBe(0);
});

test('used exceeding the closing balance is a conflict, not an import', function () {
    hliYears();
    hliType();
    hliEmployee('CNS018');

    $importer = hliImporter();
    $parsed = $importer->parse([hliRow('CNS018', '2025/26', 'AL', '10', '25', '0')]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_CONFLICT)
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('exceeds the closing balance');

    $importer->import($parsed, hliHr());

    // Scoped to the year under test: creating an employee provisions their
    // current-year annual leave, which is not what this row is about.
    expect(LeaveBalance::where('year', 2025)->count())->toBe(0);
});

test('the same employee, type and year twice in one file is a duplicate', function () {
    hliYears();
    hliType();
    hliEmployee('CNS018');

    $parsed = hliImporter()->parse([
        hliRow('CNS018', '2025/26', 'AL', '10', '0', '0'),
        hliRow('CNS018', '2025/26', 'AL', '12', '0', '0'),
    ]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_KNOWN)
        ->and($parsed['rows'][1]['status'])->toBe(Importer::STATUS_DUPLICATE);
});

test('a balance that already exists is a duplicate and is never overwritten', function () {
    [$prev] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS018');

    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'leave_year_id' => $prev->id, 'year' => $prev->legacyYear(),
        'allocated_days' => 99, 'used_days' => 3,
    ]);

    $importer = hliImporter();
    $parsed = $importer->parse([hliRow('CNS018', '2025/26', 'AL', '10', '0', '0')]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_DUPLICATE);

    $importer->import($parsed, hliHr());

    $stored = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    expect((float) $stored->allocated_days)->toBe(99.0);
});

// ── Bulk ───────────────────────────────────────────────────────────────────

test('one file carries many employees, each classified on its own merits', function () {
    hliYears();
    hliType();
    hliEmployee('CNS001');
    hliEmployee('CNS002');
    hliEmployee('CNS003');

    $importer = hliImporter();
    $parsed = $importer->parse([
        hliRow('CNS001', '2025/26', 'AL', '28', '10', '0'),
        hliRow('CNS002', '2025/26', 'AL', '10', 'Not Available', 'Not Available'),
        hliRow('CNS003', '2025/26', 'AL', '5', '20', '0'),
        hliRow('GHOST', '2025/26', 'AL', '5', '0', '0'),
    ]);

    expect($parsed['summary'][Importer::STATUS_KNOWN])->toBe(1)
        ->and($parsed['summary'][Importer::STATUS_AWAITING_HR])->toBe(1)
        ->and($parsed['summary'][Importer::STATUS_CONFLICT])->toBe(1)
        ->and($parsed['summary'][Importer::STATUS_INVALID])->toBe(1);

    $result = $importer->import($parsed, hliHr());

    expect($result['imported'])->toBe(2)
        ->and($result['skipped'])->toBe(2);
});

// ── Pending leave is not usage ─────────────────────────────────────────────

test('a pending request does not become historical usage', function () {
    [$prev] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS018');

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2025-08-01', 'end_date' => '2025-08-03', 'days' => 3,
        'reason' => 'Pending', 'status' => 'pending', 'requested_leave_status' => 'paid',
    ]);

    $importer = hliImporter();
    $importer->import($importer->parse([
        hliRow('CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available'),
    ]), hliHr());

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $type->id)->where('year', $prev->legacyYear())->first();

    // The pending three days must not have been read as usage — the figure
    // is still unknown, and used_days is not 3.
    expect($balance->used_days_unknown)->toBeTrue()
        ->and((float) $balance->used_days)->toBe(0.0);
});

// ── Leave-year boundaries ──────────────────────────────────────────────────

test('the label picks the leave year, not the calendar year', function () {
    [$prev, $curr] = hliYears();
    hliType();
    hliEmployee('CNS018');

    $importer = hliImporter();
    $parsed = $importer->parse([hliRow('CNS018', '2025/26', 'AL', '10', '0', '0')]);

    expect($parsed['rows'][0]['data']['leave_year_id'])->toBe($prev->id)
        ->and($parsed['rows'][0]['data']['leave_year_id'])->not->toBe($curr->id);

    $importer->import($parsed, hliHr());

    // 1 July 2025 – 30 June 2026 is stored under the leave year's own
    // integer, not under whichever calendar year the import happened in.
    $stored = LeaveBalance::where('employee_id', $parsed['rows'][0]['data']['employee_id'])
        ->where('leave_year_id', $prev->id)->first();

    expect($stored->year)->toBe($prev->legacyYear())
        ->and($prev->starts_on->format('m-d'))->toBe('07-01')
        ->and($prev->ends_on->format('m-d'))->toBe('06-30');
});

// ── Audit ──────────────────────────────────────────────────────────────────

test('every imported balance is audited against the employee who received it', function () {
    hliYears();
    hliType();
    $employee = hliEmployee('CNS018');
    $hr = hliHr();
    test()->actingAs($hr);

    $importer = hliImporter();
    $importer->import($importer->parse([
        hliRow('CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available', 'From the 2025/26 sheet'),
    ]), $hr);

    $log = AuditLog::where('subject_employee_id', $employee->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        // The audit must record the absence, not a zero.
        ->and(json_encode($log->new_values))->toContain('not_available');
});

test('the template names exactly the columns the business was asked for', function () {
    expect(hliImporter()->templateHeadings())->toBe([
        'employee_code', 'leave_year', 'leave_type', 'closing_balance', 'used', 'encashed', 'remarks',
    ]);
});

// ── The screen ─────────────────────────────────────────────────────────────

test('the page renders for HR and shows what the import will not do', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);

    Livewire::actingAs($hr)->test(HistoricalBalances::class)
        ->assertOk()
        ->assertSee('Historical Leave Balances')
        ->assertSee('A missing figure stays missing')
        ->assertSee('Not Available');
});

test('an employee cannot open the historical balance import', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(HistoricalBalances::class)
        ->assertForbidden();
});

test('the preview shows Not Available and Awaiting HR Decision rather than a zero', function () {
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);

    hliYears();
    hliType();
    hliEmployee('CNS018');

    Livewire::actingAs($hr)->test(HistoricalBalances::class)
        ->set('file', hliCsv([hliRow('CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available')]))
        ->call('analyze')
        ->assertSet('showPreview', true)
        ->assertSee('Awaiting HR Decision')
        ->assertSee('Not Available');
});

// ── Closed years only, and the preview is never trusted ─────────────────────

function hliCsv(array $rows): UploadedFile
{
    $lines = [implode(',', hliImporter()->templateHeadings())];
    foreach ($rows as $row) {
        $lines[] = implode(',', array_values($row));
    }

    return UploadedFile::fake()->createWithContent('history.csv', implode("\n", $lines)."\n");
}

function hliHrWithRole(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    return User::factory()->create([
        'role' => UserRole::HrAdmin,
        'role_id' => Role::where('slug', 'hr_admin')->firstOrFail()->id,
    ]);
}

test('the current leave year is rejected by the import, server-side', function () {
    hliYears();
    hliType();
    hliEmployee('CNS018');

    $parsed = hliImporter()->parse([hliRow('CNS018', '2026/27', 'AL', '10', '2', '0')]);

    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_INVALID)
        ->and(implode(' ', $parsed['rows'][0]['errors']))->toContain('current or a future leave year');

    $result = hliImporter()->import($parsed, hliHr());

    // Nothing historical was written (joining provisions a live-year balance,
    // which is untouched).
    expect($result['imported'])->toBe(0)
        ->and(AuditLog::where('event', 'LEAVE_HISTORICAL_BALANCE_SET')->count())->toBe(0);
});

test('a historical balance can never be written to the live year, from any screen', function () {
    [, $curr] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS018');
    LeaveBalance::updateOrCreate(
        ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026],
        ['leave_year_id' => $curr->id, 'allocated_days' => 28, 'used_days' => 6],
    );

    expect(fn () => app(LeaveBalanceService::class)->setHistoricalBalance(
        $employee, $type, $curr, 10, 0, 0, 'Trying to reset this year', null, hliHr(),
    ))->toThrow(DomainException::class, 'closed leave years');

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('year', 2026)->first();
    expect((float) $balance->allocated_days)->toBe(28.0)
        ->and((float) $balance->used_days)->toBe(6.0);
});

test('the preview cannot be edited from the browser', function () {
    $hr = hliHrWithRole();
    hliYears();
    hliType();
    hliEmployee('CNS018');

    Livewire::actingAs($hr)->test(HistoricalBalances::class)
        ->set('file', hliCsv([hliRow('CNS018', '2025/26', 'AL', '10', '2', '0')]))
        ->call('analyze')
        ->set('parsed.rows.0.status', Importer::STATUS_KNOWN);
})->throws(CannotUpdateLockedPropertyException::class);

test('a tampered preview cannot import a rejected row — the import re-validates', function () {
    hliYears();
    hliType();
    hliEmployee('CNS018');

    // Live year → invalid in the preview.
    $parsed = hliImporter()->parse([hliRow('CNS018', '2026/27', 'AL', '10', '2', '0')]);

    // Forge everything a client could forge: status, resolved ids, figures.
    $parsed['rows'][0]['status'] = Importer::STATUS_KNOWN;
    $parsed['rows'][0]['errors'] = [];
    $parsed['rows'][0]['data']['leave_year_id'] = LeaveYear::where('label', '2026/27')->value('id');

    $result = hliImporter()->import($parsed, hliHr());

    expect($result['imported'])->toBe(0)
        ->and(AuditLog::where('event', 'LEAVE_HISTORICAL_BALANCE_SET')->count())->toBe(0);
});

test('a row that became a duplicate after the preview is not imported over the stored balance', function () {
    [$prev] = hliYears();
    $type = hliType();
    $employee = hliEmployee('CNS018');

    $parsed = hliImporter()->parse([hliRow('CNS018', '2025/26', 'AL', '10', '2', '0')]);
    expect($parsed['rows'][0]['status'])->toBe(Importer::STATUS_KNOWN);

    // Someone records the same year between preview and import.
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_year_id' => $prev->id,
        'year' => 2025, 'allocated_days' => 20, 'used_days' => 5,
    ]);

    $result = hliImporter()->import($parsed, hliHr());

    expect($result['imported'])->toBe(0);
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('year', 2025)->first();
    expect((float) $balance->allocated_days)->toBe(20.0)
        ->and((float) $balance->used_days)->toBe(5.0);
});

test('an imported historical balance is a categorised leave audit event', function () {
    hliYears();
    hliType();
    $employee = hliEmployee('CNS018');
    $hr = hliHr();

    hliImporter()->import(hliImporter()->parse([hliRow('CNS018', '2025/26', 'AL', '12', '4', '0')]), $hr);

    $log = AuditLog::where('event', 'LEAVE_HISTORICAL_BALANCE_SET')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->category)->toBe('leave')
        ->and($log->subject_employee_id)->toBe($employee->id)
        ->and($log->new_values['allocated_days'])->toEqual(12);
});
