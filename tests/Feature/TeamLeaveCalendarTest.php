<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use Livewire\Livewire;

test('team time off shows a colour-coded team leave calendar', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager);

    $memberUser = User::factory()->create(['name' => 'CALENDARMEMBER']);
    $member = Employee::factory()->create([
        'user_id' => $memberUser->id,
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $type = LeaveType::create([
        'name' => 'Casual Leave',
        'code' => 'CL'.random_int(100, 999),
        'category' => 'annual',
        'is_paid' => true,
        'color' => '#10b981',
        'allow_paid_request' => true,
        'allow_unpaid_request' => false,
    ]);

    $day = now()->startOfMonth()->addDays(9);
    LeaveRequest::create([
        'employee_id' => $member->id,
        'leave_type_id' => $type->id,
        'start_date' => $day,
        'end_date' => $day,
        'days' => 1,
        'reason' => 'Approved leave',
        'requested_leave_status' => 'paid',
        'approved_leave_status' => 'paid',
        'status' => 'approved',
    ]);

    Livewire::test(TeamTimeOff::class)
        ->assertOk()
        ->assertSee('Team Leave Calendar')
        ->assertSee('CALENDARMEMBER')
        ->assertSee($day->format('F Y'))
        ->call('nextCalMonth')
        ->assertSee($day->copy()->addMonth()->format('F Y'));
});

test('the calendar shows public holidays', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager);

    PublicHoliday::create([
        'date' => now()->startOfMonth()->addDays(11)->toDateString(),
        'name' => 'FOUNDERSDAY',
        'is_active' => true,
    ]);

    Livewire::test(TeamTimeOff::class)
        ->assertOk()
        ->assertSee('Holiday')          // legend
        ->assertSee('FOUNDERSDAY');
});

test('the calendar surfaces comp-offs distinctly', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $this->actingAs($manager);

    $memberUser = User::factory()->create(['name' => 'COMPOFFMEMBER']);
    $member = Employee::factory()->create([
        'user_id' => $memberUser->id,
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $compOff = LeaveType::create([
        'name' => 'Comp Off',
        'code' => 'CO'.random_int(100, 999),
        'category' => 'comp_off',
        'is_paid' => true,
        'color' => '#8b5cf6',
        'allow_paid_request' => true,
        'allow_unpaid_request' => false,
    ]);

    $day = now()->startOfMonth()->addDays(12);
    LeaveRequest::create([
        'employee_id' => $member->id,
        'leave_type_id' => $compOff->id,
        'start_date' => $day,
        'end_date' => $day,
        'days' => 1,
        'reason' => 'Comp off',
        'requested_leave_status' => 'paid',
        'approved_leave_status' => 'paid',
        'status' => 'approved',
    ]);

    Livewire::test(TeamTimeOff::class)
        ->assertOk()
        ->assertSee('Comp-Off')          // legend
        ->assertSee('COMPOFFMEMBER')
        ->assertSee('comp-off');         // per-day badge title
});
