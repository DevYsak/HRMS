<?php

use App\Enums\UserRole;
use App\Livewire\ApprovalCenter;
use App\Livewire\Attendance\AttendanceReports;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\ExecutiveDashboard;
use App\Livewire\Holidays\HolidayCalendar;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Overtime\MyOtRequests;
use App\Livewire\Payroll\Process;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\Wfh\ManageWfhRequests;
use App\Livewire\Wfh\MyWfhRequests;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * Phase 5 · Responsive / honest UI. Screens measured at 375px before and
 * after (Playwright, throwaway e2e app): these pin the markup that fixed them.
 */
function rlUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

test('a KPI tile never cuts its label to one letter, and draws a sparkline only from a real series', function () {
    $empty = Blade::render('<x-dashboard.kpi-card label="Pending Compliance" value="77" compare="docs + reviews" :spark="[]" />');
    $real = Blade::render('<x-dashboard.kpi-card label="Total Employees" value="7" :spark="[5, 6, 7]" />');

    expect($empty)->toContain('line-clamp-2')->not->toContain('truncate text-[13px]')->not->toContain('<polyline')
        ->and($empty)->toContain('min-h-[150px]')
        ->and($real)->toContain('<polyline')->toContain('hidden h-10 w-20 shrink-0 overflow-visible sm:block');
});

test('the Executive view shows sparklines only for real six-month series, never an invented trend', function () {
    $html = Livewire::actingAs(rlUser('super_admin'))->test(ExecutiveDashboard::class)->html();

    // Headcount, attendance and payroll payout have real series; approvals, compliance and performance do not.
    expect(substr_count($html, '<polyline'))->toBe(3);
});

test('Run Payroll filter rows wrap on a phone', function () {
    Livewire::actingAs(rlUser('hr_admin'))->test(Process::class)
        ->assertSeeHtml('<div class="flex flex-wrap gap-2">')
        ->assertSeeHtml('mt-8 flex flex-wrap justify-center gap-3');
});

test('QA open-finding rows wrap instead of squeezing the title', function () {
    $this->actingAs(rlUser('super_admin'))->get(route('qa-audit.index'))->assertOk()
        ->assertSee('flex w-full flex-wrap items-center gap-x-3 gap-y-1.5', false)
        ->assertSee('min-w-[12rem] flex-1', false);
});

test('hero KPI tiles stack their icon on phones and never cut the label to a few letters', function () {
    $employee = Employee::factory()->create(['status' => 'active']);
    $stacked = 'flex flex-col items-start gap-2 rounded-xl';

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertSeeHtml($stacked)
        ->assertSeeHtml('line-clamp-2 break-words text-[10px] font-bold uppercase leading-snug tracking-wide text-zinc-400">Available Leave')
        ->assertDontSeeHtml('truncate text-[10px] font-bold uppercase tracking-wide text-zinc-400">Available Leave');

    Livewire::actingAs(rlUser('hr_admin'))->test(CommandCenter::class)
        ->assertSeeHtml($stacked)
        ->assertDontSeeHtml('truncate text-[10px] font-bold uppercase tracking-wide text-zinc-400">Total Pending');
});

test('empty tables keep their message in view on phones', function () {
    $employee = Employee::factory()->create(['status' => 'active']);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)->assertSeeHtml('<div class="table-empty-pin">');
    Livewire::actingAs($employee->user)->test(MyWfhRequests::class)->assertSeeHtml('<div class="table-empty-pin">');
    Livewire::actingAs(rlUser('hr_admin'))->test(ManageWfhRequests::class)->assertSeeHtml('<div class="table-empty-pin">');
    Livewire::actingAs(rlUser('hr_admin'))->test(ManageOtRequests::class)->assertSeeHtml('<div class="table-empty-pin">');

    expect(file_get_contents(resource_path('css/app.css')))->toContain('.table-empty-pin { position: sticky; left: 0;');
});

test('list month filters offer "All months" instead of an empty native month box', function () {
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    $employee = Employee::factory()->create(['status' => 'active']);

    foreach ([MyWfhRequests::class, MyOtRequests::class] as $component) {
        Livewire::actingAs($employee->user)->test($component)
            ->assertSeeHtml('data-month-filter')
            ->assertDontSeeHtml('type="month"')
            ->assertSeeHtml('<option value="">All months</option>')
            ->assertSeeHtml('<option value="2026-10">Oct 2026</option>')
            ->assertSeeHtml('<option value="2024-11">Nov 2024</option>')
            ->set('filterMonth', '2023-02')
            ->assertSeeHtml('wire:model.live="filterMonth"')
            ->assertSeeHtml('<option value="2023-02">Feb 2023</option>');
    }
});

test('the holiday month grid fits a phone without a sideways scroller', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'holiday_calendar' => 'UK']);

    Livewire::actingAs($user)->test(HolidayCalendar::class)
        ->assertSeeHtml('data-holiday-month-grid')
        ->assertDontSeeHtml('min-w-[560px]');
});

test('the attendance report table has dark-mode borders and header', function () {
    $employee = Employee::factory()->create(['status' => 'active']);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(9, 0), 'check_out' => today()->setTime(18, 0),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9,
    ]);

    Livewire::actingAs(rlUser('hr_admin'))->test(AttendanceReports::class)
        ->assertSeeHtml('dark:border-white/10 dark:bg-white/5')
        ->assertSeeHtml('divide-y divide-zinc-50 dark:divide-white/5')
        ->assertDontSeeHtml('border-orange-100/70 bg-white dark:bg-zinc-900');
});

test('a zero pending count reads neutral, not as an alarm', function () {
    Livewire::actingAs(rlUser('super_admin'))->test(ApprovalCenter::class)
        ->assertSeeHtml('bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400" data-pending-pill')
        ->assertSee('0 pending');
});

test('department ranking pluralises headcount', function () {
    $department = Department::factory()->create(['name' => 'Solo Desk']);
    Employee::factory()->create(['status' => 'active', 'department_id' => $department->id]);

    $html = Livewire::actingAs(rlUser('super_admin'))->test(ExecutiveDashboard::class)->html();

    expect($html)->toContain('1 employee</div>')->not->toContain('1 employees</div>');
});
