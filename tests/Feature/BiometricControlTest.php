<?php

use App\Enums\UserRole;
use App\Jobs\QueueHeartbeat;
use App\Livewire\Attendance\BiometricControl;
use App\Models\AttendancePunch;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('a regular employee cannot open the biometric control center', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(BiometricControl::class)
        ->assertForbidden();
});

test('the control center renders registered machines and the capability note', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    BiometricDevice::create([
        'name' => 'ESSL MB20', 'ip_address' => '10.0.0.9', 'port' => 4370, 'timeout_seconds' => 5,
        'is_active' => true, 'last_synced_at' => now()->subMinutes(3), 'last_sync_count' => 40, 'last_ping_status' => 'online',
    ]);

    Livewire::actingAs($hr)->test(BiometricControl::class)
        ->assertOk()
        ->assertSee('Biometric Control Center')
        ->assertSee('ESSL MB20')
        ->assertSee('Online')
        ->assertSee('Verification Method Mix')
        ->assertSee('Remote restart'); // honesty note
});

test('the method mix reflects today punches and discovers device serials', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $emp = Employee::factory()->create(['status' => 'active']);
    foreach (['face', 'face', 'id_card'] as $i => $method) {
        AttendancePunch::create([
            'employee_id' => $emp->id,
            'punched_at' => today()->setTime(9, $i),
            'punch_date' => today(),
            'method' => $method,
            'source' => 'biometric',
            'device_serial' => 'AIFACE-77',
        ]);
    }

    Livewire::actingAs($hr)->test(BiometricControl::class)
        ->assertOk()
        ->assertViewHas('totalPunches', 3)
        ->assertViewHas('methods', fn ($methods) => collect($methods)->firstWhere('label', 'Face')['count'] === 2
            && collect($methods)->firstWhere('label', 'Face')['pct'] === 67)
        ->assertViewHas('discovered', fn ($d) => collect($d)->contains(fn ($x) => $x['serial'] === 'AIFACE-77' && $x['punches'] === 3))
        ->assertSee('AIFACE-77');
});

test('the offline alert shows when nothing has synced recently', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    BiometricDevice::create([
        'name' => 'Stale Device', 'ip_address' => '10.0.0.5', 'port' => 4370, 'timeout_seconds' => 5,
        'is_active' => true, 'last_synced_at' => now()->subHours(4), 'last_ping_status' => 'offline',
    ]);

    Livewire::actingAs($hr)->test(BiometricControl::class)
        ->assertViewHas('anyOnline', false)
        ->assertSee('No biometric device has synced');
});

test('the queue worker indicator shows online when the heartbeat is fresh', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Cache::put('queue:heartbeat_at', now()->timestamp, now()->addMinutes(30));

    Livewire::actingAs($hr)->test(BiometricControl::class)
        ->assertOk()
        ->assertSee('Queue Worker')
        ->assertViewHas('queueOnline', true)
        ->assertSee('Online');
});

test('the queue worker indicator shows offline when the heartbeat is stale', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Cache::put('queue:heartbeat_at', now()->subMinutes(10)->timestamp, now()->addMinutes(30));

    Livewire::actingAs($hr)->test(BiometricControl::class)
        ->assertViewHas('queueOnline', false)
        ->assertSee('queued emails are not being sent');
});

test('the heartbeat job stamps the liveness timestamp', function () {
    Cache::forget('queue:heartbeat_at');

    (new QueueHeartbeat)->handle();

    expect(Cache::get('queue:heartbeat_at'))->not->toBeNull();
});
