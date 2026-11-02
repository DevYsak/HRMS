<?php

use App\Models\Role;
use App\Models\User;
use App\Services\Navigation\Sidebar;

/**
 * A login account and an employee record are different things: a Super
 * Admin, an HR or Finance user — or an employee whose profile is not linked
 * yet — may have an account with no employee record. Every page their menu
 * shows must still open, never a server error.
 */
function noProfileUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id])->fresh();
}

dataset('roles without a profile', ['super_admin', 'hr_admin', 'director', 'finance', 'manager', 'coordinator', 'employee']);

test('every menu link opens for an account with no employee record', function (string $slug) {
    $user = noProfileUser($slug);
    expect($user->employee)->toBeNull();

    $nav = app(Sidebar::class);
    $items = collect($nav->groups($user))
        ->push($nav->settings($user) ?? ['items' => []])
        ->flatMap(fn (array $g) => $g['items']);

    $failures = [];
    foreach ($items as $item) {
        $status = $this->actingAs($user)->get($item['url'])->getStatusCode();
        if ($status >= 500 || $status === 403) {
            $failures[] = "{$item['label']} ({$item['route']}) → {$status}";
        }
    }

    expect($failures)->toBe([], "{$slug} without an employee record");
})->with('roles without a profile');

test('the dashboard and help open for an account with no employee record', function (string $slug) {
    $user = noProfileUser($slug);

    expect($this->actingAs($user)->get('/')->getStatusCode())->toBeLessThan(500)
        ->and($this->actingAs($user)->get(route('help.getting-started'))->getStatusCode())->toBeLessThan(500);
})->with('roles without a profile');
