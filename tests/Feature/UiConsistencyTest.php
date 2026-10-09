<?php

use App\Enums\UserRole;
use App\Livewire\DepartmentDashboard;
use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * One design system for the dashboards: a single sans-serif stack and the
 * shared pulse.* components (header, cards, tables, empty states).
 */
test('no view sets an arbitrary font family that could fall back to serif', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(fn ($file) => ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'pdf'.DIRECTORY_SEPARATOR))
        ->filter(fn ($file) => $file->getFilename() !== 'welcome.blade.php') // stock framework page with inlined CSS
        ->filter(fn ($file) => preg_match('/font-\[[\'"]|\bfont-serif\b/', File::get($file->getPathname())) === 1)
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([], 'Use font-sans (the app stack), not an arbitrary family: '.implode(', ', $offenders));
});

test('the dashboard primitives render', function () {
    $html = Blade::render(<<<'BLADE'
        <x-pulse.empty-state title="Nothing here" text="Come back later." href="/x" cta="Open" />
        <x-pulse.attention-list :items="[['label' => 'Leave approvals', 'count' => 3, 'href' => '/a'], ['label' => 'OT approvals', 'count' => 0]]" />
        <x-pulse.table :columns="['Name', ['label' => 'Hours', 'class' => 'text-right']]"><tr><td>Ada</td><td>8</td></tr></x-pulse.table>
        <x-pulse.quick-action label="Apply leave" icon="calendar-days" href="/leave" />
        <x-pulse.grid :split="true"><div>left</div><div>right</div></x-pulse.grid>
    BLADE);

    expect($html)->toContain('Nothing here', 'Leave approvals', 'OT approvals', 'Ada', 'Apply leave', 'xl:grid-cols-[minmax(0,13fr)_minmax(0,7fr)]');
});

test('Team View uses the shared layout and keeps its approvals and markers', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $this->actingAs($manager);

    Livewire::test(ManagerDashboard::class)
        ->assertOk()
        ->assertSee('Team View')
        ->assertSee('Attendance, approvals and team performance at a glance.')
        ->assertSee('Needs Attention')
        ->assertSee('Team Attendance — Today')
        ->assertSee('Team Attendance Trend')
        ->assertSee('Leave This Week')
        ->assertSee('Team KPI Scores')
        ->assertSee('No active KPI review data.')
        ->assertDontSee('No KPI scores for your team in the latest cycle.');
});

test('the Team View trend covers at most seven working days and skips weekly offs', function () {
    $this->travelTo(now()->next('Thursday')->setTime(15, 0));

    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $member = Employee::factory()->create(['user_id' => User::factory()->create(['role' => UserRole::Employee])->id, 'status' => 'active', 'manager_id' => $manager->id, 'joining_date' => now()->subYear()]);

    foreach ([1, 2, 3, 4, 5] as $back) {
        Attendance::withoutEvents(fn () => Attendance::create([
            'employee_id' => $member->id, 'date' => now()->subDays($back)->toDateString(),
            'check_in' => now()->subDays($back)->setTime(9, 5), 'check_out' => now()->subDays($back)->setTime(18, 0),
            'check_in_method' => 'web', 'check_out_method' => 'web', 'status' => 'on_time', 'work_mode' => 'office',
            'is_late' => false, 'late_minutes' => 0, 'missing_checkout' => false, 'total_hours' => 8.5, 'break_minutes' => 30,
        ]));
    }

    $this->actingAs($manager);
    $trend = Livewire::test(ManagerDashboard::class)->viewData('trend');

    expect($trend)->not->toBeEmpty()->and(count($trend))->toBeLessThanOrEqual(7);
    collect($trend)->each(fn (array $day) => expect($day)->toHaveKeys(['label', 'present', 'late', 'absent']));
    expect(collect($trend)->pluck('label')->filter(fn (string $label) => str_starts_with($label, 'Sat') || str_starts_with($label, 'Sun')))->toBeEmpty();
});

test('the Department View keeps its own title', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));

    Livewire::test(DepartmentDashboard::class)->assertForbidden();
});

test('Leave This Week shows a short preview with a View all link', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'status' => 'active']);
    $type = LeaveType::create(['name' => 'Casual / Sick Leave', 'code' => 'CSL', 'category' => 'annual', 'is_paid' => true]);

    foreach (range(1, 7) as $i) {
        $member = Employee::factory()->create([
            'user_id' => User::factory()->create(['role' => UserRole::Employee, 'name' => "Away Person {$i}"])->id,
            'status' => 'active', 'manager_id' => $manager->id, 'joining_date' => now()->subYear(),
        ]);
        LeaveRequest::create([
            'employee_id' => $member->id, 'leave_type_id' => $type->id,
            'start_date' => now()->startOfWeek()->toDateString(), 'end_date' => now()->endOfWeek()->toDateString(),
            'days' => 1, 'reason' => 'Private', 'status' => 'approved',
        ]);
    }

    $this->actingAs($manager);

    $component = Livewire::test(ManagerDashboard::class)->assertSee('Showing 5 of 7');

    expect($component->viewData('onLeaveThisWeek'))->toHaveCount(7);
});
