<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password history
    |--------------------------------------------------------------------------
    |
    | How many previous passwords a user may not reuse. The application had a
    | password_histories table but no policy attached to it, so no number was
    | inherited from the business — 5 is stated here as an explicit, changeable
    | default rather than buried in code as a magic number.
    |
    | Set to 0 to record history without preventing reuse.
    |
    */

    'password_history_limit' => (int) env('PASSWORD_HISTORY_LIMIT', 5),

    /*
    |--------------------------------------------------------------------------
    | Sign out other sessions on password change
    |--------------------------------------------------------------------------
    |
    | When a password changes, other browsers already signed in as that user
    | keep working unless their sessions are invalidated. That is the whole
    | point of changing a password you believe is compromised.
    |
    */

    'logout_other_devices_on_password_change' => (bool) env('LOGOUT_OTHER_DEVICES_ON_PASSWORD_CHANGE', true),

    /*
    |--------------------------------------------------------------------------
    | Login invitation expiry
    |--------------------------------------------------------------------------
    |
    | How many hours an invitation link stays redeemable. Short enough that a
    | forwarded or forgotten invitation stops being a way in; long enough to
    | survive a weekend. HR can always resend, which revokes the old link.
    |
    */

    'invitation_expiry_hours' => (int) env('INVITATION_EXPIRY_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Invitation resend throttle
    |--------------------------------------------------------------------------
    |
    | Maximum resends per employee per hour. Each resend mails out a fresh
    | password, so an unthrottled button is a way to flood somebody's inbox
    | with live credentials.
    |
    */

    'invitation_resend_per_hour' => (int) env('INVITATION_RESEND_PER_HOUR', 5),

];
