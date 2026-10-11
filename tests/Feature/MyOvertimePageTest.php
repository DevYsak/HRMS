<?php

use App\Livewire\Overtime\MyOtRequests;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Services\OvertimeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function overtimeEmployee(): User
{
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id]);

    return $user->fresh();
}

test('the summary cards use the canonical OT summary', function () {
    $user = overtimeEmployee();
    $employeeId = $user->employee->id;

    OtRequest::factory()->create(['employee_id' => $employeeId, 'status' => 'pending']);
    OtRequest::factory()->count(2)->create(['employee_id' => $employeeId, 'status' => 'rejected']);
    $approved = OtRequest::factory()->create([
        'employee_id' => $employeeId, 'status' => 'approved', 'work_date' => now()->startOfMonth()->toDateString(), 'requested_hours' => 3,
    ]);
    OvertimeRecord::create([
        'employee_id' => $employeeId, 'ot_request_id' => $approved->id, 'work_date' => $approved->work_date,
        'total_hours_worked' => 11.5, 'standard_hours' => 9, 'ot_hours' => 2.5, 'rate_per_hour' => 100, 'ot_amount' => 250,
    ]);

    $summary = app(OvertimeService::class)->summaryFor($user->employee);
    expect($summary)->toMatchArray(['pending' => 1, 'approved' => 1, 'rejected' => 2, 'approved_hours_month' => 2.5]);

    Livewire::actingAs($user)->test(MyOtRequests::class)
        ->assertSee('My Overtime')
        ->assertSee('Track requests and approved overtime.')
        ->assertSee('Approved OT Hours This Month')
        ->assertSeeHtml('data-ot-card="pending"')
        // Actual approved hours come from the OvertimeRecord, not the 3h requested.
        ->assertSee('2.5h')
        ->assertSee('Actual approved hours');
});

test('an employee with no requests sees the empty state', function () {
    Livewire::actingAs(overtimeEmployee())->test(MyOtRequests::class)
        ->assertSee('You have not requested any overtime yet.');
});

test('details and timeline only open for the employee\'s own request', function () {
    $user = overtimeEmployee();
    $mine = OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending', 'reason' => 'Month-end close']);
    $theirs = OtRequest::factory()->create(['employee_id' => overtimeEmployee()->employee->id, 'status' => 'pending']);

    Livewire::actingAs($user)->test(MyOtRequests::class)
        ->call('showDetails', $mine->id)
        ->assertSee('Timeline')
        ->assertSee('Waiting for your manager')
        ->assertSeeHtml('data-app-modal');

    expect(fn () => Livewire::actingAs($user)->test(MyOtRequests::class)->call('showDetails', $theirs->id))
        ->toThrow(ModelNotFoundException::class);
});

test('the end time error is specific and the request modal marks required fields', function () {
    $user = overtimeEmployee();

    Livewire::actingAs($user)->test(MyOtRequests::class)
        ->call('openModal')
        ->assertSeeHtml('data-required-marker')
        ->assertSeeHtml('aria-required="true"')
        ->set('work_date', now()->toDateString())
        ->set('start_time', '20:00')
        ->set('end_time', '18:00')
        ->set('reason', 'Release deployment')
        ->call('submit')
        ->assertHasErrors(['end_time' => 'after'])
        ->assertSee('The end time must be after the start time.');
});

test('cancelling a pending request keeps the canonical counts in step', function () {
    $user = overtimeEmployee();
    $request = OtRequest::factory()->create(['employee_id' => $user->employee->id, 'status' => 'pending']);

    Livewire::actingAs($user)->test(MyOtRequests::class)->call('cancel', $request->id);

    expect($request->fresh()->status)->toBe('cancelled')
        ->and(app(OvertimeService::class)->summaryFor($user->employee)['pending'])->toBe(0);
});
