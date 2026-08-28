<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved company working pattern
    |--------------------------------------------------------------------------
    |
    | Used only when an import supplies no working pattern of its own. It is a
    | stated company default, not an assumption: the entitlement engine flags a
    | pattern it had to guess, and a guessed pattern produces a guessed
    | entitlement.
    |
    | Set to null to stop provisioning instead, so a new hire with no pattern is
    | reported rather than given a number nobody verified.
    |
    | The current company pattern is Monday to Friday, five days.
    |
    */

    'default_working_days_per_week' => env('LEAVE_DEFAULT_WORKING_DAYS_PER_WEEK', 5),

    'default_working_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],

];
