<?php

use App\Enums\UserRole;
use App\Livewire\ManagerDashboard;
use App\Livewire\Profile\MyProfile;
use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\LeaveLedgerService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Phase 5 · one number per fact across screens. The profile's "Leave
 * balance" is the Available Leave the employee sees on My Time Off
 * (CSL + Comp Off) — never a sum of every leave type, which added
 * Maternity/Paternity entitlements (162 days).
 */
test('the profile leave balance equals My Time Off Available Leave, ignoring other leave types', function () {
    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
    $year = LeaveYear::firstOrCreate(['label' => '2026/27'], ['starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    $csl = LeaveType::firstOrCreate(['code' => ConexusLeavePolicyService::CSL_CODE], [
        'name' => 'Casual / Sick Leave', 'category' => 'annual', 'allow_paid_request' => true,
    ]);
    $maternity = LeaveType::firstOrCreate(['code' => 'P5MAT'], ['name' => 'Maternity Leave', 'category' => 'maternity']);

    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    $ledger = app(LeaveLedgerService::class);
    foreach ([[$csl, 10], [$maternity, 182]] as [$type, $days]) {
        $balance = LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year->legacyYear()],
            ['leave_year_id' => $year->id, 'allocated_days' => 0, 'used_days' => 0],
        );
        $ledger->credit($balance, LeaveLedgerEntry::TYPE_BASE, $days, Carbon::parse('2026-07-01'), 'test:p5:'.$balance->id);
        $ledger->rebuild($balance);
    }

    $available = Livewire::actingAs($user)->test(MyTimeOff::class)->viewData('overview')['available_leave'];
    $profile = Livewire::actingAs($user)->test(MyProfile::class)->viewData('kpis');

    expect($profile['leave'])->toBe(round((float) $available, 1))
        ->and($profile['leave'])->toBeLessThan(182.0);
});

test('the manager dashboard says its missing check-out count is today\'s', function () {
    $role = Role::where('slug', 'manager')->firstOrFail();
    $manager = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);

    Livewire::actingAs($manager)->test(ManagerDashboard::class)
        ->assertSee('Missing check-outs today');
});
