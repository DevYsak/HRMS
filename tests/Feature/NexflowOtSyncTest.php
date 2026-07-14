<?php

use App\Enums\UserRole;
use App\Livewire\Overtime\ManageOtRequests;
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
        ->and($second['skipped'])->toBe(1)
        ->and(OtRequest::count())->toBe(1);
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
