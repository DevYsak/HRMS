<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Device Seed
    |--------------------------------------------------------------------------
    | Used only when seeding / first-time setup via artisan command.
    | Production device configuration lives in the biometric_devices table.
    */
    'default_device' => [
        'name' => env('BIOMETRIC_DEVICE_NAME', 'AIFACE-MAGNUM'),
        'ip_address' => env('BIOMETRIC_IP', '192.168.0.121'),
        'port' => (int) env('BIOMETRIC_PORT', 4370),
        'timeout_seconds' => (int) env('BIOMETRIC_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Sync API
    |--------------------------------------------------------------------------
    | Shared secret the external Python attendance engine must send (header
    | X-Api-Key, or Authorization: Bearer <key>) to call the /api/v1/* sync
    | endpoints. This is the INBOUND key (Python → HRMS) and is distinct from
    | services.biometric_app.key, which is the OUTBOUND key (HRMS → Python).
    */
    'api' => [
        'key' => env('BIOMETRIC_SYNC_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    */
    'sync' => [
        // How many biometric log rows to process per artisan run
        'batch_size' => (int) env('BIOMETRIC_BATCH_SIZE', 500),

        // Minutes between two punch events of the same type before they are
        // treated as separate entries rather than duplicates.
        'duplicate_window_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Punch Type Map
    |--------------------------------------------------------------------------
    | Maps the raw state byte reported by the device to a punch_type label.
    | ZKTeco standard: 0=check_in, 1=check_out, 4=ot_in, 5=ot_out
    */
    'punch_types' => [
        0 => 'check_in',
        1 => 'check_out',
        4 => 'ot_in',
        5 => 'ot_out',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verify Type Labels
    |--------------------------------------------------------------------------
    */
    'verify_types' => [
        1 => 'Fingerprint',
        2 => 'PIN',
        3 => 'Card',
        4 => 'Face',
        15 => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verify Code → Tracked Punch Method
    |--------------------------------------------------------------------------
    | Maps this device's numeric verify code to a tracked App\Enums\PunchMethod
    | value shown as a chip on the attendance UI. Device codes vary by model —
    | these mirror the verify_types above (Card=3, Face=4 on the AIFACE-MAGNUM).
    | Codes not listed here (e.g. 2=PIN, 15=Other) intentionally show no chip.
    | When this map is non-empty it is authoritative for the device.
    */
    'verify_methods' => [
        1 => 'fingerprint',
        3 => 'id_card',
        4 => 'face',
        // If your device uses physical/swipe cards on a distinct code, add e.g.
        // 5 => 'physical_card',
    ],

];
