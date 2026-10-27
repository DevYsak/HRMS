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

    /*
    |--------------------------------------------------------------------------
    | Monthly Casual / Sick Leave accrual
    |--------------------------------------------------------------------------
    |
    | OFF by default (D7, 8 Oct 2026). When off, CSL is never credited
    | automatically: balances come from the HR register reconciliation and HR
    | adjustments. Switched on, `leave:conexus-csl-accrual` credits 1 day per
    | completed month — idempotent (one ledger key per employee and month),
    | audited on the ledger, and never re-crediting months the reconciled
    | register base already stands for. Preview runs are always allowed.
    |
    */

    'csl_monthly_accrual_enabled' => (bool) env('LEAVE_CSL_MONTHLY_ACCRUAL_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Leave V2 policy engine
    |--------------------------------------------------------------------------
    |
    | Categories and subtypes, the annual paid cap, effective-dated policy
    | assignments, policy-driven paid/LWP treatment and approval paths.
    | Off locally and in production until the migration preview is
    | approved; the Leave V2 tests switch it on. Never enable it on live
    | automatically.
    |
    */

    'policy_engine_v2' => (bool) env('LEAVE_POLICY_ENGINE_V2', false),

    /*
    |--------------------------------------------------------------------------
    | Leave V2 Earned Leave monthly accrual
    |--------------------------------------------------------------------------
    |
    | `leave:accrue-earned` credits 1 day of Earned Leave per completed month
    | (12 a year), idempotent per employee and month, catching up missed
    | months. OFF by default: switch it on only after its preview has been
    | reviewed on live. Previews and manual --apply runs are always allowed.
    |
    */

    'earned_accrual_enabled' => (bool) env('LEAVE_EARNED_ACCRUAL_ENABLED', false),

];
