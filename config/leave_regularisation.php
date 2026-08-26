<?php

return [

    /*
    |--------------------------------------------------------------------------
    | How far back a regularisation may reach
    |--------------------------------------------------------------------------
    |
    | Correcting a missed day is legitimate; reopening last quarter's payroll
    | because somebody remembered an absence is not. The window is the line
    | between the two, and it belongs here rather than inside a controller
    | where changing it means a deploy.
    |
    | 0 removes the limit.
    |
    */

    'window_days' => (int) env('LEAVE_REGULARISATION_WINDOW_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Future dates
    |--------------------------------------------------------------------------
    |
    | A regularisation corrects what already happened. Booking leave ahead is
    | what the normal leave request is for, and allowing it here would give
    | employees a second route to leave that skips the leave approval rules.
    |
    */

    'allow_future_dates' => (bool) env('LEAVE_REGULARISATION_ALLOW_FUTURE', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum days in one request
    |--------------------------------------------------------------------------
    |
    | A long unexplained absence is a conversation, not a form.
    |
    */

    'max_days_per_request' => (float) env('LEAVE_REGULARISATION_MAX_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Supporting document
    |--------------------------------------------------------------------------
    |
    | When true, a request cannot be submitted without an attachment. Off by
    | default: most corrections are administrative, and demanding evidence for
    | all of them pushes people back to asking HR to fix it by hand, which is
    | the untraceable path this feature replaces.
    |
    */

    'require_document' => (bool) env('LEAVE_REGULARISATION_REQUIRE_DOCUMENT', false),

    /*
    |--------------------------------------------------------------------------
    | Who may submit
    |--------------------------------------------------------------------------
    |
    | 'employee_self_service' lets an employee raise one for their own absence.
    | Whoever submits, approval still runs the full chain — submitting is not
    | approving.
    |
    */

    'employee_self_service' => (bool) env('LEAVE_REGULARISATION_SELF_SERVICE', true),

    /*
    |--------------------------------------------------------------------------
    | Balance
    |--------------------------------------------------------------------------
    |
    | Whether the leave type's balance must cover the request. Turning this off
    | permits a negative balance, which some contractual leave types genuinely
    | allow; it does not stop the deduction being recorded.
    |
    */

    'require_sufficient_balance' => (bool) env('LEAVE_REGULARISATION_REQUIRE_BALANCE', true),

];
