<?php

/*
 * Starter-kit account teams are Super Admin only (Phase 4 decision,
 * 16 Oct 2026); the HRMS team structure is People -> Teams. The acting
 * users here are Super Admins.
 */

use App\Enums\TeamRole;
use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

test('team member role can be updated by owner', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $member->id, TeamRole::Admin->value)
        ->assertHasNoErrors();

    expect($team->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(TeamRole::Admin->value);
});

test('team member role cannot be updated by non owner', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->call('updateMember', $member->id, TeamRole::Admin->value)
        ->assertForbidden();
});

test('team member can be removed by owner', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->belongsToTeam($team))->toBeFalse();
});

test('team member cannot be removed by non owners', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertForbidden();
});

test('removed members current team is set to personal team', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $personalTeam = $member->personalTeam();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $member->update(['current_team_id' => $team->id]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.remove-member-modal', ['team' => $team])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->current_team_id)->toEqual($personalTeam->id);
});
