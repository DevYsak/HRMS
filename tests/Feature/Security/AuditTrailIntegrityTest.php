<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\DataPurgeService;

/**
 * Phase 1 safety — the audit trail is append-only, records the real actor
 * (even through impersonation), never stores secrets, and cannot be purged
 * from the UI.
 */
function auditEntry(): AuditLog
{
    $user = User::factory()->create();

    return app(AuditService::class)->event('EMPLOYEE_UPDATED', AuditService::EMPLOYEE, $user, ['name' => 'Old'], ['name' => 'New']);
}

test('audit entries cannot be edited', function () {
    auditEntry()->update(['action' => 'tampered']);
})->throws(LogicException::class, 'immutable');

test('audit entries cannot be deleted', function () {
    auditEntry()->delete();
})->throws(LogicException::class, 'cannot be deleted');

test('categorised events carry module, category, event and a request id', function () {
    $entry = auditEntry();

    expect($entry->category)->toBe('employee')
        ->and($entry->module)->toBe('employee')
        ->and($entry->event)->toBe('EMPLOYEE_UPDATED')
        ->and($entry->request_id)->not->toBeEmpty();
});

test('secrets are redacted and sensitive identifiers masked before storage', function () {
    $user = User::factory()->create();

    $entry = AuditLog::record($user, 'updated', null, [
        'password' => 'hunter2',
        'two_factor_secret' => 'abc',
        'account_number' => '50100012345678',
        'pan_number' => 'ABCDE1234F',
        'name' => 'Visible',
    ]);

    expect($entry->new_values['password'])->toBe('[redacted]')
        ->and($entry->new_values['two_factor_secret'])->toBe('[redacted]')
        ->and($entry->new_values['account_number'])->toBe('**********5678')
        ->and($entry->new_values['pan_number'])->toBe('******234F')
        ->and($entry->new_values['name'])->toBe('Visible');
});

test('impersonation is recorded against the real Super Admin, and their later actions keep the impersonator', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
    $target = User::factory()->create(['role' => UserRole::Employee, 'email_verified_at' => now()]);

    $this->actingAs($admin)->post(route('impersonate.start', $target))->assertRedirect(route('dashboard'));

    $started = AuditLog::where('event', 'IMPERSONATION_STARTED')->first();
    expect($started->user_id)->toBe($admin->id)
        ->and($started->category)->toBe('security');

    // Anything written while impersonating names both people.
    $entry = AuditLog::record($target, 'updated', null, ['name' => 'x']);
    expect($entry->user_id)->toBe($target->id)
        ->and($entry->impersonator_id)->toBe($admin->id);
});

test('impersonation can no longer be started by a plain link (GET)', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'email_verified_at' => now()]);
    $target = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)->get("/impersonate/{$target->id}")->assertStatus(405);
});

test('audit logs are not offered as a purgeable data domain', function () {
    expect(DataPurgeService::DOMAINS)->not->toHaveKey('audit');
});

// ── Route gates ──────────────────────────────────────────────────────────────

test('role dashboards are closed to a plain employee', function (string $route) {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    $this->actingAs($employee)->get(route($route))->assertForbidden();
})->with(['dashboard.finance', 'dashboard.executive', 'dashboard.director', 'dashboard.hr-admin', 'dashboard.manager']);

test('company-wide CSV exports are refused to a line manager but open to HR', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $this->actingAs($manager)->get(route('reports.attendance-summary'))->assertForbidden();
    $this->actingAs($manager)->get(route('reports.ot-records'))->assertForbidden();
    $this->actingAs($hr)->get(route('reports.attendance-summary'))->assertOk();
});
