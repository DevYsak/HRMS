<?php

use App\Enums\UserRole;
use App\Livewire\Overtime\NexflowOtPanel;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\NexflowApiService;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** The exact NexBridge ot-details payload shape. */
function nexflowOtPayload(): array
{
    return [
        'employee' => ['nexflow_id' => 42, 'name' => 'Asha Menon', 'email' => 'asha@company.com', 'role' => 'team_member', 'job_title' => 'Backend Developer'],
        'period' => ['from' => '2026-06-01', 'to' => '2026-06-30'],
        'summary' => [
            'total_ot_records' => 2, 'approved_count' => 1, 'pending_count' => 1, 'rejected_count' => 0,
            'total_ot_hours' => 3.0, 'approved_ot_hours' => 2.0, 'pending_ot_hours' => 1.0, 'rejected_ot_hours' => 0.0,
        ],
        'ot_records' => [
            [
                'id' => 17, 'date' => '2026-06-12', 'status' => 'approved', 'reason' => 'Release deployment',
                'ot_minutes' => 120, 'ot_hours' => 2.0,
                'approvals' => ['l1' => ['status' => 'approved', 'approver' => 'Ravi Kumar'], 'l2' => ['status' => 'approved', 'approver' => 'Meena Nair']],
                'sessions' => [['task' => 'Deploy v2.3', 'started_at' => '18:30', 'stopped_at' => '20:30', 'duration_minutes' => 120]],
            ],
            [
                'id' => 21, 'date' => '2026-06-15', 'status' => 'pending', 'reason' => 'Bug fixing',
                'ot_minutes' => 60, 'ot_hours' => 1.0,
                'approvals' => ['l1' => ['status' => 'approved', 'approver' => 'Ravi Kumar'], 'l2' => ['status' => 'pending', 'approver' => null]],
                'sessions' => [['task' => 'Fix login race', 'started_at' => '18:30', 'stopped_at' => '19:30', 'duration_minutes' => 60]],
            ],
        ],
        'generated_at' => '2026-07-08T10:15:00+00:00',
    ];
}

function configureNexbridge(): void
{
    config(['nexbridge.nexflow_url' => 'https://nexflow.test', 'nexbridge.secret' => 'shhh', 'nexbridge.cache_ttl' => 0]);
}

test('the client fetches ot-details with a Bearer token and parses the payload', function () {
    configureNexbridge();
    Http::fake(['nexflow.test/*' => Http::response(nexflowOtPayload(), 200)]);

    $result = app(NexflowApiService::class)->getOtDetails('asha@company.com', '2026-06-01', '2026-06-30', 'approved');

    expect((float) $result['summary']['approved_ot_hours'])->toBe(2.0)
        ->and($result['ot_records'])->toHaveCount(2);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/employees/asha@company.com/ot-details')
        && str_contains($req->url(), 'status=approved')
        && $req->hasHeader('Authorization', 'Bearer shhh'));
});

test('importing an approved record creates an approved OT request and overtime record', function () {
    $employee = Employee::factory()->create();
    $record = nexflowOtPayload()['ot_records'][0]; // the approved one

    $outcome = app(OvertimeService::class)->importNexflowOtRecord($employee, $record);

    expect($outcome['status'])->toBe('imported')
        ->and($outcome['record'])->toBeInstanceOf(OvertimeRecord::class);

    $req = OtRequest::where('employee_id', $employee->id)->first();
    expect($req->source)->toBe('nexflow')
        ->and($req->status)->toBe('approved')
        ->and($req->nexflow_ref)->toBe('otdetails:17')
        ->and((float) $req->requested_hours)->toBe(2.0)
        ->and((float) $outcome['record']->ot_hours)->toBe(2.0);
});

test('a pending record is not payable and no records are re-imported for the same day', function () {
    $employee = Employee::factory()->create();
    $ot = app(OvertimeService::class);

    // Pending (L2 not cleared) → not payable.
    expect($ot->importNexflowOtRecord($employee, nexflowOtPayload()['ot_records'][1])['status'])->toBe('not_payable');

    // First approved import succeeds, re-seeing the same record is a no-op (unchanged).
    expect($ot->importNexflowOtRecord($employee, nexflowOtPayload()['ot_records'][0])['status'])->toBe('imported')
        ->and($ot->importNexflowOtRecord($employee, nexflowOtPayload()['ot_records'][0])['status'])->toBe('unchanged')
        ->and(OtRequest::where('employee_id', $employee->id)->count())->toBe(1);
});

test('the panel loads Nexflow OT and imports an approved record to payroll', function () {
    configureNexbridge();
    Http::fake(['nexflow.test/*' => Http::response(nexflowOtPayload(), 200)]);

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $employee = Employee::factory()->create(['user_id' => User::factory()->create(['email' => 'asha@company.com'])->id]);

    Livewire::actingAs($admin)->test(NexflowOtPanel::class)
        ->call('selectEmployee', $employee->id)
        ->assertSet('data.summary.approved_ot_hours', 2.0)
        ->assertSee('Release deployment')
        ->call('importRecord', 17);

    expect(OtRequest::where('employee_id', $employee->id)->where('status', 'approved')->count())->toBe(1)
        ->and(OvertimeRecord::where('employee_id', $employee->id)->count())->toBe(1);
});

test('a user without approve-overtime cannot open the panel', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(NexflowOtPanel::class)->assertForbidden();
});
