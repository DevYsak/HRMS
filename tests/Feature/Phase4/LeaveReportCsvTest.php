<?php

use App\Models\Employee;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase 4 · Reports: the Leave Utilization and Leave Encashment CSVs read
 * columns that exist. Days was always blank (total_days / days are not
 * columns), Amount was never stored, and utilisation used the calendar year
 * instead of the leave year (1 July – 30 June).
 */
function lrcRows(string $csv): array
{
    $lines = collect(preg_split('/\R/', trim($csv)))->map(fn ($l) => str_getcsv($l));

    return $lines->slice(1)->map(fn ($row) => array_combine($lines->first(), $row))->values()->all();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 10:00'));
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $this->hr->id, 'status' => 'active', 'manager_id' => null]);
    $this->employee = Employee::factory()->create(['status' => 'active', 'manager_id' => null]);
    $this->type = LeaveType::create(['name' => 'Casual']);

    // Leave year 2026/27 = 1 Jul 2026 – 30 Jun 2027.
    foreach ([['2026-06-29', '2026-06-30', 2], ['2026-08-10', '2026-08-12', 3], ['2027-02-01', '2027-02-01', 0.5]] as [$from, $to, $days]) {
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->type->id, 'start_date' => $from, 'end_date' => $to,
            'days' => $days, 'status' => 'approved', 'reason' => 'Scenario']);
    }
});

test('leave utilisation fills Days and covers the leave year', function () {
    $rows = lrcRows($this->actingAs($this->hr)->get(route('reports.leave-utilization', ['year' => 2026]))->assertOk()->streamedContent());

    expect(collect($rows)->pluck('Start Date')->sort()->values()->all())->toBe(['2026-08-10', '2027-02-01'])
        ->and(collect($rows)->pluck('Days')->map(fn ($d) => (float) $d)->sort()->values()->all())->toBe([0.5, 3.0])
        ->and($rows[0]['Leave Year'])->toBe('2026/27');
});

test('leave encashment fills Days and drops the never-stored Amount', function () {
    LeaveEncashment::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->type->id, 'requested_days' => 4.5, 'status' => 'approved', 'payout_month' => '2026-11']);

    $rows = lrcRows($this->actingAs($this->hr)->get(route('reports.leave-encashment-report'))->assertOk()->streamedContent());

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['Days'])->toBe(4.5)
        ->and($rows[0]['Payout Month'])->toBe('2026-11')
        ->and($rows[0])->not->toHaveKey('Amount');
});
