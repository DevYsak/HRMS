<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceSettings;
use App\Models\AttendanceScoreSetting;
use App\Models\AttendanceSetting;
use App\Models\User;
use Livewire\Livewire;

test('HR can edit and persist the attendance score weights', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(AttendanceSettings::class)
        ->assertOk()
        ->assertSee('Attendance Score Weights')
        ->set('weights.late_arrival_penalty', 6)
        ->set('weights.overtime_bonus', 3)
        ->call('saveScoreSettings')
        ->assertHasNoErrors();

    $settings = AttendanceScoreSetting::current();
    expect($settings->late_arrival_penalty)->toBe(6.0)
        ->and($settings->overtime_bonus)->toBe(3.0);
});

test('the policy thresholds are editable and validated', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(AttendanceSettings::class)
        ->set('policy.late_warning_threshold', 4)
        ->set('policy.auto_checkout_buffer_minutes', 45)
        ->call('save')
        ->assertHasNoErrors();

    expect((int) AttendanceSetting::first()->late_warning_threshold)->toBe(4)
        ->and((int) AttendanceSetting::first()->auto_checkout_buffer_minutes)->toBe(45);
});

test('a negative score weight is rejected', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(AttendanceSettings::class)
        ->set('weights.missing_punch_penalty', -5)
        ->call('saveScoreSettings')
        ->assertHasErrors('weights.missing_punch_penalty');
});

test('a regular employee cannot open attendance settings', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(AttendanceSettings::class)
        ->assertForbidden();
});
