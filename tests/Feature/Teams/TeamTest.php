<?php

/*
 * Starter-kit account teams are Super Admin only (Phase 4 decision,
 * 16 Oct 2026); the HRMS team structure is People -> Teams. The acting
 * users here are Super Admins.
 */

use App\Enums\TeamRole;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Navigation\Sidebar;
use Livewire\Livewire;

test('teams index page can be rendered', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('teams can be created', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($user);

    Livewire::test('pages::teams.index')
        ->set('name', 'Test Team')
        ->call('createTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'name' => 'Test Team',
        'is_personal' => false,
    ]);
});

test('team slug uses next available suffix', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this->actingAs($user);

    Livewire::test('pages::teams.index')
        ->set('name', 'Acme')
        ->call('createTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('team edit page can be rendered', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.edit', $team));

    $response->assertOk();
});

test('teams can be updated by owners', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Updated Name')
        ->call('updateTeam')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.edit', ['team' => $team])
        ->set('teamName', 'Updated Name')
        ->call('updateTeam')
        ->assertForbidden();
});

test('teams can be deleted by owners', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);
});

test('team deletion requires name confirmation', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', 'Wrong Name')
        ->call('deleteTeam')
        ->assertHasErrors(['deleteName']);

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function () {
    $user = User::factory()->create(['name' => 'Mike', 'role' => UserRole::SuperAdmin]);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $zuluTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $zuluTeam])
        ->set('deleteName', $zuluTeam->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting current team falls back to personal team when alphabetically first', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create(['name' => 'Zulu Team']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $team->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('deleting non current team leaves current team unchanged', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $personalTeam = $user->personalTeam();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $user->update(['current_team_id' => $personalTeam->id]);

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('deleting team switches other affected users to their personal team', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $owner->update(['current_team_id' => $team->id]);
    $member->update(['current_team_id' => $team->id]);

    $this->actingAs($owner);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertHasNoErrors();

    expect($member->fresh()->current_team_id)->toEqual($member->personalTeam()->id);
});

test('personal teams cannot be deleted', function () {
    $user = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $personalTeam = $user->personalTeam();

    $this->actingAs($user);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $personalTeam])
        ->set('deleteName', $personalTeam->name)
        ->call('deleteTeam')
        ->assertForbidden();

    $this->assertDatabaseHas('teams', [
        'id' => $personalTeam->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function () {
    $owner = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $member = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $this->actingAs($member);

    Livewire::test('pages::teams.delete-team-modal', ['team' => $team])
        ->set('deleteName', $team->name)
        ->call('deleteTeam')
        ->assertForbidden();
});

test('guests cannot access teams', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});
test('starter-kit account teams are Super Admin only: everyone else is refused and never sees the menu entry', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)->get(route('teams.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('profile.edit'))->assertOk()->assertDontSee('Account Teams (legacy)');

    Livewire::actingAs($employee)->test('pages::teams.index')->set('name', 'Sneaky')->call('createTeam')->assertForbidden();
    $this->assertDatabaseMissing('teams', ['name' => 'Sneaky']);
});

test('the Coordinators page is in the Settings menu only for people who assign coordinators', function () {
    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id]);
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    $labels = fn (User $u) => collect(app(Sidebar::class)->settings($u)['items'] ?? [])->pluck('label');

    expect($hr->hasPermission('assign_coordinators'))->toBeTrue()
        ->and($labels($hr))->toContain('Coordinators')
        ->and($labels($employee))->not->toContain('Coordinators');
});
