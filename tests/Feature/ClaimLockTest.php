<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Approvals\ClaimLockService;
use Livewire\Livewire;

/**
 * Claim-lock for multi-HR approval routing (v4 Part 2.3): the first HR to open
 * a pending request claims it; others can't act until it's released or stale.
 */
function pendingReg(Employee $employee): AttendanceRegularisation
{
    $date = today()->subDay()->toDateString();

    return AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'requested_check_in' => "$date 09:00:00",
        'requested_check_out' => "$date 18:00:00",
        'reason' => 'Forgot to punch out at the gate.',
        'status' => 'pending',
        'stage' => 'manager_review',
    ]);
}

test('the first HR to open a request claims it; a second HR cannot act on it', function () {
    $hrA = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'HR ALPHA']);
    $hrB = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));

    // HR A opens the review modal → claims the request.
    Livewire::actingAs($hrA)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->assertSet('showReviewModal', true);

    expect($reg->fresh()->claimed_by)->toBe($hrA->id);

    // HR B is told who's handling it and never gets the modal.
    Livewire::actingAs($hrB)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->assertSet('showReviewModal', false)
        ->assertSet('activeRequest', null);

    // Quick approve by HR B is blocked too — the request stays pending.
    Livewire::actingAs($hrB)->test(AllAttendance::class)
        ->call('quickApproveRegularisation', $reg->id);

    expect($reg->fresh()->status)->toBe('pending')
        ->and($reg->fresh()->claimed_by)->toBe($hrA->id);
});

test('two HRs claiming at the same moment — only one wins (atomic update)', function () {
    $hrA = User::factory()->create(['role' => UserRole::HrAdmin]);
    $hrB = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));
    $service = app(ClaimLockService::class);

    // Both act on the same unclaimed snapshot, as in a real race.
    $copyA = AttendanceRegularisation::find($reg->id);
    $copyB = AttendanceRegularisation::find($reg->id);

    $wonA = $service->claim($copyA, $hrA->id);
    $wonB = $service->claim($copyB, $hrB->id);

    expect($wonA)->toBeTrue()
        ->and($wonB)->toBeFalse()                       // conditional UPDATE matched 0 rows
        ->and($reg->fresh()->claimed_by)->toBe($hrA->id);
});

test('closing the modal without deciding releases the claim for other HRs', function () {
    $hrA = User::factory()->create(['role' => UserRole::HrAdmin]);
    $hrB = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));

    Livewire::actingAs($hrA)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->call('closeReviewModal');

    expect($reg->fresh()->claimed_by)->toBeNull();

    // Now HR B can open it normally.
    Livewire::actingAs($hrB)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->assertSet('showReviewModal', true);

    expect($reg->fresh()->claimed_by)->toBe($hrB->id);
});

test('a decision releases the claim and the request leaves the queue', function () {
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));

    Livewire::actingAs($admin)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->call('approveRegularisation');

    $fresh = $reg->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->claimed_by)->toBeNull();
});

test('stale claims auto-release after two hours via the scheduled command', function () {
    $hrA = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));

    $reg->forceFill(['claimed_by' => $hrA->id, 'claimed_at' => now()->subMinutes(121)])->save();

    $this->artisan('hrms:release-stale-claims')
        ->expectsOutputToContain('Released 1 stale claim(s).')
        ->assertSuccessful();

    expect($reg->fresh()->claimed_by)->toBeNull();
});

test('a stale claim no longer blocks another HR from taking over', function () {
    $hrA = User::factory()->create(['role' => UserRole::HrAdmin]);
    $hrB = User::factory()->create(['role' => UserRole::HrAdmin]);
    $reg = pendingReg(Employee::factory()->create(['status' => 'active']));

    $reg->forceFill(['claimed_by' => $hrA->id, 'claimed_at' => now()->subMinutes(150)])->save();

    // HR B opens it before the hourly cleanup has run — takes over the claim.
    Livewire::actingAs($hrB)->test(AllAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->assertSet('showReviewModal', true);

    expect($reg->fresh()->claimed_by)->toBe($hrB->id);
});
