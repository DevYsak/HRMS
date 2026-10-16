<?php

use App\Livewire\Attendance\AttendanceReports;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Navigation\Sidebar;

/**
 * QA-F-021 (decision 15 Oct): Attendance Reports run on their own read-only
 * permission, view_attendance_reports. Finance reads company-wide for
 * payroll, with no attendance-management power.
 */
function arpUser(string $slug, Department $department, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

beforeEach(function () {
    $this->ops = Department::factory()->create(['name' => 'ARP Operations']);
    $this->sales = Department::factory()->create(['name' => 'ARP Sales']);
    arpUser('employee', $this->sales, 'Sid Salesperson');
});

test('exactly the roles that could open Attendance Reports hold the permission; employees do not', function (string $slug, bool $opens) {
    $user = arpUser($slug, $this->ops, 'Some '.$slug);

    expect(AttendanceReports::canOpen($user))->toBe($opens)
        ->and($user->hasPermission('view_attendance_reports'))->toBe($opens);
    $this->actingAs($user)->get(route('attendance.reports'))->assertStatus($opens ? 200 : 403);
})->with([
    ['super_admin', true], ['hr_admin', true], ['director', true], ['department_head', true],
    ['manager', true], ['coordinator', true], ['finance', true], ['employee', false],
]);

test('Finance reads company-wide but gets no attendance-management power', function () {
    $finance = arpUser('finance', $this->ops, 'Fin Finance');

    $this->actingAs($finance)->get(route('attendance.reports', ['type' => 'monthly']))->assertOk()->assertSee('ARP Sales', false);
    foreach (['manage_attendance', 'approve_regularisation', 'hr_approve_regularisation', 'monitor_attendance_exceptions'] as $key) {
        expect($finance->hasPermission($key))->toBeFalse("Finance must not hold {$key}");
    }
    $this->actingAs($finance)->get(route('attendance.command-center'))->assertForbidden();
});

test('a Coordinator sees only their department; the menu follows the permission', function () {
    $coordinator = arpUser('coordinator', $this->ops, 'Cora Coordinator');

    $this->actingAs($coordinator)->get(route('attendance.reports', ['type' => 'monthly']))->assertOk()->assertDontSee('Sid Salesperson');
    $labels = collect(app(Sidebar::class)->groups($coordinator))->flatMap(fn ($g) => collect($g['items'])->pluck('label'));
    expect($labels)->toContain('Attendance Reports');
});

test('approving leave no longer implies Attendance Reports: revoking the permission closes them', function () {
    $manager = arpUser('manager', $this->ops, 'Max Manager');
    UserPermissionOverride::create(['user_id' => $manager->id, 'permission_id' => Permission::where('key', 'view_attendance_reports')->value('id'), 'effect' => 'revoke']);

    expect($manager->fresh()->canApproveLeave())->toBeTrue()
        ->and(AttendanceReports::canOpen($manager->fresh()))->toBeFalse();
    $this->actingAs($manager->fresh())->get(route('attendance.reports'))->assertForbidden();
});
