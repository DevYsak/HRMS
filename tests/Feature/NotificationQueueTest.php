<?php

use App\Notifications\AttendanceRegularisationNotification;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\OtRequestNotification;
use App\Notifications\RegularisationReviewedNotification;
use App\Notifications\WfhRequestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

test('approval-workflow notifications are queued so mail never blocks the web request', function () {
    // Each of these fans out to multiple recipients; queuing keeps the SMTP
    // sends off the request thread (and off a single overloaded connection).
    foreach ([
        AttendanceRegularisationNotification::class,
        RegularisationReviewedNotification::class,
        LeaveRequestNotification::class,
        WfhRequestNotification::class,
        OtRequestNotification::class,
    ] as $class) {
        expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue("{$class} must implement ShouldQueue");
    }
});
