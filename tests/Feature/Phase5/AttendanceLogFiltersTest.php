<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Attendance › Employees: HR inspects one employee's punches for a day or a
 * From–To range. Filters stay inside the viewer's reach (AttendanceFiltersTest
 * covers scope), survive pagination and the URL, and never change a record.
 * Clock: Wednesday 14 October 2026.
 */
function alfEmployee(string $name, Department $department): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['name' => $name, 'role' => UserRole::Employee])->id,
        'status' => 'active', 'department_id' => $department->id, 'joining_date' => '2024-01-08',
    ]);
}

function alfDay(Employee $employee, string $date, array $punches = []): Attendance
{
    $attendance = Attendance::create([
        'employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} 09:00:00",
        'check_out' => "{$date} 18:00:00", 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    foreach ($punches as [$time, $direction]) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punch_date' => $date, 'punched_at' => "{$date} {$time}",
            'method' => 'web', 'direction' => $direction, 'source' => 'web',
        ]);
    }

    return $attendance;
}

function alfHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $this->dept = Department::factory()->create(['name' => 'Ops']);
    $this->ana = alfEmployee('ANA LOGS', $this->dept);
    $this->ben = alfEmployee('BEN LOGS', $this->dept);
    foreach (['2026-10-05', '2026-10-06', '2026-10-12', '2026-10-13'] as $date) {
        alfDay($this->ana, $date, [['09:00:00', 'in'], ['13:00:00', 'out'], ['13:45:00', 'in'], ['18:00:00', 'out']]);
        alfDay($this->ben, $date);
    }
});

test('employee and date range together show only that employee\'s days in the range, with every punch', function () {
    $component = Livewire::actingAs(alfHr())->test(AllAttendance::class)
        ->set('filterEmployee', (string) $this->ana->id)
        ->set('dateFrom', '2026-10-05')->set('dateTo', '2026-10-06');

    $rows = $component->viewData('attendances');
    expect($rows->pluck('employee_id')->unique()->all())->toBe([$this->ana->id])
        ->and($rows->map(fn ($a) => $a->date->toDateString())->sort()->values()->all())->toBe(['2026-10-05', '2026-10-06']);

    $component->assertSeeHtml('data-punches')
        ->assertSee('IN 09:00 AM')->assertSee('OUT 01:00 PM')->assertSee('IN 01:45 PM')->assertSee('OUT 06:00 PM');
});

test('a single day takes the place of the range', function () {
    $rows = Livewire::actingAs(alfHr())->test(AllAttendance::class)
        ->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-14')
        ->set('date', '2026-10-12')
        ->viewData('attendances');

    expect($rows->map(fn ($a) => $a->date->toDateString())->unique()->values()->all())->toBe(['2026-10-12'])
        ->and($rows->total())->toBe(2);
});

test('From after To is refused with a message and shows no rows', function () {
    Livewire::actingAs(alfHr())->test(AllAttendance::class)
        ->set('dateFrom', '2026-10-13')->set('dateTo', '2026-10-05')
        ->assertSee('From date cannot be after To date.')
        ->assertSeeHtml('data-range-error')
        ->assertViewHas('attendances', fn ($rows) => $rows->total() === 0)
        ->assertViewHas('stats', fn ($stats) => $stats['absent'] === 0);
});

test('a malformed date from the URL is ignored safely', function () {
    Livewire::withQueryParams(['from' => '2026-02-31', 'to' => 'not-a-date'])
        ->actingAs(alfHr())->test(AllAttendance::class)
        ->assertOk()
        ->assertSee('Choose a valid From date.')
        ->assertViewHas('attendances', fn ($rows) => $rows->total() === 0);
});

test('filters come from the URL and stay through pagination', function () {
    // 4 + 14 = 18 rows for Ana: two pages of 15.
    foreach (range(1, 14) as $i) {
        alfDay($this->ana, Carbon::parse('2026-09-01')->addDays($i - 1)->toDateString());
    }

    $component = Livewire::withQueryParams(['employee' => (string) $this->ana->id, 'from' => '2026-09-01', 'to' => '2026-10-13'])
        ->actingAs(alfHr())->test(AllAttendance::class);

    // mount() keeps the URL's range instead of resetting it to this week.
    expect([$component->get('dateFrom'), $component->get('dateTo'), $component->get('filterEmployee')])->toBe(['2026-09-01', '2026-10-13', (string) $this->ana->id]);

    $first = $component->viewData('attendances');
    $component->call('nextPage');
    $second = $component->viewData('attendances');

    expect($first->total())->toBe(18)
        ->and($second->currentPage())->toBe(2)
        ->and($second->pluck('employee_id')->unique()->all())->toBe([$this->ana->id])
        ->and([$component->get('dateFrom'), $component->get('dateTo')])->toBe(['2026-09-01', '2026-10-13']);
});

test('Clear filters returns to this week and every employee in reach', function () {
    $component = Livewire::actingAs(alfHr())->test(AllAttendance::class)
        ->set('search', 'ANA')->set('status', 'late')->set('filterEmployee', (string) $this->ana->id)
        ->set('dateFrom', '2026-10-13')->set('dateTo', '2026-10-05')
        ->assertSeeHtml('data-clear-filters')
        ->call('clearFilters');

    expect([$component->get('search'), $component->get('status'), $component->get('filterEmployee'), $component->get('date')])->toBe(['', '', '', ''])
        ->and([$component->get('dateFrom'), $component->get('dateTo')])->toBe(['2026-10-12', '2026-10-18']);
    $component->assertDontSeeHtml('data-range-error')->assertDontSeeHtml('data-clear-filters')
        ->assertViewHas('attendances', fn ($rows) => $rows->total() === 4);
});

test('dates on the page follow the reader\'s date format', function () {
    $hr = alfHr();
    $hr->update(['date_format' => 'd/m/Y']);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->set('dateFrom', '2026-10-12')->set('dateTo', '2026-10-13')
        ->assertSee('13/10/2026')
        ->assertSee('12/10/2026 – 13/10/2026');
});

test('the punches column costs one query for the page, not one per row', function () {
    $queries = function (string $month, int $extraDays) {
        foreach (range(1, $extraDays) as $i) {
            alfDay($this->ana, Carbon::parse($month)->addDays($i)->toDateString(), [['09:00:00', 'in'], ['18:00:00', 'out']]);
        }
        $component = Livewire::actingAs(alfHr())->test(AllAttendance::class)->set('dateFrom', '2026-08-01')->set('dateTo', '2026-10-13');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh');
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'attendance_punches'))->count();
        DB::disableQueryLog();

        return $count;
    };

    expect($queries('2026-07-01', 1))->toBe($queries('2026-08-01', 10));
});

test('filtering never changes an attendance record', function () {
    $snapshot = fn () => Attendance::orderBy('id')->get()
        ->map(fn (Attendance $a) => [$a->id, (string) $a->check_in, (string) $a->check_out, $a->status, (string) $a->updated_at])->all();
    $before = $snapshot();

    Livewire::actingAs(alfHr())->test(AllAttendance::class)
        ->set('filterEmployee', (string) $this->ana->id)->set('date', '2026-10-12')
        ->set('dateFrom', '2026-10-13')->set('dateTo', '2026-10-05')
        ->call('clearFilters');

    expect($snapshot())->toBe($before)
        ->and(AttendancePunch::count())->toBe(16);
});
