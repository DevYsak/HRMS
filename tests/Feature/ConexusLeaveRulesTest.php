<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveMovementService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\Leave\LeaveRuleResolver;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * How leave behaves once the Conexus policy is in place: CSL is requestable
 * (half-days too) against one authoritative balance, MDL dates and public
 * holidays are never charged, Comp Off is earned and never lapses, and only
 * CSL can be encashed — approved by a Director or the HR Admin.
 *
 * Clock: Monday 5 October 2026 (leave year 2026/27); Saturday and Sunday are off.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);

    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SATURDAY, Carbon::SUNDAY],
        'requires_location' => false, 'requires_photo' => false,
    ]);

    foreach (range(26, 31) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }

    // The CSL is production's existing Paid Leave type, renamed in place.
    conexusPaidLeave();
    app(ConexusLeavePolicyService::class)->apply();
});

/** An employee whose 2026/27 CSL is credit 2 + carry 7 (9 available), as the register would set it. */
function crEmployee(float $credit = 2, float $carry = 7, float $used = 0, array $attributes = []): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create($attributes + [
        'user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08', 'holiday_calendar' => 'IN',
    ]);

    $service = app(LeaveRegisterReconciliationService::class);
    $year = LeaveYear::where('label', '2026/27')->first();
    $service->reconcileEmployee($employee->fresh(), [
        'name' => 'Test', 'emails' => [$user->email], 'credit' => $credit, 'carry' => $carry, 'used' => $used,
        'encashed' => 0, 'available' => $credit + $carry - $used,
    ], $year, crCsl(), LeaveType::withTrashed()->where('code', 'AL')->first(), null);

    return $employee->fresh();
}

function crCsl(): LeaveType
{
    return LeaveType::where('code', 'CSL')->firstOrFail();
}

function crSummary(Employee $employee, string $yearLabel = '2026/27'): array
{
    $year = LeaveYear::where('label', $yearLabel)->firstOrFail();
    $balance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', crCsl()->id)
        ->where(fn ($q) => $q->where('leave_year_id', $year->id)->orWhere('year', $year->legacyYear()))->firstOrFail();

    return app(LeaveBalanceCalculator::class)->summary($balance);
}

function crApply(Employee $employee, string $start, string $end, bool $halfDay = false): LeaveRequest
{
    return app(LeaveService::class)->submitRequest($employee, crCsl(), $start, $end, 'Personal', $halfDay, $halfDay ? 'first_half' : null);
}

// ── Leave year ──────────────────────────────────────────────────────────────

test('the leave year runs 1 July to 30 June', function () {
    $years = app(LeaveYearResolver::class);

    expect($years->forDate(Carbon::parse('2026-07-01'))->label)->toBe('2026/27')
        ->and($years->forDate(Carbon::parse('2027-06-30'))->label)->toBe('2026/27')
        ->and($years->forDate(Carbon::parse('2026-06-30'))->label)->toBe('2025/26')
        ->and($years->forDate(Carbon::parse('2027-03-10'))->label)->toBe('2026/27');
});

// ── Policy metadata ─────────────────────────────────────────────────────────

test('CSL carry forward has no cap and no expiry; Comp Off has no expiry', function () {
    $employee = crEmployee();
    $resolver = app(LeaveRuleResolver::class);
    $csl = $resolver->settings($employee, crCsl());
    $compOff = $resolver->settings($employee, app(ConexusLeavePolicyService::class)->compOffType() ?? LeaveType::create(['name' => 'Comp Off', 'code' => 'CO', 'category' => 'comp_off', 'is_paid' => true]));

    expect($csl['carry_forward_enabled'])->toBeTrue()
        ->and($csl['carry_forward_max_days'])->toBeNull()
        ->and($csl['carry_forward_expiry_months'])->toBeNull()
        ->and($csl['carry_forward_expiry_date'])->toBeNull()
        ->and($csl['allow_half_day'])->toBeTrue()
        ->and(LeaveLedgerEntry::where('employee_id', $employee->id)->where('entry_type', 'carry_forward')->whereNull('reverses_entry_id')->value('expires_on'))->toBeNull()
        ->and($compOff['carry_forward_expiry_months'])->toBeNull()
        ->and($compOff['carry_forward_expiry_date'])->toBeNull();
});

test('MDL is six configured dates, not a leave type anyone can request', function () {
    $year = LeaveYear::where('label', '2026/27')->first();

    expect(DecemberMandatoryDay::forLeaveYear($year))->toHaveCount(6)
        ->and(app(ConexusLeavePolicyService::class)->policy()->mandatory_leave_days)->toBe(6)
        ->and(LeaveType::where('allow_paid_request', true)->whereRaw('LOWER(name) LIKE ?', ['%mandatory%'])->exists())->toBeFalse();
});

// ── Applying for leave ──────────────────────────────────────────────────────

test('a full and a half day of CSL can be requested', function () {
    $employee = crEmployee();

    expect((float) crApply($employee, '2026-10-12', '2026-10-13')->days)->toBe(2.0)
        ->and((float) crApply($employee, '2026-10-15', '2026-10-15', halfDay: true)->days)->toBe(0.5);
});

test('leave on an MDL date is refused', function () {
    $employee = crEmployee();

    expect(fn () => crApply($employee, '2026-12-28', '2026-12-28'))
        ->toThrow(DomainException::class, 'Mandatory December Leave');
});

test('a public holiday is refused and consumes no CSL', function () {
    $employee = crEmployee();
    PublicHoliday::create(['date' => '2026-10-20', 'name' => 'Dussehra', 'country' => 'IN']);
    $before = crSummary($employee)['approved_available'];

    expect(fn () => crApply($employee, '2026-10-19', '2026-10-21'))->toThrow(DomainException::class, 'company holiday');
    expect(crSummary($employee)['approved_available'])->toBe($before);
});

test('a request beyond the available CSL is refused', function () {
    $employee = crEmployee(); // 9 available

    expect(fn () => crApply($employee, '2026-10-12', '2026-10-23'))->toThrow(DomainException::class);
});

test('pending requests reserve their days so leave cannot be overbooked', function () {
    $employee = crEmployee(); // 9 available
    crApply($employee, '2026-10-12', '2026-10-16'); // Mon–Fri: 5 days, pending

    expect(crSummary($employee)['available_to_request'])->toBe(4.0)
        ->and(fn () => crApply($employee, '2026-10-19', '2026-10-23'))->toThrow(DomainException::class);
});

test('a request awaiting more information still holds its dates', function () {
    $employee = crEmployee();
    crApply($employee, '2026-10-12', '2026-10-12')->update(['status' => 'more_info_requested']);

    expect(fn () => crApply($employee, '2026-10-12', '2026-10-12'))->toThrow(DomainException::class, 'already have');
});

test('a real negative balance stays visible and blocks new requests', function () {
    $employee = crEmployee(credit: 2, carry: 0, used: 3);

    expect(crSummary($employee)['approved_available'])->toBe(-1.0)
        ->and(fn () => crApply($employee, '2026-10-12', '2026-10-12', halfDay: true))->toThrow(DomainException::class);
});

test('cancelling leave returns it to the leave year the leave was in', function () {
    $employee = crEmployee();
    $request = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => crCsl()->id, 'start_date' => '2027-06-28', 'end_date' => '2027-06-28',
        'days' => 1, 'reason' => 'June', 'status' => 'approved', 'requested_leave_status' => 'paid',
    ]);
    app(LeaveMovementService::class)->recordUsage($request, 1, crCsl()->id, Carbon::parse('2027-06-28'));
    expect(crSummary($employee)['approved_available'])->toBe(8.0);

    $this->travelTo(Carbon::parse('2027-07-05 10:00:00'));
    app(LeaveService::class)->cancelRequest($request->fresh());

    $nextYear = LeaveYear::where('starts_on', '2027-07-01')->first();
    expect(crSummary($employee)['approved_available'])->toBe(9.0)
        ->and($nextYear === null || ! LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', crCsl()->id)->where('leave_year_id', $nextYear->id)->where('used_days', '<', 0)->exists())->toBeTrue();
});

// ── Comp Off ────────────────────────────────────────────────────────────────

test('working an MDL day earns one Comp Off credit that never expires', function () {
    $employee = crEmployee();
    $this->travelTo(Carbon::parse('2026-12-28 09:30:00'));
    $attendance = app(AttendanceService::class)->checkIn($employee, $employee->shift, ['ip' => '127.0.0.1']);
    $this->travelTo(Carbon::parse('2026-12-28 18:00:00'));
    app(AttendanceService::class)->checkOut($attendance->fresh(), ['ip' => '127.0.0.1']);

    $compOff = LeaveType::where('category', 'comp_off')->firstOrFail();
    $credit = LeaveLedgerEntry::where('employee_id', $employee->id)->where('leave_type_id', $compOff->id)->where('entry_type', 'add_on')->first();

    expect($credit)->not->toBeNull()
        ->and((float) $credit->days)->toBe(1.0)
        ->and($credit->expires_on)->toBeNull()
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

// ── Encashment ──────────────────────────────────────────────────────────────

test('only CSL can be encashed', function () {
    $employee = crEmployee();
    $compOff = LeaveType::firstOrCreate(['category' => 'comp_off'], ['name' => 'Comp Off', 'code' => 'CO', 'is_paid' => true]);
    $compOff->forceFill(['allow_encashment' => true])->save(); // even if someone flips the flag

    expect(fn () => app(LeaveService::class)->requestEncashment($employee, $compOff, 1, now()->format('Y-m')))
        ->toThrow(DomainException::class, 'not eligible')
        ->and(fn () => app(LeaveService::class)->requestEncashment($employee, LeaveType::withTrashed()->where('code', 'AL')->first(), 1, now()->format('Y-m')))
        ->toThrow(DomainException::class, 'not eligible');

    expect(app(LeaveService::class)->requestEncashment($employee, crCsl(), 2, now()->format('Y-m'))->status)->toBe('pending');
});

test('encashment cannot use days already promised to pending leave or another encashment', function () {
    $employee = crEmployee(); // 9 available
    crApply($employee, '2026-10-12', '2026-10-16'); // 5 pending → 4 free
    $service = app(LeaveService::class);

    $service->requestEncashment($employee, crCsl(), 2, now()->format('Y-m')); // 2 free left

    expect(fn () => $service->requestEncashment($employee, crCsl(), 3, now()->format('Y-m')))
        ->toThrow(DomainException::class, 'Insufficient');
});

test('a Director or the HR Admin approves encashment; a manager cannot', function () {
    $employee = crEmployee();
    $encashment = app(LeaveService::class)->requestEncashment($employee, crCsl(), 2, now()->format('Y-m'));

    $manager = User::factory()->create(['role' => UserRole::Manager]);
    expect(fn () => app(LeaveService::class)->approveEncashment($manager, $encashment, ''))
        ->toThrow(DomainException::class, 'Director or the HR Admin');

    $director = User::factory()->create(['role' => UserRole::Director]);
    app(LeaveService::class)->approveEncashment($director, $encashment->fresh(), 'OK');

    expect($encashment->fresh()->status)->toBe('pending_finance');
});

test('approved encashment posts to the ledger, reduces CSL and lands in a payroll month that is still open', function () {
    $employee = crEmployee();
    $encashment = app(LeaveService::class)->requestEncashment($employee, crCsl(), 2, '2026-09');
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $finance = User::factory()->create(['role' => UserRole::Finance]);

    app(LeaveService::class)->approveEncashment($hr, $encashment, '');
    app(LeaveService::class)->financeApproveEncashment($finance, $encashment->fresh(), '');

    $summary = crSummary($employee);
    expect($summary['encashed'])->toBe(2.0)
        ->and($summary['approved_available'])->toBe(7.0)
        ->and(LeaveEncashment::find($encashment->id)->payout_month)->toBe('2026-10')
        ->and(LeaveLedgerEntry::where('source_type', 'leave_encashment')->where('source_id', $encashment->id)->count())->toBe(1);
});

// ── Permissions ─────────────────────────────────────────────────────────────

test('an employee still cannot open HR leave management', function () {
    $employee = crEmployee();

    $this->actingAs($employee->user)->get(route('time-off.leave-management'))->assertForbidden();
});
