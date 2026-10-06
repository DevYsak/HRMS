<?php

use App\Enums\UserRole;
use App\Livewire\Settings\ImportExportCentre;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\User;
use App\Services\DataTransfer\DataExportService;
use App\Services\DataTransfer\HolidayImportService;
use App\Services\SpreadsheetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The Import / Export centre: Export Data / Import Data permissions (HR Admin
 * by default), exports limited to the viewer's reach and free of bank / tax /
 * salary columns, and a preview-first holiday import that only adds valid,
 * new rows.
 */
function iecHr(array $attributes = []): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin, 'email_verified_at' => now()] + $attributes);
}

function iecHolidayCsv(array $rows): UploadedFile
{
    $lines = [implode(',', HolidayImportService::HEADINGS)];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return UploadedFile::fake()->createWithContent('holidays.csv', implode("\n", $lines)."\n");
}

// ── Access ─────────────────────────────────────────────────────────────────

test('HR opens the centre; a manager is refused', function () {
    $this->actingAs(iecHr())->get(route('settings.import-export'))
        ->assertOk()->assertSee('Import / Export')->assertSee('Import holidays');

    $this->actingAs(User::factory()->create(['role' => UserRole::Manager]))
        ->get(route('settings.import-export'))->assertForbidden();
});

test('a role with Export Data alone sees exports but no import', function () {
    $role = Role::create(['name' => 'Reporter', 'slug' => 'reporter', 'is_system' => false, 'is_active' => true]);
    $role->permissions()->sync(Permission::where('key', 'data_export')->pluck('id'));
    $role->flushPermissionCache();
    $user = User::factory()->create(['role' => UserRole::Employee, 'role_id' => $role->id]);

    Livewire::actingAs($user)->test(ImportExportCentre::class)
        ->assertSee('Leave balances')
        ->assertDontSee('Import holidays')
        ->call('downloadHolidayTemplate')
        ->assertForbidden();
});

// ── Export ─────────────────────────────────────────────────────────────────

test('an export downloads a file and is audited', function () {
    $hr = iecHr();
    Employee::factory()->create();

    Livewire::actingAs($hr)->test(ImportExportCentre::class)
        ->set('format', 'csv')
        ->call('export', 'employees')
        ->assertFileDownloaded('employees-'.now()->format('Y-m-d').'.csv');

    expect(AuditLog::where('event', 'DATA_EXPORTED')->where('user_id', $hr->id)->exists())->toBeTrue();
});

test('the employee export carries no bank, tax-ID or salary columns', function () {
    [$headings] = app(DataExportService::class)->build('employees', iecHr(), []);

    expect(collect($headings)->map(fn ($h) => strtolower($h))->implode(' '))
        ->not->toContain('bank')->not->toContain('pan')->not->toContain('aadhar')
        ->not->toContain('ctc')->not->toContain('account');
});

test('a department-scoped HR exports only their own department', function () {
    $mine = Department::factory()->create();
    $other = Department::factory()->create();
    $inside = Employee::factory()->create(['department_id' => $mine->id]);
    $outside = Employee::factory()->create(['department_id' => $other->id]);

    [, $rows] = app(DataExportService::class)->build('employees', iecHr(['scope_departments' => [$mine->id]]), []);
    $codes = collect($rows)->pluck(1);

    expect($codes)->toContain($inside->employee_id)
        ->not->toContain($outside->employee_id);
});

test('an over-long or reversed date range is refused with a message', function () {
    $service = app(DataExportService::class);
    $hr = iecHr();

    expect(fn () => $service->build('attendance', $hr, ['from' => '2025-01-01', 'to' => '2026-06-01']))
        ->toThrow(InvalidArgumentException::class, 'at most')
        ->and(fn () => $service->build('leave_requests', $hr, ['from' => '2026-06-02', 'to' => '2026-06-01']))
        ->toThrow(InvalidArgumentException::class, 'on or after');
});

test('the holiday export uses the import layout, so it round-trips', function () {
    PublicHoliday::create(['name' => 'Spring Bank Holiday', 'date' => '2026-05-25', 'country' => 'UK', 'holiday_type' => 'national']);

    [$headings, $rows] = app(DataExportService::class)->build('holidays', iecHr(), ['year' => 2026]);

    expect($headings)->toBe(HolidayImportService::HEADINGS)
        ->and($rows[0][0])->toBe('Spring Bank Holiday')
        ->and($rows[0][1])->toBe('2026-05-25');
});

// ── Holiday import ─────────────────────────────────────────────────────────

test('a holiday file is previewed row by row before anything is written', function () {
    PublicHoliday::create(['name' => 'Christmas Day', 'date' => '2027-12-25', 'country' => 'UK', 'holiday_type' => 'national']);

    $file = iecHolidayCsv([
        ['New Year Day', '2027-01-01', 'national', '', 'UK', 'Yes', 'No', 'Yes', ''],
        ['Foundation Day', '15/03/2027', 'Company Holiday', 'Celebration', 'UK', 'yes', 'no', 'no', ''],
        ['Bad Date', '31/02/2027', 'company', '', 'UK', 'Yes', 'No', 'No', ''],
        ['Christmas Day', '2027-12-25', 'national', '', 'UK', 'Yes', 'No', 'No', ''],
        ['Odd Flag', '2027-04-01', 'company', '', 'UK', 'maybe', 'No', 'No', ''],
    ]);

    $component = Livewire::actingAs(iecHr())->test(ImportExportCentre::class)
        ->set('holidayFile', $file)
        ->assertHasNoErrors()
        ->assertSee('2 ready')
        ->assertSee('3 will be skipped')
        ->assertSee('already exists')
        ->assertSee('is not a date');

    expect(PublicHoliday::count())->toBe(1);

    $preview = $component->get('holidayPreview');
    expect($preview[1]['data']['holiday_type'])->toBe('company')
        ->and($preview[1]['data']['date'])->toBe('2027-03-15');
});

test('importing adds only the valid new rows and is audited', function () {
    $hr = iecHr();

    Livewire::actingAs($hr)->test(ImportExportCentre::class)
        ->set('holidayFile', iecHolidayCsv([
            ['New Year Day', '2027-01-01', 'national', '', 'UK', 'Yes', 'No', 'Yes', ''],
            ['New Year Day', '2027-01-01', 'national', '', 'UK', 'Yes', 'No', 'Yes', ''],
            ['', '2027-02-01', 'national', '', 'UK', 'Yes', 'No', 'No', ''],
        ]))
        ->call('importHolidays')
        ->assertSet('holidayPreview', []);

    $created = PublicHoliday::where('name', 'New Year Day')->get();

    expect($created)->toHaveCount(1)
        ->and($created[0]->country)->toBe('UK')
        ->and($created[0]->is_recurring)->toBeTrue()
        ->and($created[0]->created_by)->toBe($hr->id)
        ->and(AuditLog::where('event', 'DATA_IMPORTED')->where('user_id', $hr->id)->exists())->toBeTrue();
});

test('a blank country falls back to the company holiday calendar', function () {
    DB::table('companies')->update(['holiday_calendar' => 'UK']);

    $preview = app(HolidayImportService::class)->preview([
        ['name' => 'Summer Bank Holiday', 'date' => '2027-08-30', 'country' => ''],
    ]);

    expect($preview[0]['errors'])->toBe([])
        ->and($preview[0]['data']['country'])->toBe(DB::table('companies')->value('holiday_calendar') ?: 'IN');
});

test('a file without the template headings is rejected', function () {
    Livewire::actingAs(iecHr())->test(ImportExportCentre::class)
        ->set('holidayFile', UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n"))
        ->assertHasErrors('holidayFile');
});

test('date-formatted xlsx cells are read as dates instead of crashing', function () {
    $path = tempnam(sys_get_temp_dir(), 'iec').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Name', 'Date']));
    // As Excel saves it: a date serial carrying a date number format.
    $writer->addRow(Row::fromValuesWithStyles(
        ['Boxing Day', new DateTimeImmutable('2027-12-26')],
        [1 => (new Style)->withFormat('dd/mm/yyyy')],
    ));
    $writer->close();

    $rows = app(SpreadsheetService::class)->read($path, 'xlsx');
    @unlink($path);

    expect($rows[0])->toBe(['name' => 'Boxing Day', 'date' => '2027-12-26']);
});
