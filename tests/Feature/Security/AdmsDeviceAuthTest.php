<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The ADMS push endpoint (/iclock) used to accept attendance from anyone who
 * knew a device serial number. A push is now accepted only from a registered,
 * active device, from an address it is allowed to push from, with its token
 * when one is issued — and replayed or out-of-window punches never change
 * attendance.
 *
 * Clock: Wednesday 14 October 2026, 18:00. Device registered at 10.0.0.21.
 */
const ADMS_SN = 'TBDD253900118';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 18:00:00'));
    RateLimiter::clear('adms:10.0.0.21');
    RateLimiter::clear('adms:203.0.113.99');

    $shift = ShiftSetting::create([
        'name' => 'Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $this->employee = Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => $shift->id,
        'biometric_id' => '501', 'employee_code' => 501, 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
    $this->device = BiometricDevice::create([
        'name' => 'AIFACE', 'serial_number' => ADMS_SN, 'ip_address' => '10.0.0.21', 'port' => 4370,
        'timeout_seconds' => 5, 'is_active' => true,
    ]);
});

/** POST an ATTLOG upload as the device would. */
function admsUpload(string $ip, string $data, array $query = [], array $headers = [])
{
    $uri = '/iclock/cdata?'.http_build_query(['SN' => ADMS_SN, 'table' => 'ATTLOG', 'Stamp' => 1] + $query);
    $server = ['REMOTE_ADDR' => $ip];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', $uri, ['data' => $data], [], [], $server);
}

function admsLine(string $at, int $state = 0, int $verify = 15): string
{
    return "501\t{$at}\t{$state}\t{$verify}\t0\t0\n";
}

test('a push from the device registered address is accepted and reaches attendance', function () {
    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00').admsLine('2026-10-14 17:58:00', 1, 4))
        ->assertOk()->assertSee('OK: 2');

    expect(BiometricLog::count())->toBe(2)
        ->and(Attendance::where('employee_id', $this->employee->id)->whereDate('date', '2026-10-14')->exists())->toBeTrue();
});

test('knowing the serial is not enough: a push from another address is refused and writes nothing', function () {
    admsUpload('203.0.113.99', admsLine('2026-10-14 09:02:00'))->assertForbidden();

    expect(BiometricLog::count())->toBe(0)
        ->and(Attendance::count())->toBe(0);
});

test('X-Forwarded-For cannot impersonate the device address', function () {
    admsUpload('203.0.113.99', admsLine('2026-10-14 09:02:00'), headers: ['X-Forwarded-For' => '10.0.0.21'])
        ->assertForbidden();

    expect(BiometricLog::count())->toBe(0);
});

test('an unknown serial and an inactive device are refused on every endpoint', function () {
    $this->call('GET', '/iclock/cdata?SN=UNKNOWN1&options=all', [], [], [], ['REMOTE_ADDR' => '10.0.0.21'])->assertForbidden();
    $this->call('GET', '/iclock/getrequest?SN=UNKNOWN1', [], [], [], ['REMOTE_ADDR' => '10.0.0.21'])->assertForbidden();

    $this->device->update(['is_active' => false]);
    $this->call('GET', '/iclock/cdata?SN='.ADMS_SN.'&options=all', [], [], [], ['REMOTE_ADDR' => '10.0.0.21'])->assertForbidden();
    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'))->assertForbidden();

    expect(BiometricLog::count())->toBe(0);
});

test('an allow-list of addresses and CIDR ranges replaces the registered address', function () {
    $this->device->forceFill(['adms_allowed_ips' => '198.51.100.7, 192.168.10.0/24'])->save();

    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'))->assertForbidden();
    admsUpload('192.168.10.44', admsLine('2026-10-14 09:02:00'))->assertOk();

    expect(BiometricLog::count())->toBe(1);
});

test('when a token is issued it is required, by query or header, and a wrong one is refused', function () {
    $this->device->forceFill(['adms_token_hash' => hash('sha256', 'right-token-value')])->save();

    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'))->assertForbidden();
    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'), ['token' => 'wrong'])->assertForbidden();
    expect(BiometricLog::count())->toBe(0);

    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'), ['token' => 'right-token-value'])->assertOk();
    admsUpload('10.0.0.21', admsLine('2026-10-14 17:58:00', 1, 4), headers: ['X-Device-Token' => 'right-token-value'])->assertOk();

    expect(BiometricLog::count())->toBe(2);
});

test('a replayed upload changes nothing', function () {
    $payload = admsLine('2026-10-14 09:02:00').admsLine('2026-10-14 17:58:00', 1, 4);

    admsUpload('10.0.0.21', $payload)->assertOk();
    $attendance = Attendance::where('employee_id', $this->employee->id)->first()->only(['check_in', 'check_out', 'total_hours']);

    admsUpload('10.0.0.21', $payload)->assertOk();

    expect(BiometricLog::count())->toBe(2)
        ->and(Attendance::count())->toBe(1)
        ->and(Attendance::where('employee_id', $this->employee->id)->first()->only(['check_in', 'check_out', 'total_hours']))->toEqual($attendance);
});

test('punches stamped in the future or older than the allowed age are never written', function () {
    config(['biometric.adms.max_record_age_days' => 45, 'biometric.adms.future_tolerance_minutes' => 10]);

    admsUpload('10.0.0.21',
        admsLine('2026-10-15 09:00:00')          // tomorrow
        .admsLine('2026-08-01 09:00:00')         // 74 days old
        .admsLine('not-a-date')
        .admsLine('2026-10-14 09:02:00'))        // genuine
        ->assertOk()->assertSee('OK: 4');        // acknowledged so the device stops resending

    expect(BiometricLog::pluck('punched_at')->map(fn ($t) => Carbon::parse($t)->format('Y-m-d H:i'))->all())
        ->toBe(['2026-10-14 09:02']);
});

test('a rejection is logged with the reason but never the token or the punch data', function () {
    Log::spy();
    $this->device->forceFill(['adms_token_hash' => hash('sha256', 'right-token-value')])->save();

    admsUpload('10.0.0.21', admsLine('2026-10-14 09:02:00'), ['token' => 'leaked-guess'])->assertForbidden();

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
        $flat = json_encode($context);

        return $message === 'ADMS request rejected'
            && $context['reason'] === 'invalid_token'
            && $context['ip'] === '10.0.0.21'
            && ! str_contains($flat, 'leaked-guess')
            && ! str_contains($flat, '09:02');
    })->once();
});

test('pushes are rate limited per address', function () {
    config(['biometric.adms.requests_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        $this->call('GET', '/iclock/getrequest?SN='.ADMS_SN, [], [], [], ['REMOTE_ADDR' => '10.0.0.21'])->assertOk();
    }

    $this->call('GET', '/iclock/getrequest?SN='.ADMS_SN, [], [], [], ['REMOTE_ADDR' => '10.0.0.21'])->assertStatus(429);
});

test('the configure command sets the allow-list and issues a token stored only as a hash', function () {
    $this->artisan('biometric:adms-device', ['serial' => ADMS_SN, '--allow-ip' => ['198.51.100.7', '10.1.0.0/16'], '--issue-token' => true])
        ->assertSuccessful();

    $device = $this->device->fresh();

    expect($device->adms_allowed_ips)->toBe('198.51.100.7,10.1.0.0/16')
        ->and($device->adms_token_hash)->toHaveLength(64)
        ->and($device->toArray())->not->toHaveKey('adms_token_hash');

    $this->artisan('biometric:adms-device', ['serial' => ADMS_SN, '--allow-ip' => ['not-an-ip']])->assertFailed();
    $this->artisan('biometric:adms-device', ['serial' => 'NOPE'])->assertFailed();
});
