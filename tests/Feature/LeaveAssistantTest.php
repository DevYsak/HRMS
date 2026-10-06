<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\AiSetting;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EmployeeLeaveOverviewService;
use App\Services\Leave\LeaveAssistantService;
use App\Services\Leave\LeaveRegisterReconciliationService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The Leave Assistant in Apply Leave explains the request from live data —
 * the same overview, calculator, rules and holiday calendar the submit uses
 * — and never decides anything itself.
 *
 * Clock: Monday 5 October 2026 (leave year 2026/27). The employee is a UK
 * register employee with 2 credited + 7 carried − 5 used = 4 CSL.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
    LeaveYear::firstOrCreate(['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'], ['label' => '2026/27']);
    foreach (range(28, 31) as $day) {
        DecemberMandatoryDay::create(['year' => 2026, 'date' => "2026-12-{$day}", 'description' => 'Company shutdown']);
    }
    conexusPaidLeave();
    app(ConexusLeavePolicyService::class)->apply();
});

function laEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK']);

    app(LeaveRegisterReconciliationService::class)->reconcileEmployee(
        $employee->fresh(),
        ['name' => 'Test', 'emails' => [$user->email], 'credit' => 2, 'carry' => 7, 'used' => 5, 'encashed' => 0, 'available' => 4],
        LeaveYear::where('label', '2026/27')->first(),
        LeaveType::where('code', 'CSL')->firstOrFail(),
        LeaveType::withTrashed()->where('code', 'AL')->first(),
        null,
    );

    return $employee->fresh();
}

function laExplain(Employee $employee, ?string $start = null, ?string $end = null, ?LeaveType $type = null, string $paid = 'paid'): array
{
    $overview = app(EmployeeLeaveOverviewService::class)->for($employee);

    return app(LeaveAssistantService::class)->explain($employee, $overview, $type ?? LeaveType::where('code', 'CSL')->first(), $start, $end, false, $paid);
}

function laTexts(array $assistant): string
{
    return collect($assistant['messages'])->pluck('text')->implode(' | ');
}

test('the available CSL, carry forward and leave year come from the live balance', function () {
    $employee = laEmployee();
    $overview = app(EmployeeLeaveOverviewService::class)->for($employee);
    $assistant = laExplain($employee);

    expect($assistant['facts']['csl_available'])->toBe(round((float) $overview['csl']['summary']['available_to_request'], 2))
        ->and($assistant['facts']['csl_available'])->toBe(4.0)
        ->and($assistant['facts']['csl_carry_forward'])->toBe(7.0)
        ->and($assistant['facts']['leave_year'])->toBe('2026/27')
        ->and(laTexts($assistant))->toContain('You currently have 4 days')
        ->toContain('7 days carried forward');
});

test('a request the balance covers is explained as paid', function () {
    $assistant = laExplain(laEmployee(), '2026-10-12', '2026-10-13');

    expect($assistant['facts']['requested_days'])->toBe(2.0)
        ->and(laTexts($assistant))->toContain('Requested leave is paid: 2 days of your 4 days');
});

test('a request longer than the balance says how much is available, and suggests unpaid', function () {
    // Mon 12 → Mon 19 Oct: six working days, the weekend not counted.
    $assistant = laExplain(laEmployee(), '2026-10-12', '2026-10-19');

    expect($assistant['facts']['requested_days'])->toBe(6.0)
        ->and(laTexts($assistant))->toContain('Only 4 days')
        ->toContain('for your 6 days request')
        ->toContain('Weekly offs inside the range')
        ->and($assistant['suggestion']['name'])->toContain('unpaid');
});

test('available Comp Off is offered instead of CSL', function () {
    $employee = laEmployee();
    app(LeaveService::class)->creditCompOff($employee, Carbon::parse('2026-09-26'), 1.0);

    $assistant = laExplain($employee, '2026-10-12', '2026-10-12');

    expect($assistant['facts']['comp_off_available'])->toBe(1.0)
        ->and(laTexts($assistant))->toContain('You have 1 day of Comp Off available; you may use it instead of')
        ->and($assistant['suggestion']['name'])->toBe(LeaveType::where('code', 'CO')->value('name'));
});

test('dates overlapping the December shutdown are explained, not charged', function () {
    $assistant = laExplain(laEmployee(), '2026-12-24', '2026-12-29');

    expect($assistant['facts']['mdl_in_range'])->toHaveCount(2)
        ->and(laTexts($assistant))->toContain('overlap the Mandatory December shutdown')
        ->toContain('do not require CSL');
});

test('a weekly-off start is flagged as the server will refuse it', function () {
    $assistant = laExplain(laEmployee(), '2026-10-10', '2026-10-12');

    expect(laTexts($assistant))->toContain('cannot start or end on a weekly off');
});

test('only holidays on the employee\'s own calendar are flagged — and only those block the submit', function () {
    $employee = laEmployee();
    PublicHoliday::create(['name' => 'Gandhi Jayanti (moved)', 'date' => '2026-10-14', 'country' => 'IN']);
    PublicHoliday::create(['name' => 'Company Day', 'date' => '2026-10-21', 'country' => 'UK']);

    $indian = laExplain($employee, '2026-10-14', '2026-10-14');
    $uk = laExplain($employee, '2026-10-21', '2026-10-21');

    expect($indian['facts']['holidays_in_range'])->toBe([])
        ->and($uk['facts']['holidays_in_range'][0])->toContain('Company Day');

    $leave = app(LeaveService::class);
    expect($leave->holidayWithinRange($employee, Carbon::parse('2026-10-14'), Carbon::parse('2026-10-14')))->toBeNull()
        ->and($leave->holidayWithinRange($employee, Carbon::parse('2026-10-21'), Carbon::parse('2026-10-21'))?->name)->toBe('Company Day');
});

test('half-day and encashment eligibility are read from the rules', function () {
    $texts = laTexts(laExplain(laEmployee()));

    expect($texts)->toMatch('/Half days are allowed for|must be taken in whole days/')
        ->toMatch('/can be encashed|cannot be encashed/');
});

test('the panel shows in Apply Leave, and AI is offered only when it is enabled', function () {
    $employee = laEmployee();
    AiSetting::current()->update(['enabled' => false]);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->set('showRequestModal', true)
        ->set('leave_type_id', LeaveType::where('code', 'CSL')->value('id'))
        ->set('start_date', '2026-10-12')->set('end_date', '2026-10-13')
        ->assertSee('Leave Assistant')
        ->assertSee('Requested leave is paid')
        ->assertDontSee('Explain in plain words')
        ->call('explainWithAi')
        ->assertSet('assistantAiText', null);
});

test('using the suggested type switches the form to it', function () {
    $employee = laEmployee();
    app(LeaveService::class)->creditCompOff($employee, Carbon::parse('2026-09-26'), 1.0);
    $co = LeaveType::where('code', 'CO')->firstOrFail();

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->set('showRequestModal', true)
        ->set('leave_type_id', LeaveType::where('code', 'CSL')->value('id'))
        ->set('start_date', '2026-10-12')->set('end_date', '2026-10-12')
        ->call('useSuggestedType', $co->id)
        ->assertSet('leave_type_id', $co->id);
});
