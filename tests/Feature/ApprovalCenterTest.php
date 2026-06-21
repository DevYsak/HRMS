<?php

use App\Enums\UserRole;
use App\Livewire\ApprovalCenter;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\User;
use Livewire\Livewire;

it('shows pending requests to an approver', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $emp = Employee::factory()->create();
    OtRequest::factory()->create(['employee_id' => $emp->id, 'status' => 'pending']);

    Livewire::test(ApprovalCenter::class)
        ->assertSee('Approval Command Center')
        ->assertSee($emp->user->name)
        ->assertSee('Overtime');
});

it('hides the queue from a non-approver employee', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $other = Employee::factory()->create();
    OtRequest::factory()->create(['employee_id' => $other->id, 'status' => 'pending']);

    Livewire::test(ApprovalCenter::class)
        ->assertSee('All caught up')
        ->assertDontSee($other->user->name);
});

it('filters the queue by type', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $emp = Employee::factory()->create();
    OtRequest::factory()->create(['employee_id' => $emp->id, 'status' => 'pending']);

    Livewire::test(ApprovalCenter::class)
        ->call('setFilter', 'leave')
        ->assertDontSee($emp->user->name)   // OT hidden under Leave filter
        ->call('setFilter', 'ot')
        ->assertSee($emp->user->name);
});
