<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\TimeOff\MyLeaveBalances;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\BreakLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\MenuSetting;
use App\Models\OtRequest;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\WfhRequest;
use App\Notifications\LeaveBalanceChangedNotification;
use App\Services\EmployeeDashboardService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveManagementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The redesigned employee dashboard.
 *
 * The leave cases are the reason this exists: the old tile summed every leave
 * type, ignored encashment and floored at zero, so an employee could read
 * "19 days left" while HR's screens said something else. The dashboard now
 * reads the calculator HR and My Time Off use, and these tests pin that.
 *
 * Clock: Friday 2 October 2026, inside leave year 2026/27.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-02 11:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    // Sunday-only week, no location or selfie requirement: Friday is a
    // plain working day and the dashboard may punch directly.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SUNDAY],
        'requires_location' => false, 'requires_photo' => false,
    ]);
});

function edrEmployee(array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create($attributes + [
        'user_id' => $user->id, 'status' => 'active', 'holiday_calendar' => 'IN',
    ]);
}

/** A single, not-yet-ledger balance with the given figures for leave year 2026/27. */
function edrBalance(Employee $employee, array $figures, int $year = 2026): LeaveBalance
{
    LeaveBalance::where('employee_id', $employee->id)->delete();

    $type = LeaveType::firstOrCreate(['code' => 'CSL'], ['name' => 'Casual / Sick Leave', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true]);

    return LeaveBalance::create($figures + [
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year,
    ]);
}

/** @return array{0: Employee, 1: LeaveType, 2: LeaveBalance} Base 20, carry 3, accrual 1, used 6; 2 days pending. */
function edrLedgerEmployee(): array
{
    $policy = LeavePolicy::create([
        'name' => 'Dashboard '.random_int(100, 999), 'statutory_weeks' => 5.6, 'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional', 'irregular_accrual_rate' => 0.1207, 'is_default' => false, 'is_active' => true,
    ]);
    $type = LeaveType::create(['name' => 'Ledger Holiday', 'code' => 'LH'.random_int(10000, 99999), 'category' => 'other', 'is_paid' => true, 'allow_paid_request' => true]);
    LeavePolicyRule::create(['leave_policy_id' => $policy->id, 'leave_type_id' => $type->id, 'entitlement_method' => 'fixed_days', 'fixed_days' => 20]);

    $employee = edrEmployee(['leave_policy_id' => $policy->id, 'joining_date' => '2024-01-08']);

    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', 2026)->firstOrFail();
    $ledger = app(LeaveLedgerService::class);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_CARRY_FORWARD, 3, Carbon::parse('2026-07-01'), 'edr:cf:'.$balance->id);
    $ledger->credit($balance, LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-08-01'), 'edr:acc:'.$balance->id);
    $ledger->debit($balance, LeaveLedgerEntry::TYPE_USAGE, 6, Carbon::parse('2026-07-20'), 'edr:use:'.$balance->id);
    $ledger->rebuild($balance);

    LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-09', 'end_date' => '2026-11-10',
        'days' => 2, 'reason' => 'Trip', 'requested_leave_status' => 'paid', 'status' => 'pending',
    ]);

    return [$employee, $type, $balance->fresh()];
}

/** The dashboard's figures for one leave type, wherever it sits on the card. */
function edrLeaveType(array $leave, int $typeId): ?array
{
    return collect([$leave['primary']])->merge($leave['others'])->filter()->firstWhere('id', $typeId);
}

// ── Leave: one authoritative figure ────────────────────────────────────────

test('leave available is allocated minus used minus encashed, not the allocation', function () {
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 19, 'used_days' => 5, 'encashed_days' => 2]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leave', fn ($leave) => $leave['primary']['available'] === 12.0 && $leave['primary']['used'] === 5.0)
        ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->firstWhere('label', 'Available Leave')['value'] === '12 days')
        ->assertSee('Leave Summary')
        ->assertDontSee('19 / 19');
});

test('an overdrawn balance stays negative and is flagged, never shown as zero', function () {
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 2, 'used_days' => 5]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leave', fn ($leave) => $leave['primary']['available'] === -3.0)
        ->assertSee('Overdrawn');
});

test('a zero balance reads as zero available', function () {
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 4, 'used_days' => 4]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertViewHas('leave', fn ($leave) => $leave['primary']['available'] === 0.0)
        ->assertDontSee('Overdrawn');
});

test('the dashboard, My Time Off and HR leave detail show the same ledger figure', function () {
    [$employee, $type, $balance] = edrLedgerEmployee();

    $calculator = app(LeaveBalanceCalculator::class)->summary($balance);
    $hrDetail = app(LeaveManagementService::class)->employeeDetail($employee, LeaveYear::where('label', '2026/27')->first())
        ->first(fn ($row) => $row['leave_type']?->id === $type->id)['summary'];
    $myTimeOff = Livewire::actingAs($employee->user)->test(MyLeaveBalances::class)
        ->get('cards')->first(fn ($card) => $card['type']->id === $type->id)['summary'];

    $dashboard = edrLeaveType(app(EmployeeDashboardService::class)->build($employee->user->fresh())['leave'], $type->id);

    // 20 + 3 + 1 − 6 = 18 approved; 2 pending leaves 16 to request.
    expect($calculator['approved_available'])->toBe(18.0)
        ->and($dashboard['available'])->toBe($calculator['approved_available'])
        ->and($dashboard['available'])->toBe($hrDetail['approved_available'])
        ->and($dashboard['available'])->toBe($myTimeOff['approved_available'])
        ->and($dashboard['pending'])->toBe(2.0)
        ->and($dashboard['available_to_request'])->toBe(16.0)
        ->and($dashboard['carry_forward'])->toBe(3.0)
        ->and($dashboard['used'])->toBe(6.0);
});

test('the balance shown is the July–June leave year, not the calendar year', function () {
    // March 2027 is still leave year 2026/27 (legacy integer 2026). Reading the
    // calendar year would pick up the 2027 row instead.
    $this->travelTo(Carbon::parse('2027-03-10 10:00:00'));

    $employee = edrEmployee();
    $current = edrBalance($employee, ['allocated_days' => 10, 'used_days' => 1], 2026);
    LeaveBalance::create([
        'employee_id' => $employee->id, 'leave_type_id' => $current->leave_type_id, 'year' => 2027,
        'allocated_days' => 30, 'used_days' => 0,
    ]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leave', fn ($leave) => $leave['primary']['available'] === 9.0 && $leave['year_label'] === '2026/27');
});

test('My Time Off shows an overdrawn CSL as negative, never zero', function () {
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 2, 'used_days' => 5]);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertViewHas('overview', fn ($o) => $o['available_leave'] === -3.0 && $o['csl']['summary']['approved_available'] === -3.0)
        ->assertSee('-3 Days');
});

test('an employee with no balance for the leave year gets an empty state, not a zero', function () {
    $employee = edrEmployee();
    LeaveBalance::where('employee_id', $employee->id)->delete();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leave', fn ($leave) => $leave['primary'] === null)
        ->assertSee('No leave balance yet');
});

test('a long list of other leave types is capped with a link to My Time Off', function () {
    // Real employees carry a dozen leave types; listing them all stretched the
    // card and the attendance card beside it.
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 9, 'used_days' => 0]);

    foreach (range(1, 8) as $i) {
        $type = LeaveType::create(['name' => 'Extra Leave '.$i, 'code' => 'EX'.$i.random_int(100, 999), 'category' => 'other', 'is_paid' => true]);
        LeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026, 'allocated_days' => 2 + $i, 'used_days' => 0]);
    }

    // CSL leads; the eight special types are listed, six of them on the card.
    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('leave', fn ($leave) => $leave['primary']['name'] === 'Casual / Sick Leave' && $leave['others']->count() === 8)
        ->assertSee('+2 more types in My Time Off');
});

// ── Payroll ────────────────────────────────────────────────────────────────

test('the payroll card shows the employee\'s own latest payslip and nobody else\'s', function () {
    $employee = edrEmployee();
    $colleague = edrEmployee();

    $payroll = Payroll::create(['month' => 'September', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized', 'total_payout' => 1]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $colleague->id, 'gross_salary' => 120000, 'total_deductions' => 20001, 'net_salary' => 99999, 'status' => 'paid']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertSee('No payslip generated yet.')
        ->assertDontSee('99,999');

    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'gross_salary' => 50000, 'total_deductions' => 4322, 'net_salary' => 45678, 'status' => 'paid']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertSee('September 2026')
        ->assertSee('45,678.00')
        ->assertSee('Download payslip')
        ->assertDontSee('99,999');
});

test('the payroll card is left out for an account without view_payslips', function () {
    $employee = edrEmployee();
    $employee->user->forceFill(['role_id' => null])->save();

    Livewire::actingAs($employee->user->fresh())->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('payroll', null)
        ->assertDontSee('No payslip generated yet.')
        ->assertDontSee('My Payslips')
        ->assertViewHas('kpis', fn ($kpis) => collect($kpis)->pluck('label')->doesntContain('Salary'));
});

// ── Performance, documents, announcements ──────────────────────────────────

test('with no performance cycle the card explains itself', function () {
    $employee = edrEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('performance', fn ($p) => $p['cycle'] === null && $p['score'] === null)
        ->assertSee('No active review cycle');
});

test('documents are the employee\'s own and company-wide, never another employee\'s', function () {
    $employee = edrEmployee();
    $colleague = edrEmployee();
    $uploader = User::factory()->create(['role' => UserRole::HrAdmin]);

    $doc = fn (array $attrs) => Document::create($attrs + [
        'file_path' => 'documents/'.Str::random(8).'.pdf', 'file_name' => 'file.pdf', 'uploaded_by' => $uploader->id,
    ]);
    $doc(['title' => 'My Offer Letter', 'category' => 'contract', 'visibility' => 'individual', 'employee_id' => $employee->id]);
    $doc(['title' => 'Holiday Notice', 'category' => 'notice', 'visibility' => 'all']);
    $doc(['title' => 'Colleague Contract', 'category' => 'contract', 'visibility' => 'individual', 'employee_id' => $colleague->id]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertSee('My Offer Letter')
        ->assertSee('Holiday Notice')
        ->assertDontSee('Colleague Contract');
});

test('HR balance postings from one reconciliation read as a single update', function () {
    $employee = edrEmployee();
    $user = $employee->user;

    foreach ([['add', 7], ['deduct', 2], ['add', 1]] as [$action, $days]) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => LeaveBalanceChangedNotification::class,
            'data' => (new LeaveBalanceChangedNotification('Annual Leave', $action, $days, 21, 'Reconciliation'))->toArray($user),
        ]);
    }

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('announcements', fn ($a) => $a['updates']->count() === 1 && $a['updates']->first()['count'] === 3)
        ->assertSee('Your Annual Leave balance was updated by HR')
        ->assertSee('3 changes')
        ->assertDontSee('HR added 7');
});

test('quick actions follow the sidebar menu HR configured', function () {
    MenuSetting::create(['key' => 'wfh', 'is_enabled' => false, 'sort_order' => 4]);
    $employee = edrEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertViewHas('quickActions', fn ($actions) => collect($actions)->pluck('label')->doesntContain('WFH Request')
            && collect($actions)->pluck('label')->contains('Apply Leave'));
});

// ── Today ──────────────────────────────────────────────────────────────────

test('today shows the worked time from the attendance row while clocked in', function () {
    $employee = edrEmployee();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-10-02', 'check_in' => '2026-10-02 09:00:00',
        'status' => 'on_time', 'work_mode' => 'office', 'is_late' => false,
    ]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('today', fn ($t) => $t['state'] === 'present' && $t['worked_minutes'] === 120
            && $t['working'] === true && $t['live_base'] !== null && $t['check_in'] === '9:00 AM')
        ->assertSee('Clock Out');
});

test('an employee can take and end a break from the dashboard', function () {
    $employee = edrEmployee();

    $component = Livewire::actingAs($employee->user)->test(Dashboard::class)->call('clockIn')->call('startBreak');

    $break = BreakLog::where('employee_id', $employee->id)->first();
    expect($break)->not->toBeNull()->and($break->break_end)->toBeNull();

    $component->assertViewHas('today', fn ($t) => $t['on_break'] === true && $t['live_base'] === null)
        ->call('endBreak');

    expect($break->fresh()->break_end)->not->toBeNull();
});

test('a break cannot be started without clocking in', function () {
    $employee = edrEmployee();

    Livewire::actingAs($employee->user)->test(Dashboard::class)->call('startBreak');

    expect(BreakLog::where('employee_id', $employee->id)->count())->toBe(0);
});

test('without a shift the timeline does not invent expected hours', function () {
    ShiftSetting::query()->update(['is_default' => false]);
    $employee = edrEmployee(['shift_id' => null]);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('today', fn ($t) => $t['progress']['measurable'] === false && $t['progress']['expected_minutes'] === null)
        ->assertSee('Shift not assigned');
});

test('the month strip and counts come from real attendance and leave', function () {
    $employee = edrEmployee();
    $type = LeaveType::firstOrCreate(['code' => 'CSL'], ['name' => 'Casual / Sick Leave', 'category' => 'annual', 'is_paid' => true, 'allow_paid_request' => true]);

    // 1 Oct late, 2 Oct (today) on leave.
    Attendance::create(['employee_id' => $employee->id, 'date' => '2026-10-01', 'check_in' => '2026-10-01 09:40:00', 'check_out' => '2026-10-01 18:00:00', 'status' => 'late', 'work_mode' => 'office', 'is_late' => true, 'late_minutes' => 40]);
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-10-02', 'end_date' => '2026-10-02', 'days' => 1, 'reason' => 'Rest', 'status' => 'approved']);

    Livewire::actingAs($employee->user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('attendance', fn ($a) => $a['counts']['present'] === 1 && $a['counts']['late'] === 1
            && $a['counts']['leave'] === 1 && $a['counts']['absent'] === 0 && count($a['days']) === 31
            && $a['days'][0]['state'] === 'late' && $a['days'][1]['state'] === 'leave')
        ->assertViewHas('today', fn ($t) => $t['state'] === 'leave' && $t['is_working_day'] === false);
});

// ── Shell: impersonation and navigation ────────────────────────────────────

test('a super admin viewing as an employee sees the employee dashboard and an exit control', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 10, 'used_days' => 3]);

    $this->actingAs($admin)->post(route('impersonate.start', $employee->user))->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Viewing as')
        ->assertSee($employee->user->name)
        ->assertSee('Exit to Admin')
        ->assertSee(route('impersonate.stop'), false)
        ->assertSee('Attendance Overview')
        ->assertDontSee('Manage Employees')
        ->assertDontSee('Run Payroll');
});

test('a signed-in employee sees no impersonation bar and no admin navigation', function () {
    $employee = edrEmployee();

    $this->actingAs($employee->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Leave Summary')
        ->assertSee("Today's Timeline")
        ->assertDontSee('Exit to Admin')
        ->assertDontSee('Manage Employees')
        ->assertDontSee('Meet Pulse AI');
});

test('an account without an employee record still gets a dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertOk()
        ->assertViewHas('employee', null)
        ->assertSee('Please contact HR');
});

// ── Performance of the page itself ─────────────────────────────────────────

test('the dashboard query count does not grow with the employee\'s history', function () {
    $this->travelTo(Carbon::parse('2026-10-28 11:00:00'));
    $employee = edrEmployee();
    edrBalance($employee, ['allocated_days' => 10, 'used_days' => 1]);
    $type = LeaveType::where('code', 'CSL')->first();
    $uploader = User::factory()->create(['role' => UserRole::HrAdmin]);

    $addHistory = function (int $from, int $count) use ($employee, $type, $uploader) {
        foreach (range($from, $from + $count - 1) as $day) {
            $date = sprintf('2026-10-%02d', $day);
            Attendance::create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => $date.' 09:00:00', 'check_out' => $date.' 18:00:00', 'status' => 'on_time', 'work_mode' => 'office', 'is_late' => false]);
            LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-12-'.sprintf('%02d', $day), 'end_date' => '2026-12-'.sprintf('%02d', $day), 'days' => 1, 'reason' => 'x', 'status' => 'pending']);
            OtRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'start_time' => '18:00', 'end_time' => '20:00', 'requested_hours' => 2, 'reason' => 'x', 'status' => 'approved']);
            WfhRequest::create(['employee_id' => $employee->id, 'start_date' => '2026-11-'.sprintf('%02d', $day), 'end_date' => '2026-11-'.sprintf('%02d', $day), 'reason' => 'x', 'status' => 'pending']);
            Document::create(['title' => 'Doc '.$day, 'file_path' => 'd/'.$day.'.pdf', 'file_name' => $day.'.pdf', 'category' => 'contract', 'visibility' => 'individual', 'employee_id' => $employee->id, 'uploaded_by' => $uploader->id]);
            $employee->user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\Generic', 'data' => ['title' => 'Note '.$day, 'body' => 'Body', 'icon' => 'bell']]);
        }
    };

    $queries = function () use ($employee) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(EmployeeDashboardService::class)->build($employee->user->fresh());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $addHistory(1, 1);
    $queries(); // warm per-request memoisation
    $before = $queries();

    $addHistory(2, 15);
    $after = $queries();

    expect($after)->toBe($before);
});
