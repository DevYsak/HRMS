<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceWorkbook;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;

/*
 * Attendance Reports → Excel workbook: Summary, Late Marks, Detailed
 * Attendance, Shift Changes and About, for the people and period selected.
 * Each day is judged against the shift that applied on it, and a shift
 * change in the period is spelled out with the old and new timings.
 */

function workbookDay(Employee $employee, string $date, string $in, string $out, array $punches = []): void
{
    Attendance::create(['employee_id' => $employee->id, 'date' => $date,
        'check_in' => "{$date} {$in}", 'check_out' => "{$date} {$out}", 'status' => 'on_time', 'work_mode' => 'office']);

    foreach ($punches as [$time, $method]) {
        AttendancePunch::create(['employee_id' => $employee->id, 'punched_at' => "{$date} {$time}", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric']);
    }
}

/** @return array<string, list<list<mixed>>> sheet name => rows (heading first) */
function readWorkbook(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $sheets;
}

beforeEach(function () {
    $this->uk = ShiftSetting::create(['name' => 'UK Sales Shift', 'start_time' => '13:00', 'end_time' => '22:00', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->it = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->actingAs($this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Shivani HR']));
    Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'joining_date' => '2024-01-01']);

    // Ari: UK shift, moved to the IT shift on Thu 5 Nov 2026.
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    $this->ari = Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => 'Ari Alpha'])->id,
        'shift_id' => $this->uk->id, 'status' => 'active', 'joining_date' => '2025-01-01', 'manager_id' => null,
    ]);
    $this->travelTo(Carbon::parse('2026-11-05 09:00'));
    $this->ari->update(['shift_id' => $this->it->id]);
    // The audit log is stamped by the database clock, which time travel does not move.
    AuditLog::query()->whereKey(AuditLog::max('id'))->update(['created_at' => now()->toDateTimeString()]);
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));

    workbookDay($this->ari, '2026-11-03', '12:58:00', '22:05:00', [['12:58:00', 'face'], ['22:05:00', 'id_card']]); // on time (UK)
    workbookDay($this->ari, '2026-11-04', '13:10:00', '22:00:00');  // 4 min late (UK, 13:06 cutoff)
    workbookDay($this->ari, '2026-11-06', '10:40:00', '19:35:00');  // 4 min late (IT, 10:36 cutoff)
});

test('late marks are judged against the shift that applied that day, and the change is spelled out', function () {
    $sheets = collect(app(AttendanceWorkbook::class)->sheets(['from' => '2026-11-02', 'to' => '2026-11-06', 'employee_id' => $this->ari->id]))->keyBy('name');

    expect($sheets->keys()->all())->toBe(['Summary', 'Late Marks', 'Detailed Attendance', 'Shift Changes', 'About']);

    $late = collect($sheets['Late Marks']['rows'])->keyBy(3);
    $note = 'Shift changed on 05 Nov 2026: UK Sales Shift 13:00 – 22:00 · 6 min grace → IT Shift 10:30 – 19:30 · 6 min grace';
    expect($late->keys()->all())->toBe(['2026-11-04', '2026-11-06'])
        // Judged against the UK shift on 4 Nov, the IT shift on 6 Nov — 12:58 on 3 Nov is not late.
        ->and($late['2026-11-04'])->toMatchArray([5 => 'UK Sales Shift', 6 => '01:00 PM', 7 => 6, 8 => '01:06 PM', 9 => '01:10 PM', 10 => 4, 15 => $note])
        ->and($late['2026-11-06'])->toMatchArray([5 => 'IT Shift', 6 => '10:30 AM', 9 => '10:40 AM', 10 => 4]);

    $detail = collect($sheets['Detailed Attendance']['rows'])->keyBy(3);
    expect($detail['2026-11-03'])->toMatchArray([5 => 'Present', 6 => 'UK Sales Shift', 8 => '12:58 PM', 9 => '10:05 PM', 10 => '9h 07m', 16 => 2,
        17 => '12:58 PM Face · 10:05 PM ID Card', 23 => 'Biometric'])
        ->and($detail['2026-11-04'][5])->toBe('Late')
        ->and($detail['2026-11-05'][5])->toBe('Absent')
        ->and($detail['2026-11-05'][6])->toBe('IT Shift');

    $summary = $sheets['Summary']['rows'][0];
    // Mon 2 – Fri 6 Nov: 5 working days, present 3 (2 of them late, 8 min in all), absent 2 (2nd and 5th).
    expect($summary)->toMatchArray([1 => 'Ari Alpha', 3 => 'UK Sales Shift (13:00–22:00) → IT Shift (10:30–19:30)', 4 => $note,
        5 => 5, 6 => 3, 7 => 2, 8 => 8, 11 => 2]);

    expect($sheets['Shift Changes']['rows'])->toHaveCount(1)
        ->and($sheets['Shift Changes']['rows'][0])->toMatchArray([0 => '2026-11-05', 1 => 'Moved to another shift', 3 => 'Ari Alpha',
            5 => 'UK Sales Shift', 6 => '13:00 – 22:00 · 6 min grace', 7 => 'IT Shift', 8 => '10:30 – 19:30 · 6 min grace', 9 => 'Shivani HR']);
});

test('HR downloads the workbook from Attendance Reports for the selected period', function () {
    $this->withoutVite()->get(route('attendance.reports'))->assertOk()->assertSee('Excel workbook');

    $response = $this->get(route('reports.attendance-workbook', ['from' => '2026-11-02', 'to' => '2026-11-06']))
        ->assertOk()
        ->assertDownload('attendance-workbook-20261102-20261106.xlsx');

    $sheets = readWorkbook($response->baseResponse->getFile()->getPathname());

    expect(array_keys($sheets))->toBe(['Summary', 'Late Marks', 'Detailed Attendance', 'Shift Changes', 'About'])
        ->and($sheets['Late Marks'][0])->toContain('Late by (min)', 'Shift note')
        ->and(collect($sheets['Late Marks'])->pluck(1))->toContain('Ari Alpha')
        ->and(collect($sheets['Detailed Attendance'])->pluck(1)->filter(fn ($n) => $n === 'Ari Alpha'))->toHaveCount(5);
});

test('the workbook keeps to the reports permission and to a period of up to a year', function () {
    $this->get(route('reports.attendance-workbook', ['from' => '2025-01-01', 'to' => '2026-11-06']))->assertStatus(422);

    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->get(route('reports.attendance-workbook', ['from' => '2026-11-02', 'to' => '2026-11-06']))
        ->assertForbidden();
});
