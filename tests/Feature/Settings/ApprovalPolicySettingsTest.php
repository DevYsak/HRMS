<?php

use App\Livewire\Settings\ApprovalPolicySettings;
use App\Models\PayrollApprovalPolicy;
use App\Models\User;
use Livewire\Livewire;

test('finance and director cannot access the approval policy settings screen', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $director = User::factory()->create(['role' => 'director']);

    Livewire::actingAs($finance)->test(ApprovalPolicySettings::class)->assertForbidden();
    Livewire::actingAs($director)->test(ApprovalPolicySettings::class)->assertForbidden();
});

test('hr admin can create, reorder, disable and delete policy steps', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'HR Review')
        ->set('approver_type', 'hr_admin')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'Finance Sign-off')
        ->set('approver_type', 'finance')
        ->call('save')
        ->assertHasNoErrors();

    $steps = PayrollApprovalPolicy::orderBy('level')->get();
    expect($steps)->toHaveCount(2)
        ->and($steps[0]->label)->toBe('HR Review')
        ->and($steps[0]->level)->toBe(1)
        ->and($steps[1]->level)->toBe(2);

    $financeStep = $steps[1];
    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('moveUp', $financeStep->id);

    expect(PayrollApprovalPolicy::find($financeStep->id)->level)->toBe(1)
        ->and(PayrollApprovalPolicy::find($steps[0]->id)->level)->toBe(2);

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('toggleActive', $financeStep->id);
    expect(PayrollApprovalPolicy::find($financeStep->id)->is_active)->toBeFalse();

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('delete', $financeStep->id);
    expect(PayrollApprovalPolicy::find($financeStep->id))->toBeNull();
});

test('deleting or reordering steps renumbers levels contiguously with no duplicates', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);
    $a = PayrollApprovalPolicy::create(['level' => 1, 'label' => 'A', 'approver_type' => 'hr_admin', 'is_active' => true]);
    $b = PayrollApprovalPolicy::create(['level' => 2, 'label' => 'B', 'approver_type' => 'finance', 'is_active' => true]);
    $c = PayrollApprovalPolicy::create(['level' => 3, 'label' => 'C', 'approver_type' => 'director', 'is_active' => true]);

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('delete', $b->id);

    $levels = PayrollApprovalPolicy::orderBy('level')->pluck('level')->all();
    expect($levels)->toBe([1, 2]);

    $remaining = PayrollApprovalPolicy::orderBy('level')->pluck('id')->all();
    expect($remaining)->toBe([$a->id, $c->id]);
});

test('a specific_user approver requires an existing user, validated on save', function () {
    $hrAdmin = User::factory()->create(['role' => 'hr_admin']);

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'CEO Sign-off')
        ->set('approver_type', 'specific_user')
        ->set('specific_user_id', null)
        ->call('save')
        ->assertHasErrors(['specific_user_id']);

    $ceo = User::factory()->create();

    Livewire::actingAs($hrAdmin)->test(ApprovalPolicySettings::class)
        ->call('openCreate')
        ->set('label', 'CEO Sign-off')
        ->set('approver_type', 'specific_user')
        ->set('specific_user_id', $ceo->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(PayrollApprovalPolicy::where('label', 'CEO Sign-off')->first()->specific_user_id)->toBe($ceo->id);
});
