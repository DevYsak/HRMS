<?php

use App\Models\Role;
use App\Models\User;

/**
 * Phase 4 · Settings mobile: master-data tables sat inside overflow-hidden
 * cards with no horizontal scroll, so on a phone the right-hand columns
 * (and their edit buttons) were cut off. Each now scrolls inside its card.
 */
test('settings master-data tables scroll horizontally on small screens', function (string $route) {
    $role = Role::where('slug', 'super_admin')->firstOrFail();
    $admin = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);

    $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('data-table-scroll', false);
})->with([
    'settings.departments', 'settings.employment-types', 'settings.job-titles', 'settings.work-modes',
    'settings.salary-cycles', 'settings.onboarding-templates',
]);
