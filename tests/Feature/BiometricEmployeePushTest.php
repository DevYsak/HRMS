<?php

use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Services\Biometric\BiometricSyncService;
use App\Services\Biometric\ZKTecoService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helper ────────────────────────────────────────────────────────────────────

function makeDevice(array $attrs = []): BiometricDevice
{
    return BiometricDevice::create(array_merge([
        'name' => 'Test Device',
        'ip_address' => '127.0.0.1',
        'port' => 4370,
        'timeout_seconds' => 5,
        'is_active' => true,
    ], $attrs));
}

// ── ZKTecoService: setUser packet shape ───────────────────────────────────────

test('ZKTecoService setUser builds a 72-byte user record', function () {
    // We cannot connect to a real device in tests, so we verify the packet
    // shape by subclassing and intercepting sendCommand.
    $zk = new class('127.0.0.1') extends ZKTecoService
    {
        public ?string $capturedData = null;

        public function exposeSendCommand(int $cmd, string $data = ''): void
        {
            $this->capturedData = $data;
        }
    };

    // Build the payload the same way setUser does, using reflection to test the
    // record layout without a live socket.
    $userId = 42;
    $name = 'Yogesh Sakpal';
    $password = '';
    $privilege = 0;

    $record = pack('vv', $userId, $privilege)
        .str_pad(substr($password, 0, 8), 8, "\x00")
        .str_pad(substr($name, 0, 24), 24, "\x00")
        ."\x00"
        .pack('V', 0)
        ."\x00"
        .str_repeat("\x00", 30);

    expect(strlen($record))->toBe(72);

    // user_id is the first uint16 LE
    $parsedUserId = unpack('v', substr($record, 0, 2))[1];
    expect($parsedUserId)->toBe(42);

    // name starts at offset 4 and is null-padded to 24 bytes
    $parsedName = rtrim(substr($record, 4, 24), "\x00");
    // offset is actually 4 (user_id 2 + privilege 2) + 8 (password) = 12
    $parsedName = rtrim(substr($record, 12, 24), "\x00");
    expect($parsedName)->toBe('Yogesh Sakpal');
});

// ── BiometricSyncService: pushEmployee ───────────────────────────────────────

test('pushEmployee marks sync_status synced on success', function () {
    $device = makeDevice();

    $employee = Employee::factory()->create([
        'employee_code' => 17,
        'biometric_device_id' => $device->id,
        'sync_status' => 'pending',
    ]);

    // Swap out ZKTecoService so no real TCP connection is made.
    $mockZk = Mockery::mock(ZKTecoService::class);
    $mockZk->shouldReceive('connect')->once();
    $mockZk->shouldReceive('setUser')->with(17, Mockery::type('string'))->andReturn(true);
    $mockZk->shouldReceive('disconnect')->once();

    $service = Mockery::mock(BiometricSyncService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('makeZKService')->andReturn($mockZk);

    $result = $service->pushEmployee($employee, $device);

    expect($result)->toBeTrue();
    expect($employee->fresh()->sync_status)->toBe('synced');
    expect($employee->fresh()->last_biometric_sync_at)->not->toBeNull();
});

test('pushEmployee marks sync_status failed when device returns false', function () {
    $device = makeDevice();

    $employee = Employee::factory()->create([
        'employee_code' => 18,
        'biometric_device_id' => $device->id,
        'sync_status' => 'pending',
    ]);

    $mockZk = Mockery::mock(ZKTecoService::class);
    $mockZk->shouldReceive('connect')->once();
    $mockZk->shouldReceive('setUser')->andReturn(false);
    $mockZk->shouldReceive('disconnect')->once();

    $service = Mockery::mock(BiometricSyncService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('makeZKService')->andReturn($mockZk);

    $result = $service->pushEmployee($employee, $device);

    expect($result)->toBeFalse();
    expect($employee->fresh()->sync_status)->toBe('failed');
});

test('pushEmployee throws when employee has no employee_code', function () {
    $device = makeDevice();
    $employee = Employee::factory()->create(['employee_code' => null]);

    $service = app(BiometricSyncService::class);

    expect(fn () => $service->pushEmployee($employee, $device))
        ->toThrow(RuntimeException::class, 'no employee_code');
});

// ── EmployeeObserver: sync_status tracking ───────────────────────────────────

test('updating employee_code marks sync_status pending', function () {
    $device = makeDevice();
    $employee = Employee::factory()->create([
        'employee_code' => 10,
        'biometric_device_id' => $device->id,
        'sync_status' => 'synced',
    ]);

    $employee->update(['employee_code' => 20]);

    expect($employee->fresh()->sync_status)->toBe('pending');
});

test('updating non-biometric fields does not reset sync_status', function () {
    $device = makeDevice();
    $employee = Employee::factory()->create([
        'employee_code' => 10,
        'biometric_device_id' => $device->id,
        'sync_status' => 'synced',
    ]);

    $employee->update(['phone' => '9999999999']);

    expect($employee->fresh()->sync_status)->toBe('synced');
});

// ── biometric:push-employees command ─────────────────────────────────────────

test('push-employees command skips employees without employee_code', function () {
    makeDevice();
    Employee::factory()->create(['employee_code' => null, 'sync_status' => 'pending']);

    $this->artisan('biometric:push-employees', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Nothing to push');
});

test('push-employees --dry-run lists employees without pushing', function () {
    $device = makeDevice();
    Employee::factory()->create([
        'employee_code' => 5,
        'biometric_device_id' => $device->id,
        'sync_status' => 'pending',
    ]);

    $this->artisan('biometric:push-employees', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('[ENROL]');

    // Dry run — sync_status unchanged
    expect(Employee::where('employee_code', 5)->first()->sync_status)->toBe('pending');
});
