<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PipRecord;
use App\Models\User;
use App\Services\Biometric\EngineAttendanceSyncService;
use App\Services\EmployeeDashboardService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Follow-ups from the adversarial review of the compliance pass: each pins a
 * case where a fix would otherwise have broken a legitimate workflow.
 */
function followUser(UserRole $role, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $user->fresh();
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
});

test('a manager can open the PIP document of their own report, but not of someone outside their team', function () {
    $manager = followUser(UserRole::Manager);
    $report = followUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $outsider = followUser(UserRole::Manager);
    Storage::disk('local')->put('documents/pip/1/plan.pdf', '%PDF');
    $document = Document::create([
        'title' => 'Action plan', 'file_path' => 'documents/pip/1/plan.pdf', 'file_name' => 'plan.pdf', 'mime_type' => 'application/pdf',
        'category' => 'pip', 'visibility' => 'restricted', 'employee_id' => $report->employee->id, 'uploaded_by' => $manager->id,
        'documentable_type' => PipRecord::class, 'documentable_id' => 1,
    ]);
    $url = URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]);

    $this->actingAs($manager)->get($url)->assertOk();
    $this->actingAs($outsider)->get($url)->assertForbidden();
});

test('a policy addressed to one employee is not counted as "to acknowledge" for everyone else', function () {
    $hr = followUser(UserRole::HrAdmin);
    $addressee = followUser(UserRole::Employee);
    $colleague = followUser(UserRole::Employee);
    Document::create([
        'title' => 'Individual policy', 'file_path' => 'documents/x.pdf', 'file_name' => 'x.pdf', 'mime_type' => 'application/pdf',
        'category' => 'policy', 'visibility' => 'restricted', 'employee_id' => $addressee->employee->id,
        'requires_acknowledgement' => true, 'uploaded_by' => $hr->id,
    ]);

    $service = app(EmployeeDashboardService::class);

    expect($service->build($colleague)['documents']['pending_acknowledgement'])->toBe(0)
        ->and($service->build($addressee)['documents']['pending_acknowledgement'])->toBe(1);
});

test('a request decided by someone else while the modal was open is not silently reversed', function () {
    $manager = followUser(UserRole::Manager);
    $report = followUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $report->employee->id, 'work_date' => now()->subDay()->toDateString(),
        'requested_check_in' => '10:30', 'requested_check_out' => '19:30', 'reason' => 'Forgot to punch', 'status' => 'pending',
    ]);

    $component = Livewire::actingAs($manager)->test(AllAttendance::class)->call('openReviewModal', $reg->id);

    // Meanwhile another approver finalises it.
    $reg->update(['status' => 'approved']);

    $component->set('reviewComment', 'Not valid any more')->call('rejectRegularisation')->assertHasNoErrors();

    expect($reg->fresh()->status)->toBe('approved');
});

test('a half-day regularisation still receives the real device punches, keeping its approved status', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    $employee = followUser(UserRole::Employee, ['employee_code' => 7101]);
    $date = now()->toDateString();
    $attendance = Attendance::create([
        'employee_id' => $employee->employee->id, 'date' => $date, 'check_in' => "{$date} 10:30:00",
        'status' => 'half_day', 'is_regularized' => true,
    ]);
    Http::fake(['*/api/dashboard*' => Http::response(['table' => [[
        'emp_id' => 7101, 'first_punch' => '10:32:00', 'last_punch' => '14:30:00', 'working_min' => 238, 'break_min' => 0,
    ]]], 200)]);

    app(EngineAttendanceSyncService::class)->syncDate($date);

    $fresh = $attendance->fresh();
    expect($fresh->status)->toBe('half_day')
        ->and($fresh->check_out?->format('H:i'))->toBe('14:30')
        ->and($fresh->is_regularized)->toBeTrue();
});
