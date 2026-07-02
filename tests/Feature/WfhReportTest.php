<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WfhReport;
use Livewire\Livewire;

test('a WFH employee can submit a daily report', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('wfhForm.work_summary', 'Shipped the billing module and reviewed 4 PRs.')
        ->set('wfhForm.tomorrow_plan', 'Start the reporting epic.')
        ->call('saveWfhReport');

    $report = WfhReport::where('employee_id', $employee->id)->first();
    expect($report)->not->toBeNull();
    expect($report->work_summary)->toContain('billing module');
    expect($report->date->isToday())->toBeTrue();
});

test('the work summary is required', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('wfhForm.work_summary', '')
        ->call('saveWfhReport')
        ->assertHasErrors('wfhForm.work_summary');

    expect(WfhReport::count())->toBe(0);
});

test('submitting twice updates the same day report rather than duplicating', function () {
    $employee = Employee::factory()->create();

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('wfhForm.work_summary', 'First version of the day.')
        ->call('saveWfhReport');

    $component->set('wfhForm.work_summary', 'Updated end-of-day summary.')
        ->call('saveWfhReport');

    expect(WfhReport::where('employee_id', $employee->id)->count())->toBe(1);
    expect(WfhReport::where('employee_id', $employee->id)->first()->work_summary)->toBe('Updated end-of-day summary.');
});

test('the WFH report card only renders on a WFH or hybrid day', function () {
    $employee = Employee::factory()->create();

    // Office day → no card
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => now()->setTime(9, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertDontSee('WFH Daily Report');

    // Switch today to WFH → card appears
    Attendance::where('employee_id', $employee->id)->update(['work_mode' => 'wfh']);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('WFH Daily Report');
});
