<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\Help\EmployeeGuide;
use App\Services\Help\GettingStarted;
use Illuminate\Support\Arr;

/*
 * The guides tell employees which button to press, so every name they give
 * must be one the page really shows. After the My Leave and My Attendance
 * redesigns they still said "Apply Leave" and "Regularize" — buttons that no
 * longer existed.
 */

beforeEach(function () {
    $this->employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $this->employee->id, 'status' => 'active']);
    $this->withoutVite()->actingAs($this->employee);
});

test('the guides name the leave and regularisation buttons the pages show', function () {
    $guide = app(EmployeeGuide::class);
    $written = implode(' ', array_filter(Arr::flatten([
        $guide->sections($this->employee),
        $guide->faqs(),
        app(GettingStarted::class)->steps($this->employee),
    ]), 'is_string'));

    expect($written)
        ->toContain('click Request Leave')
        ->toContain('click Request Regularisation')
        ->not->toContain('Apply Leave')
        ->not->toContain('Regularize');

    $this->get(route('time-off.my'))->assertOk()->assertSee('Request Leave')->assertDontSee('Apply Leave');
    $this->get(route('attendance.my'))->assertOk()->assertSee('Request Regularisation');
});

test('the dashboard offers the same Request Leave action as My Time Off', function () {
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Apply Leave');
});
