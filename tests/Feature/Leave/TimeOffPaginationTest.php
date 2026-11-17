<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Livewire\Livewire;

/*
 * All Time Off and Team Leave list 10 requests a page. Their Previous / Next
 * were plain links; built during a Livewire update the link was relative
 * ("time-off/employees?page=2"), so the browser opened
 * /time-off/time-off/employees?page=2 — a 404. They now change page through
 * Livewire, in place, keeping the filters.
 */

beforeEach(function () {
    $type = LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANP', 'category' => 'annual', 'is_paid' => true,
        'allow_paid_request' => true, 'color' => '#F97316']);

    // Twelve requests this month, newest last, so page 1 holds 12…03 and page 2 holds 02 and 01.
    foreach (range(1, 12) as $n) {
        $employee = Employee::factory()->create([
            'user_id' => User::factory()->create(['name' => sprintf('PAGED-%02d', $n)])->id, 'status' => 'active',
        ]);
        LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => now()->startOfMonth(), 'end_date' => now()->startOfMonth(), 'days' => 1,
            'reason' => 'Family event', 'status' => 'approved', 'requested_leave_status' => 'paid', 'approved_leave_status' => 'paid',
        ])->forceFill(['created_at' => now()->subMinutes(20 - $n)])->save();
    }
});

test('HR\'s All Time Off pages through requests in place, with no link to a missing page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    Livewire::test(AllTimeOff::class)
        ->assertSee('PAGED-12')->assertDontSee('PAGED-01')
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->assertDontSeeHtml('?page=')
        ->set('search', 'PAGED')
        ->assertDontSeeHtml('?page=')
        ->call('nextPage', 'page')
        ->assertSee('PAGED-01')->assertDontSee('PAGED-12')
        ->assertSeeHtml('wire:click="previousPage(\'page\')"')
        ->assertDontSeeHtml('?page=');
});

test('a manager\'s Team Leave history pages through requests in place, with no link to a missing page', function () {
    $this->actingAs(lineManager());

    // The team calendar also names everyone on leave this month, so follow
    // the history table by its page number rather than by name.
    Livewire::test(TeamTimeOff::class)
        ->assertSeeHtml('wire:click="nextPage(\'page\')"')
        ->assertDontSeeHtml('?page=')
        ->call('nextPage', 'page')
        ->assertSet('paginators.page', 2)
        ->assertSeeHtml('wire:click="previousPage(\'page\')"')
        ->assertDontSeeHtml('wire:click="nextPage(\'page\')"')
        ->assertDontSeeHtml('?page=');
});
