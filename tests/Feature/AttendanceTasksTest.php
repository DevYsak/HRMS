<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Employee;
use App\Models\Task;
use Livewire\Livewire;

test('an employee can add a task for today', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('newTask', 'Ship the release')
        ->set('newTaskPriority', 'high')
        ->call('addTask')
        ->assertSet('newTask', '');

    $task = Task::where('employee_id', $employee->id)->first();
    expect($task->title)->toBe('Ship the release');
    expect($task->priority)->toBe('high');
    expect($task->date->isToday())->toBeTrue();
    expect($task->completed_at)->toBeNull();
});

test('adding a blank task is rejected', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('newTask', '   ')
        ->call('addTask')
        ->assertHasErrors('newTask');

    expect(Task::count())->toBe(0);
});

test('toggling a task marks it complete and back', function () {
    $employee = Employee::factory()->create();
    $task = Task::factory()->create(['employee_id' => $employee->id, 'date' => now()]);

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('toggleTask', $task->id);

    expect($task->fresh()->completed_at)->not->toBeNull();

    $component->call('toggleTask', $task->id);
    expect($task->fresh()->completed_at)->toBeNull();
});

test('the completed-this-period counter reflects completed tasks', function () {
    $employee = Employee::factory()->create();
    Task::factory()->completed()->create(['employee_id' => $employee->id, 'date' => now()]);
    Task::factory()->completed()->create(['employee_id' => $employee->id, 'date' => now()]);
    Task::factory()->create(['employee_id' => $employee->id, 'date' => now()]); // not completed

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('tasksCompletedPeriod', 2);
});

test('an employee cannot toggle or delete another employee task', function () {
    $me = Employee::factory()->create();
    $other = Employee::factory()->create();
    $theirTask = Task::factory()->create(['employee_id' => $other->id, 'date' => now()]);

    $component = Livewire::actingAs($me->user)->test(AttendanceTracker::class);

    $component->call('toggleTask', $theirTask->id);
    expect($theirTask->fresh()->completed_at)->toBeNull();

    $component->call('deleteTask', $theirTask->id);
    expect(Task::find($theirTask->id))->not->toBeNull();
});

test('deleting a task removes it', function () {
    $employee = Employee::factory()->create();
    $task = Task::factory()->create(['employee_id' => $employee->id, 'date' => now()]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('deleteTask', $task->id);

    expect(Task::find($task->id))->toBeNull();
});
