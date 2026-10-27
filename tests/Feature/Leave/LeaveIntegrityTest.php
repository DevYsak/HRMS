<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveIntegrityService as Integrity;
use App\Services\Leave\LeaveLedgerService;
use App\Services\Leave\LeaveMovementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * leave:verify finds what must never happen — duplicates, orphans and
 * mismatches — and reports it without repairing anything.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));
    $this->year = LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    $this->type = LeaveType::create(['name' => 'Earned Leave', 'code' => 'ELV2', 'category' => 'other', 'leave_category' => LeaveCategory::EARNED,
        'is_paid' => true, 'allow_paid_request' => true, 'color' => '#10b981']);
    $this->employee = Employee::factory()->create(['status' => 'active', 'manager_id' => null, 'department_id' => Department::factory()->create()->id, 'employee_id' => 'IN-001']);
    $this->balance = app(LeaveMovementService::class)->balanceFor($this->employee->id, $this->type->id, Carbon::parse('2026-07-01'));
    app(LeaveMovementService::class)->ledgerReady($this->balance);
    app(LeaveLedgerService::class)->credit($this->balance->fresh(), LeaveLedgerEntry::TYPE_BASE, 5, Carbon::parse('2026-07-01'), 'in-base');
    app(LeaveLedgerService::class)->rebuild($this->balance->fresh());
});

function inChecks(): array
{
    return app(Integrity::class)->scan(test()->year)->pluck('check')->all();
}

function inRequest(array $attributes = []): LeaveRequest
{
    return LeaveRequest::create($attributes + ['employee_id' => test()->employee->id, 'leave_type_id' => test()->type->id, 'main_category' => LeaveCategory::EARNED,
        'balance_leave_type_id' => test()->type->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'days' => 2, 'reason' => 'Test', 'status' => 'approved',
        'requested_leave_status' => 'paid', 'approved_leave_status' => 'paid']);
}

function inUsage(LeaveRequest $request, float $days, string $key): void
{
    app(LeaveLedgerService::class)->debit(test()->balance->fresh(), LeaveLedgerEntry::TYPE_USAGE, $days, Carbon::parse('2026-10-05'), $key,
        ['source_type' => 'leave_request', 'source_id' => $request->id]);
    app(LeaveLedgerService::class)->rebuild(test()->balance->fresh());
}

test('a clean ledger reports nothing and the command exits successfully', function () {
    inUsage(inRequest(), 2, 'in-usage');

    expect(inChecks())->toBe([]);
    $this->artisan('leave:verify', ['--year' => '2026/27'])->expectsOutputToContain('No findings.')->assertSuccessful();
});

test('a request charged twice is a duplicate deduction', function () {
    $request = inRequest();
    inUsage($request, 2, 'in-usage-1');
    inUsage($request, 2, 'in-usage-2');

    expect(inChecks())->toContain(Integrity::DUPLICATE_DEDUCTION);
});

test('the same month accrued twice is a duplicate accrual', function () {
    foreach (['a', 'b'] as $k) {
        app(LeaveLedgerService::class)->credit($this->balance->fresh(), LeaveLedgerEntry::TYPE_ACCRUAL, 1, Carbon::parse('2026-07-31'), 'in-accrual-'.$k, ['meta' => ['month' => '2026-07']]);
    }

    expect(inChecks())->toContain(Integrity::DUPLICATE_ACCRUAL);
});

test('usage for a request no longer approved is an orphan; approved paid days without usage are a mismatch', function () {
    inUsage(inRequest(['status' => 'cancelled']), 2, 'in-orphan');
    inRequest(['start_date' => '2026-10-12', 'end_date' => '2026-10-12', 'days' => 1]);

    expect(inChecks())->toContain(Integrity::ORPHAN_LEDGER)->toContain(Integrity::USAGE_MISMATCH);
});

test('a balance that disagrees with its ledger, or is overdrawn, is reported — and left alone', function () {
    DB::table('leave_balances')->where('id', $this->balance->id)->update(['base_days' => 9]);
    $before = LeaveBalance::find($this->balance->id)->toArray();

    expect(inChecks())->toContain(Integrity::BALANCE_LEDGER_MISMATCH);
    expect(LeaveBalance::find($this->balance->id)->toArray())->toBe($before);

    $this->artisan('leave:verify', ['--year' => '2026/27'])->assertFailed();
});

test('a Combined request whose header disagrees with its day rows is reported', function () {
    $request = inRequest(['request_mode' => LeaveRequest::MODE_COMBINED, 'days' => 3, 'lwp_days' => 1, 'status' => 'pending']);
    foreach ([['2026-10-05', 1, 0], ['2026-10-06', 0, 1]] as [$date, $paid, $unpaid]) {
        LeaveRequestDay::create(['leave_request_id' => $request->id, 'date' => $date, 'day_status' => 'working',
            'allocation_type' => $paid ? LeaveRequestDay::FULL_PAID : LeaveRequestDay::FULL_UNPAID, 'paid_units' => $paid, 'unpaid_units' => $unpaid]);
    }

    expect(inChecks())->toContain(Integrity::COMBINED_MISMATCH);
});
