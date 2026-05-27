<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;

it('generates team URL with selectedRequestId for pending leave', function () {
    $leaveType = new LeaveType(['id' => 1, 'name' => 'Casual']);
    $user = new User(['name' => 'Alice']);
    $employee = new Employee(['id' => 10]);
    $employee->setRelation('user', $user);

    $lr = new LeaveRequest(['status' => 'pending', 'days' => 2]);
    $lr->id = 123;
    $lr->setRelation('leaveType', $leaveType);
    $lr->setRelation('employee', $employee);

    $arr = (new LeaveRequestNotification($lr))->toArray(new \stdClass());

    expect($arr['url'])->toContain('time-off/team');
    expect($arr['url'])->toContain('selectedRequestId=123');
});

it('generates my URL with anchor for approved leave', function () {
    $leaveType = new LeaveType(['id' => 1, 'name' => 'Casual']);
    $user = new User(['name' => 'Bob']);
    $employee = new Employee(['id' => 20]);
    $employee->setRelation('user', $user);

    $lr = new LeaveRequest(['status' => 'approved', 'days' => 1]);
    $lr->id = 456;
    $lr->setRelation('leaveType', $leaveType);
    $lr->setRelation('employee', $employee);

    $arr = (new LeaveRequestNotification($lr))->toArray(new \stdClass());

    expect($arr['url'])->toContain('time-off/my');
    expect($arr['url'])->toContain('#request-456');
});
