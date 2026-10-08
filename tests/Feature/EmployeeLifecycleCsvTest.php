<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

/**
 * The Employee Lifecycle export (Reports → Employee Lifecycle). Employee
 * status is an enum, so the Status column is its label, not a string edit.
 */
test('HR downloads the employee lifecycle CSV with readable statuses', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'notice_period', 'employee_id' => 'LC-001']);

    $response = $this->actingAs($hr)->get(route('reports.employee-lifecycle'));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('Employee Name')
        ->and($csv)->toContain('LC-001')
        ->and($csv)->toContain($employee->user->name)
        ->and($csv)->toContain('Notice Period')
        ->and($csv)->not->toContain('notice_period');
});

test('an employee cannot download the lifecycle CSV', function () {
    $employee = Employee::factory()->create();

    $this->actingAs($employee->user)->get(route('reports.employee-lifecycle'))->assertForbidden();
});
