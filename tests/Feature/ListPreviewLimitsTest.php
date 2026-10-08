<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\FinanceDashboard;
use App\Livewire\Notifications;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Long activity / approval lists show a short preview with "View all" (a
 * right-side drawer or the full page); counts stay the true totals and no
 * preview renders an unbounded list.
 */
function lplPending(Employee $employee, int $count, string $status = 'pending'): void
{
    foreach (range(1, $count) as $i) {
        $date = today()->subDays($i)->toDateString();
        AttendanceRegularisation::create([
            'employee_id' => $employee->id, 'work_date' => $date,
            'requested_check_in' => "{$date} 09:00:00", 'requested_check_out' => "{$date} 18:00:00",
            'reason' => 'Missed punch '.$i, 'status' => $status, 'stage' => 'hr_review',
            'reviewed_at' => $status === 'pending' ? null : now()->subMinutes($i),
        ]);
    }
}

test('All Attendance previews five pending regularisations and lists the rest in a drawer', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    lplPending(Employee::factory()->create(['status' => 'active']), 7);

    $html = Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertSee('7 pending regularisation requests')
        ->assertSee('Showing the oldest 5 of 7')
        ->assertSee('View all 7 requests')
        ->assertSeeHtml('data-pending-regularisations-all')
        ->html();

    // 5 in the banner + 7 in the drawer.
    expect(substr_count($html, 'Missed punch'))->toBe(12);
});

test('All Attendance shows no View all for a short queue', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    lplPending(Employee::factory()->create(['status' => 'active']), 3);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertSee('3 pending regularisation requests')
        ->assertDontSee('View all 3 requests')
        ->assertDontSeeHtml('data-pending-regularisations-all');
});

test('Team Attendance previews five pending requests with View all', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    Employee::factory()->create(['user_id' => $manager->id, 'manager_id' => null]);
    lplPending(Employee::factory()->create(['manager_id' => $manager->id, 'status' => 'active']), 6);

    Livewire::actingAs($manager)->test(TeamAttendance::class)
        ->assertSee('Showing the oldest 5 of 6')
        ->assertSee('View all 6 requests')
        ->assertSeeHtml('data-team-pending-all');
});

test('the Command Center activity feed previews six decisions', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    lplPending(Employee::factory()->create(['status' => 'active']), 8, 'approved');

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->assertSee('View all 8 decisions');
});

test('the notification bell shows the latest five', function () {
    $user = User::factory()->create();
    foreach (range(1, 7) as $i) {
        DatabaseNotification::create([
            'id' => (string) Str::uuid(), 'type' => 'App\Notifications\Test', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => ['title' => 'Bell item '.$i, 'message' => 'm'],
            'created_at' => now()->subMinutes(10 - $i),
        ]);
    }

    Livewire::actingAs($user)->test(Notifications::class)
        ->assertViewHas('notifications', fn ($n) => $n->count() === Notifications::PREVIEW_LIMIT)
        ->assertSee('View all notifications');
});

test('the Finance dashboard counts every run awaiting sign-off but lists five', function () {
    $finance = User::factory()->create(['role' => UserRole::Finance]);
    Employee::factory()->create(['user_id' => $finance->id, 'status' => 'active']);
    foreach (range(1, 7) as $i) {
        Payroll::create(['month' => now()->subMonths($i)->format('F'), 'year' => now()->subMonths($i)->year, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'total_payout' => 1000 * $i]);
    }

    Livewire::actingAs($finance)->test(FinanceDashboard::class)
        ->assertViewHas('awaitingFinanceCount', 7)
        ->assertViewHas('awaitingFinance', fn ($runs) => $runs->count() === 5)
        ->assertSee('Oldest 5 of 7');
});
