<?php

use App\Models\Department;
use App\Models\Employee;
use App\Models\NexflowOtSyncLog;
use App\Models\OtRequest;
use App\Models\User;
use App\Notifications\NexflowOvertimeDetectedNotification;
use App\Notifications\OTApprovedNotification;
use App\Notifications\OTRejectedNotification;
use App\Services\OvertimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

// ── Phase 1 & 2 — Model columns ───────────────────────────────────────────────

test('employee ot_tracking_source defaults to biometric', function (): void {
    $employee = Employee::factory()->create();
    expect($employee->fresh()->ot_tracking_source)->toBe('biometric');
});

test('employee ot_tracking_source can be set to nexflow', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);
    expect($employee->fresh()->ot_tracking_source)->toBe('nexflow');
});

test('department default_ot_source defaults to biometric', function (): void {
    $dept = Department::factory()->create();
    expect($dept->fresh()->default_ot_source)->toBe('biometric');
});

test('department default_ot_source can be set to nexflow', function (): void {
    $dept = Department::factory()->create(['default_ot_source' => 'nexflow']);
    expect($dept->fresh()->default_ot_source)->toBe('nexflow');
});

test('ot_request has source and nexflow_ref columns', function (): void {
    $employee = Employee::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '20:00',
        'requested_hours' => 2,
        'reason' => 'Test',
        'status' => 'pending',
        'source' => 'nexflow',
        'nexflow_ref' => 'NF-123456',
    ]);

    expect($request->source)->toBe('nexflow')
        ->and($request->nexflow_ref)->toBe('NF-123456');
});

test('nexflow_ot_sync_logs can be created', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);
    $log = NexflowOtSyncLog::create([
        'employee_id' => $employee->id,
        'sync_date' => now()->subDay()->toDateString(),
        'net_hours' => 10.5,
        'shift_threshold' => 9.0,
        'ot_hours_detected' => 1.5,
        'action' => 'created',
    ]);

    expect($log->id)->toBeGreaterThan(0)
        ->and($log->action)->toBe('created');
});

// ── Phase 3 — OvertimeService ─────────────────────────────────────────────────

test('OvertimeService::isNexflowEligible returns true for nexflow source', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);
    expect(app(OvertimeService::class)->isNexflowEligible($employee))->toBeTrue();
});

test('OvertimeService::isNexflowEligible returns true for hybrid source', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'hybrid']);
    expect(app(OvertimeService::class)->isNexflowEligible($employee))->toBeTrue();
});

test('OvertimeService::isNexflowEligible returns false for biometric source', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'biometric']);
    expect(app(OvertimeService::class)->isNexflowEligible($employee))->toBeFalse();
});

test('OvertimeService::getThresholdForEmployee falls back to 9.0 when no shift', function (): void {
    $employee = Employee::factory()->create(['shift_id' => null]);
    expect(app(OvertimeService::class)->getThresholdForEmployee($employee))->toBe(9.0);
});

test('OvertimeService::autoApprove approves pending OT request', function (): void {
    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:30',
        'requested_hours' => 1.5,
        'reason' => 'Nexflow auto',
        'status' => 'pending',
        'source' => 'nexflow',
    ]);

    $record = app(OvertimeService::class)->autoApprove($request);

    expect($request->fresh()->status)->toBe('approved')
        ->and((float) $record->ot_hours)->toEqual(1.5);
});

test('OvertimeService::autoApprove throws on non-pending request', function (): void {
    $employee = Employee::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:30',
        'requested_hours' => 1.5,
        'reason' => 'Already approved',
        'status' => 'approved',
        'source' => 'nexflow',
    ]);

    expect(fn () => app(OvertimeService::class)->autoApprove($request))
        ->toThrow(DomainException::class);
});

// ── Phase 4 — Sync command ────────────────────────────────────────────────────

test('backfill command runs without error in dry-run mode', function (): void {
    Employee::factory()->create(['ot_tracking_source' => 'biometric']);

    $this->artisan('hrms:backfill-ot-source --dry-run')
        ->assertExitCode(0);
});

test('backfill command updates employee ot_tracking_source based on department name', function (): void {
    $dept = Department::factory()->create(['name' => 'IT Development', 'default_ot_source' => 'biometric']);
    $employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'ot_tracking_source' => 'biometric',
    ]);

    $this->artisan('hrms:backfill-ot-source')->assertExitCode(0);

    expect($employee->fresh()->ot_tracking_source)->toBe('nexflow');
});

test('backfill command sets hybrid for project/PM departments', function (): void {
    $dept = Department::factory()->create(['name' => 'Project Management', 'default_ot_source' => 'biometric']);
    $employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'ot_tracking_source' => 'biometric',
    ]);

    $this->artisan('hrms:backfill-ot-source')->assertExitCode(0);

    expect($employee->fresh()->ot_tracking_source)->toBe('hybrid');
});

test('backfill command leaves HR department as biometric', function (): void {
    $dept = Department::factory()->create(['name' => 'Human Resources', 'default_ot_source' => 'biometric']);
    $employee = Employee::factory()->create([
        'department_id' => $dept->id,
        'ot_tracking_source' => 'biometric',
    ]);

    $this->artisan('hrms:backfill-ot-source')->assertExitCode(0);

    expect($employee->fresh()->ot_tracking_source)->toBe('biometric');
});

// ── Phase 7 — Notifications ───────────────────────────────────────────────────

test('NexflowOvertimeDetectedNotification toArray returns correct keys', function (): void {
    Notification::fake();

    $employee = Employee::factory()->create(['ot_tracking_source' => 'nexflow']);
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '20:00',
        'requested_hours' => 2.0,
        'reason' => 'Test',
        'status' => 'pending',
        'source' => 'nexflow',
    ]);

    $notification = new NexflowOvertimeDetectedNotification($request, $employee);
    $data = $notification->toArray($employee->user);

    expect($data)->toHaveKey('type')
        ->and($data['type'])->toBe('nexflow_ot_detected')
        ->and($data)->toHaveKey('title')
        ->and($data)->toHaveKey('body')
        ->and($data)->toHaveKey('url');
});

test('OTApprovedNotification toArray returns green color', function (): void {
    $employee = Employee::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:30',
        'requested_hours' => 1.5,
        'reason' => 'Test',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $notification = new OTApprovedNotification($request);
    $data = $notification->toArray($employee->user);

    expect($data['color'])->toBe('green')
        ->and($data['type'])->toBe('ot_approved');
});

test('OTRejectedNotification includes reviewer comment in body', function (): void {
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create();
    $request = OtRequest::create([
        'employee_id' => $employee->id,
        'work_date' => now()->subDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:30',
        'requested_hours' => 1.5,
        'reason' => 'Test',
        'status' => 'rejected',
        'source' => 'manual',
        'reviewer_id' => $reviewer->id,
        'reviewer_comment' => 'Not eligible this week',
        'reviewed_at' => now(),
    ]);

    $notification = new OTRejectedNotification($request);
    $data = $notification->toArray($employee->user);

    expect($data['body'])->toContain('Not eligible this week')
        ->and($data['color'])->toBe('red');
});

// ── Phase 8 — Reports ─────────────────────────────────────────────────────────

test('department-ot CSV report returns 200', function (): void {
    $user = User::factory()->create(['role' => 'hr_admin']);
    $this->actingAs($user);

    $response = $this->get(route('reports.department-ot', ['month' => now()->month, 'year' => now()->year]));
    $response->assertStatus(200);
});

test('nexflow-sync-log CSV report returns 200', function (): void {
    $user = User::factory()->create(['role' => 'hr_admin']);
    $this->actingAs($user);

    $response = $this->get(route('reports.nexflow-sync-log', ['month' => now()->month, 'year' => now()->year]));
    $response->assertStatus(200);
});
