<?php

use App\Enums\UserRole;
use App\Models\User;

test('a super admin has every permission even without a linked db role', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]); // no role_id

    expect($admin->hasPermission('approve_overtime'))->toBeTrue();
    expect($admin->hasPermission('manage_review_cycles'))->toBeTrue();
    expect($admin->hasPermission('run_payroll'))->toBeTrue();
    expect($admin->canManageSettings())->toBeTrue();
    expect($admin->canApproveOt())->toBeTrue();
});

test('a plain employee still has no permissions', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    expect($employee->hasPermission('approve_overtime'))->toBeFalse();
    expect($employee->canManageSettings())->toBeFalse();
});
