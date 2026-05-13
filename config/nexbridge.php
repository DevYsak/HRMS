<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NexBridge — Nexcore → Nexflow Secure API Bridge
    |--------------------------------------------------------------------------
    |
    | NEXBRIDGE_NEXFLOW_URL  : Full base URL of Nexflow (no trailing slash)
    |                          e.g. https://nexflow.yourcompany.com
    |
    | NEXBRIDGE_SECRET       : Shared Bearer token (same value set in Nexflow)
    |
    */

    'nexflow_url' => env('NEXBRIDGE_NEXFLOW_URL', ''),

    'secret' => env('NEXBRIDGE_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Cache TTL (seconds)
    |--------------------------------------------------------------------------
    | How long Nexcore caches Nexflow responses locally.
    | 900 = 15 minutes. Set to 0 to disable caching (not recommended).
    */

    'cache_ttl' => (int) env('NEXBRIDGE_CACHE_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    | Seconds to wait for Nexflow to respond before giving up.
    */

    'timeout' => (int) env('NEXBRIDGE_TIMEOUT', 10),

];
