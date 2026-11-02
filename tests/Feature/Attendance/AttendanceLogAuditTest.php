<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The Attendance Log as HR audits it: the punch timeline (IN green, OUT
 * orange, latest four with the rest on hover), breaks counted from the punches,
 * the missed-punch / multiple-breaks / late audit filters, and one employee's
 * complete punch log for the chosen range.
 *
 * Clock: Wed 4 Nov 2026, 15:00. Saturday and Sunday are off. Face = IN, ID card = OUT.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-04 15:00:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();

    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $this->pratish = alEmployee('PRATISH KAVADE', 'CNS042');
    $this->kajal = alEmployee('KAJAL KAHAR', 'CNS053');
    $this->saad = alEmployee('SAAD MULLA', 'CNS040');

    // Pratish, Monday: late, three sessions = two breaks.
    alDay($this->pratish, '2026-11-02', [['10:57', 'face'], ['11:33', 'id_card'], ['11:36', 'face'], ['12:12', 'id_card'], ['12:30', 'face'], ['17:00', 'id_card']],
        ['is_late' => true, 'late_minutes' => 22, 'status' => 'late', 'check_out' => '17:00']);
    // Kajal, Tuesday: checked in, never out — a missed punch.
    alDay($this->kajal, '2026-11-03', [['09:30', 'face']], ['status' => 'on_time']);
    // Saad, Tuesday: a clean day.
    alDay($this->saad, '2026-11-03', [['09:00', 'face'], ['18:00', 'id_card']], ['status' => 'on_time', 'check_out' => '18:00']);
});

function alEmployee(string $name, string $code): Employee
{
    return Employee::factory()->create(['user_id' => User::factory()->create(['name' => $name])->id, 'status' => 'active',
        'employee_id' => $code, 'joining_date' => '2024-01-01']);
}

/** @param  array<int, array{0: string, 1: string}>  $punches */
function alDay(Employee $employee, string $date, array $punches, array $attendance): void
{
    foreach ($punches as [$time, $method]) {
        AttendancePunch::create(['employee_id' => $employee->id, 'punched_at' => "{$date} {$time}:00", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric', 'device_serial' => 'MB20']);
    }
    $checkOut = $attendance['check_out'] ?? null;
    unset($attendance['check_out']);
    Attendance::create($attendance + ['employee_id' => $employee->id, 'date' => $date, 'check_in' => "{$date} {$punches[0][0]}:00",
        'check_out' => $checkOut ? "{$date} {$checkOut}:00" : null, 'check_in_method' => 'face', 'work_mode' => 'office']);
}

function alPage()
{
    return Livewire::actingAs(test()->hr)->test(AllAttendance::class)->set('dateFrom', '2026-11-02')->set('dateTo', '2026-11-04');
}

test('the Punches column shows the latest four as a green / orange timeline, with every punch on hover', function () {
    $html = alPage()->assertOk()->assertSeeHtml('data-punch-timeline')->html();

    $row = substr($html, strpos($html, 'data-open-punch-log="'.$this->pratish->id.'"'));
    $row = substr($row, 0, strpos($row, '</tr>'));
    expect(substr_count($row, 'data-punch="in"') + substr_count($row, 'data-punch="out"'))->toBe(4)
        ->and($row)->toContain('+2')                                   // two earlier punches collapsed
        ->and($row)->toContain('IN 10:57 AM')->and($row)->toContain('OUT 05:00 PM')   // every punch in the hover timeline
        ->and($row)->toContain('data-punch-timeline-full')
        ->and($row)->toContain('2 breaks')
        ->and($row)->toContain('+22m late');
});

test('audit chips count missed punches and multiple breaks, and each filters the log to them', function () {
    $page = alPage()->assertSeeHtml('data-audit-chips');
    expect($page->viewData('auditCounts'))->toMatchArray(['missed' => 1, 'multiple_breaks' => 1, 'with_breaks' => 1]);

    $page->call('$set', 'status', 'missed_punch')
        ->assertViewHas('attendances', fn ($p) => collect($p->items())->pluck('employee_id')->all() === [$this->kajal->id])
        ->assertSeeHtml('data-flag="missed_punch"');

    $page->set('status', 'multiple_breaks')
        ->assertViewHas('attendances', fn ($p) => collect($p->items())->pluck('employee_id')->all() === [$this->pratish->id]);

    $page->set('status', 'late')
        ->assertViewHas('attendances', fn ($p) => collect($p->items())->pluck('employee_id')->all() === [$this->pratish->id]);

    $page->set('status', '')->assertViewHas('attendances', fn ($p) => $p->total() === 3);
});

test('choosing one employee shows their complete punch log for the range — every day, every punch, breaks and audit flags', function () {
    $page = alPage()->call('$set', 'filterEmployee', (string) $this->pratish->id)
        ->assertSeeHtml('data-punch-log="'.$this->pratish->id.'"')
        ->assertSee('Punch log · PRATISH KAVADE')
        ->assertSeeHtml('data-punch-log-day="2026-11-02"')
        ->assertSeeHtml('data-punch-log-day="2026-11-03"')
        ->assertSee('OUT 12:12 PM')
        ->assertSee('Multiple breaks');

    $log = $page->viewData('punchLog');
    $days = collect($log['days'])->keyBy('date');
    expect($log['summary'])->toMatchArray(['present' => 1, 'late' => 1, 'absent' => 1, 'missed' => 0, 'multiple_breaks' => 1, 'punches' => 6])
        ->and($days['2026-11-02'])->toMatchArray(['label' => 'Late', 'breaks' => 2, 'late_minutes' => 22])
        ->and($days['2026-11-03']['label'])->toBe('Absent')
        ->and($days['2026-11-04']['label'])->toBe('Not checked in');

    // Kajal's log flags the missed check-out.
    $kajal = alPage()->set('filterEmployee', (string) $this->kajal->id)->viewData('punchLog');
    expect(collect($kajal['days'])->firstWhere('date', '2026-11-03'))->toMatchArray(['missed' => true, 'label' => 'Present']);

    // Opening the log from a row, and back to everyone.
    alPage()->assertSeeHtml('data-open-punch-log="'.$this->saad->id.'"')
        ->call('$set', 'filterEmployee', (string) $this->saad->id)
        ->assertSeeHtml('data-punch-log="'.$this->saad->id.'"')
        ->call('$set', 'filterEmployee', '')
        ->assertDontSeeHtml('data-punch-log=');
});

test('a punch log is never shown for an employee outside the viewer\'s reach', function () {
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    $manager = Employee::factory()->create(['user_id' => $managerUser->id, 'status' => 'active']);
    $this->kajal->update(['manager_id' => null]);

    expect($managerUser->canApproveLeave())->toBeTrue()
        ->and($managerUser->accessibleEmployeeIds())->not->toContain($this->kajal->id);

    expect(Livewire::actingAs($managerUser)->test(AllAttendance::class)->set('filterEmployee', (string) $this->kajal->id)->viewData('punchLog'))->toBeNull()
        // HR, company-wide, does see it.
        ->and(alPage()->set('filterEmployee', (string) $this->kajal->id)->viewData('punchLog'))->not->toBeNull();
});
