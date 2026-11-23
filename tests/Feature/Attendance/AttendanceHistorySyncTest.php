<?php

use App\Enums\UserRole;
use App\Jobs\RunAttendanceHistorySync;
use App\Livewire\Attendance\HistorySyncPanel;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Biometric\AttendanceHistorySync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Biometric Control → Attendance history sync: replays the engine's logs
 * through the live day sync (EngineAttendanceSyncService::syncDate), one day
 * at a time in the background, or only reads them ("Check logs") to show the
 * days with punches and the device PINs with no HRMS employee.
 */

/** The engine's log: PIN 17 (Yogesh, in HRMS) on 2 and 4 Nov, PIN 99 (not in HRMS) on 2 Nov. */
function fakeEngineLog(): void
{
    config(['services.biometric_app.url' => 'http://engine.test']);

    Http::fake(function (Request $request) {
        $row = fn (int $pin, string $name) => ['emp_id' => $pin, 'name' => $name, 'first_punch' => '10:25:00', 'last_punch' => '19:40:00',
            'punch_count' => 2, 'inside' => false, 'punches' => [['time' => '10:25:00', 'verify' => 15], ['time' => '19:40:00', 'verify' => 4]]];

        return Http::response(['table' => match ($request['date']) {
            '2026-11-02' => [$row(17, 'Yogesh Sapkal'), $row(99, 'Night Guard')],
            '2026-11-04' => [$row(17, 'Yogesh Sapkal')],
            default => [],
        }]);
    });
}

beforeEach(function () {
    Cache::forget(AttendanceHistorySync::CACHE_KEY);
    config(['queue.default' => 'database']);
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));

    $shift = ShiftSetting::create(['name' => 'IT Shift', 'start_time' => '10:30', 'end_time' => '19:30', 'grace_minutes' => 6, 'standard_hours' => 9]);
    $this->yogesh = Employee::factory()->create(['employee_code' => 17, 'shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
    $this->actingAs($this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Shivani HR']));
    fakeEngineLog();
});

test('checking the logs reports the days with punches and the PINs with no employee, and writes nothing', function () {
    $result = app(AttendanceHistorySync::class)->runNow('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_CHECK);

    expect($result)->toMatchArray(['days_done' => 5, 'days_with_punches' => 2, 'first_punch_date' => '2026-11-02', 'last_punch_date' => '2026-11-04', 'failed' => []])
        ->and(array_keys($result['matched']))->toBe([17])
        ->and($result['unmatched'])->toBe([99 => ['name' => 'Night Guard', 'days' => 1, 'first' => '2026-11-02', 'last' => '2026-11-02']])
        ->and(Attendance::count())->toBe(0)
        ->and(AttendancePunch::count())->toBe(0);
});

test('a history sync runs the live day sync over the range in the background', function () {
    Queue::fake();

    expect(app(AttendanceHistorySync::class)->start('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_SYNC, $this->hr))->toBeNull();
    Queue::assertPushed(RunAttendanceHistorySync::class);

    $runId = app(AttendanceHistorySync::class)->status()['run_id'];
    expect(app(AttendanceHistorySync::class)->processChunk($runId))->toBeFalse(); // 5 days fit in one batch

    $status = app(AttendanceHistorySync::class)->status();
    expect($status)->toMatchArray(['status' => 'finished', 'days_done' => 5, 'synced' => 2, 'skipped' => 1])
        ->and(Attendance::where('employee_id', $this->yogesh->id)->orderBy('date')->get()->map(fn ($a) => $a->date->toDateString())->all())
        ->toBe(['2026-11-02', '2026-11-04'])
        ->and(AttendancePunch::where('employee_id', $this->yogesh->id)->count())->toBe(4)
        ->and(AuditLog::where('action', 'attendance.history_sync_started')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'attendance.history_sync_finished')->exists())->toBeTrue();

    // Running the same range again changes nothing.
    app(AttendanceHistorySync::class)->runNow('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_SYNC);
    expect(Attendance::count())->toBe(2)->and(AttendancePunch::count())->toBe(4);
});

test('a long range is handled a batch at a time, and a stop takes effect between days', function () {
    Queue::fake();
    $history = app(AttendanceHistorySync::class);

    $history->start('2026-08-01', '2026-11-05', AttendanceHistorySync::MODE_CHECK, $this->hr);
    $runId = $history->status()['run_id'];

    expect($history->processChunk($runId))->toBeTrue()
        ->and($history->status()['days_done'])->toBe(AttendanceHistorySync::CHUNK_DAYS);

    $history->cancel($this->hr);
    expect($history->processChunk($runId))->toBeFalse()
        ->and($history->status()['status'])->toBe('cancelled')
        ->and($history->status()['days_done'])->toBe(AttendanceHistorySync::CHUNK_DAYS);
});

test('a run does not start without a background worker, twice at once, or with a bad range', function () {
    Queue::fake();
    $history = app(AttendanceHistorySync::class);

    config(['queue.default' => 'sync']);
    expect($history->start('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_SYNC, $this->hr))
        ->toContain('php artisan attendance:sync-engine --from=2026-11-01 --to=2026-11-05');

    config(['queue.default' => 'database']);
    expect($history->start('2026-11-05', '2026-11-01', AttendanceHistorySync::MODE_SYNC, $this->hr))->toBe('From must be on or before To.')
        ->and($history->start('2026-11-01', '2026-11-30', AttendanceHistorySync::MODE_SYNC, $this->hr))->toBe('To cannot be in the future.')
        ->and($history->start('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_SYNC, $this->hr))->toBeNull()
        ->and($history->start('2026-11-01', '2026-11-05', AttendanceHistorySync::MODE_SYNC, $this->hr))->toContain('already in progress');

    Queue::assertPushed(RunAttendanceHistorySync::class, 1);
});

test('HR starts and follows a run from Biometric Control; others cannot', function () {
    Queue::fake();

    $this->withoutVite()->get(route('attendance.biometric-control'))->assertOk()->assertSee('Attendance history sync');

    Livewire::test(HistorySyncPanel::class)
        ->set('from', '2026-11-01')->set('to', '2026-11-05')
        ->call('check')
        ->assertHasNoErrors()
        ->assertSee('Log check (read only)')
        ->assertSee('Waiting for the background worker');
    Queue::assertPushed(RunAttendanceHistorySync::class);

    app(AttendanceHistorySync::class)->processChunk(app(AttendanceHistorySync::class)->status()['run_id']);

    Livewire::test(HistorySyncPanel::class)
        ->assertSee('Finished')
        ->assertSee('Night Guard')
        ->assertSee('no HRMS employee with that Employee Code');

    $this->actingAs(User::factory()->create(['role' => UserRole::Employee]));
    Livewire::test(HistorySyncPanel::class)->assertForbidden();
});

test('the command line check lists the PINs to fix and the sync command to run', function () {
    $this->artisan('attendance:history-coverage', ['--from' => '2026-11-01', '--to' => '2026-11-05'])
        ->expectsOutputToContain('READ ONLY')
        ->expectsOutputToContain('Night Guard')
        ->expectsOutputToContain('php artisan attendance:sync-engine --from=2026-11-02 --to=2026-11-05')
        ->assertSuccessful();

    expect(Attendance::count())->toBe(0);
});
