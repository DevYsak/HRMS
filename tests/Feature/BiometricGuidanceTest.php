<?php

use App\Enums\PunchMethod;
use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Face = Biometric IN and ID Card = Biometric OUT, shown wherever a punch
 * method is — as display guidance that never overrides a direction the punch
 * was actually resolved to — plus the scrolling punch guide on attendance
 * screens.
 */
beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-14 12:00:00')));

test('each method carries its biometric guidance', function () {
    expect(PunchMethod::Face->guidance())->toBe('Biometric IN')
        ->and(PunchMethod::IdCard->guidance())->toBe('Biometric OUT')
        ->and(PunchMethod::Face->labelWithGuidance())->toBe('Face · Biometric IN')
        ->and(PunchMethod::IdCard->labelWithGuidance())->toBe('ID Card · Biometric OUT');
});

test('a resolved direction wins over the method default', function () {
    expect(PunchMethod::Face->guidance('out'))->toBe('Biometric OUT')
        ->and(PunchMethod::IdCard->guidance('in'))->toBe('Biometric IN')
        ->and(PunchMethod::Face->guidance('sideways'))->toBe('Biometric IN');
});

test('a genuine face OUT is labelled Biometric OUT on the timeline, not IN', function () {
    $employee = Employee::factory()->create();
    foreach ([['11:33:20', 'in'], ['11:33:21', 'out']] as [$time, $direction]) {
        AttendancePunch::factory()->create([
            'employee_id' => $employee->id, 'punched_at' => '2026-10-14 '.$time, 'punch_date' => '2026-10-14',
            'method' => 'face', 'direction' => $direction, 'source' => 'biometric',
        ]);
    }

    $timeline = app(PunchTimeline::class)->process(AttendancePunch::orderBy('punched_at')->get(), Carbon::parse('2026-10-14'));

    expect(collect($timeline['nodes'])->pluck('guidance')->all())->toBe(['Biometric IN', 'Biometric OUT'])
        ->and(collect($timeline['raw_events'])->pluck('guidance')->all())->toBe(['Biometric IN', 'Biometric OUT']);
});

test('the punch guide scrolls across My Attendance and All Attendance', function () {
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($user)->test(AttendanceTracker::class)
        ->assertSee('Face = Biometric IN')
        ->assertSee('ID Card = Biometric OUT')
        ->assertSee('Regularised punches are manually corrected attendance entries')
        ->assertSeeHtml('data-biometric-notice');

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertSeeHtml('data-biometric-notice');
});
