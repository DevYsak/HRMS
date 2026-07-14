<?php

use App\Enums\UserRole;
use App\Livewire\Overtime\ManageOtRequests;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function otDetailsPayload(int $id, string $status, string $date = '2026-06-09', float $hours = 2.0): array
{
    return [
        'ot_records' => [[
            'id' => $id, 'date' => $date, 'status' => $status, 'reason' => 'Release work',
            'ot_hours' => $hours, 'ot_minutes' => (int) ($hours * 60),
            'sessions' => [['started_at' => '18:00', 'stopped_at' => '20:00', 'duration_minutes' => 120]],
        ]],
    ];
}

function configureNexbridgeSync(): void
{
    config(['nexbridge.nexflow_url' => 'https://nexflow.test', 'nexbridge.secret' => 's', 'nexbridge.cache_ttl' => 0]);
}

test('the batch sync imports approved OT and records rejected OT across employees', function () {
    configureNexbridgeSync();

    $approvedEmp = Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'approved@x.com'])->id]);
    $rejectedEmp = Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'rejected@x.com'])->id]);

    Http::fake([
        '*approved@x.com/ot-details*' => Http::response(otDetailsPayload(17, 'approved'), 200),
        '*rejected@x.com/ot-details*' => Http::response(otDetailsPayload(21, 'rejected'), 200),
        '*' => Http::response(['ot_records' => []], 200),
    ]);

    $result = app(OvertimeService::class)->syncNexflowOtDetails('2026-06-01', '2026-06-30');

    expect($result['imported'])->toBe(1)
        ->and($result['rejected'])->toBe(1);

    // Approved → approved OT + payroll record.
    $approved = OtRequest::where('employee_id', $approvedEmp->id)->first();
    expect($approved->status)->toBe('approved')
        ->and($approved->source)->toBe('nexflow')
        ->and(OvertimeRecord::where('ot_request_id', $approved->id)->exists())->toBeTrue();

    // Rejected → rejected OT, visible, no payroll record.
    $rejected = OtRequest::where('employee_id', $rejectedEmp->id)->first();
    expect($rejected->status)->toBe('rejected')
        ->and($rejected->source)->toBe('nexflow')
        ->and(OvertimeRecord::where('ot_request_id', $rejected->id)->exists())->toBeFalse();
});

test('the sync is idempotent — a second run imports nothing new', function () {
    configureNexbridgeSync();
    Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'approved@x.com'])->id]);
    Http::fake([
        '*approved@x.com/ot-details*' => Http::response(otDetailsPayload(17, 'approved'), 200),
        '*' => Http::response(['ot_records' => []], 200),
    ]);

    $svc = app(OvertimeService::class);
    expect($svc->syncNexflowOtDetails('2026-06-01', '2026-06-30')['imported'])->toBe(1);
    $second = $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');

    expect($second['imported'])->toBe(0)
        ->and($second['updated'])->toBe(0)
        ->and(OtRequest::count())->toBe(1);   // unchanged — no duplicate
});

test('a status change in Nexflow is reconciled, voids the pay, and is recorded in history', function () {
    configureNexbridgeSync();
    Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'flip@x.com'])->id]);
    $svc = app(OvertimeService::class);

    // Nexflow re-decides the same OT across three syncs: approved → rejected → approved.
    Http::fake([
        '*flip@x.com/ot-details*' => Http::sequence()
            ->push(otDetailsPayload(17, 'approved'), 200)
            ->push(otDetailsPayload(17, 'rejected'), 200)
            ->push(otDetailsPayload(17, 'approved'), 200),
        '*' => Http::response(['ot_records' => []], 200),
    ]);

    // 1) approved → imported + payroll record.
    $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');
    $ot = OtRequest::where('source', 'nexflow')->first();
    expect($ot->status)->toBe('approved')
        ->and(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeTrue();

    // 2) rejected → HRMS status updates and the unpaid pay is voided.
    $result = $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');
    expect($result['updated'])->toBe(1)
        ->and($ot->fresh()->status)->toBe('rejected')
        ->and(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeFalse()   // pay voided
        ->and(OtRequest::count())->toBe(1);                                              // no duplicate

    // The status change is recorded in history (audit log).
    expect(AuditLog::where('action', 'nexflow_ot_status_changed')->where('auditable_id', $ot->id)->exists())->toBeTrue();

    // 3) re-approved → pay is re-materialised.
    $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');
    expect($ot->fresh()->status)->toBe('approved')
        ->and(OvertimeRecord::where('ot_request_id', $ot->id)->exists())->toBeTrue();
});

test('the OT view shows the Nexflow status-change history timeline', function () {
    configureNexbridgeSync();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'hist@x.com'])->id]);
    $svc = app(OvertimeService::class);

    Http::fake([
        '*hist@x.com/ot-details*' => Http::sequence()
            ->push(otDetailsPayload(17, 'approved'), 200)
            ->push(otDetailsPayload(17, 'rejected'), 200),
        '*' => Http::response(['ot_records' => []], 200),
    ]);

    $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');   // approved  → synced entry
    $svc->syncNexflowOtDetails('2026-06-01', '2026-06-30');   // rejected  → status_changed entry
    $ot = OtRequest::where('source', 'nexflow')->first();

    Livewire::actingAs($admin)->test(ManageOtRequests::class)
        ->call('openView', $ot->id)
        ->assertSet('viewHistory', fn ($h) => count($h) === 2)   // synced + one change
        ->assertSee('Nexflow status history')
        ->assertSee('Approved → Rejected');
});

test('the Sync from Nexflow button on Manage OT pulls approved OT', function () {
    configureNexbridgeSync();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    Employee::factory()->create(['status' => 'active', 'user_id' => User::factory()->create(['email' => 'approved@x.com'])->id]);
    Http::fake([
        '*approved@x.com/ot-details*' => Http::response(otDetailsPayload(17, 'approved', now()->startOfMonth()->toDateString()), 200),
        '*' => Http::response(['ot_records' => []], 200),
    ]);

    Livewire::actingAs($admin)->test(ManageOtRequests::class)
        ->call('syncFromNexflow');

    expect(OtRequest::where('source', 'nexflow')->where('status', 'approved')->count())->toBe(1);
});
