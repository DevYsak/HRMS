<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\RegularisationReviewedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function regEmployee(): array
{
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    return [$user, $employee];
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test('submitting a regularisation request sends a pending notification to the employee', function () {
    Notification::fake();

    [$user, $employee] = regEmployee();

    $this->actingAs($user);

    Livewire::test(AttendanceTracker::class)
        ->set('regDate', now()->subDay()->toDateString())
        ->set('regCheckIn', '09:00')
        ->set('regCheckOut', '18:00')
        ->set('regReason', 'Forgot to clock in yesterday')
        ->call('submitRegularisation');

    Notification::assertSentTo(
        $user,
        RegularisationReviewedNotification::class,
        function (RegularisationReviewedNotification $notification) use ($user) {
            $payload = $notification->toArray($user);

            return $payload['title'] === 'Regularisation Request Submitted'
                && $payload['color'] === 'amber'
                && $payload['url'] === '/attendance/my';
        }
    );
});

test('employee notification payload contains the correct date', function () {
    Notification::fake();

    [$user, $employee] = regEmployee();

    $this->actingAs($user);

    $date = now()->subDays(2)->toDateString();

    Livewire::test(AttendanceTracker::class)
        ->set('regDate', $date)
        ->set('regCheckIn', '09:30')
        ->set('regCheckOut', '18:30')
        ->set('regReason', 'System issue prevented clock-in')
        ->call('submitRegularisation');

    $regularisation = AttendanceRegularisation::where('employee_id', $employee->id)->first();
    expect($regularisation)->not->toBeNull();

    $payload = (new RegularisationReviewedNotification($regularisation))->toArray($user);

    expect($payload['body'])->toContain(Carbon::parse($date)->format('d M Y'));
});

test('regularisation notification pending case links to attendance my page', function () {
    $regularisation = new AttendanceRegularisation([
        'work_date' => '2026-05-20',
        'status' => 'pending',
    ]);

    $notification = new RegularisationReviewedNotification($regularisation);
    $payload = $notification->toArray(new User);

    expect($payload['url'])->toBe('/attendance/my')
        ->and($payload['color'])->toBe('amber')
        ->and($payload['icon'])->toBe('clipboard-document-check');
});
