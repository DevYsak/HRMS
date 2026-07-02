<?php

namespace App\Support;

use App\Enums\PunchMethod;

/**
 * Resolves a device/engine verify value into a tracked PunchMethod.
 *
 * Verify codes are device-specific (e.g. the AIFACE-MAGNUM uses 3=Card,
 * 4=Face), so a numeric code is resolved through the authoritative
 * config('biometric.verify_methods') map. A textual value ('face', 'card',
 * 'fingerprint', …) is resolved through PunchMethod::fromDevice aliases.
 */
class PunchMethodResolver
{
    public static function resolve(int|string|null $raw): ?PunchMethod
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $isNumeric = is_int($raw) || ctype_digit((string) $raw);
        $map = config('biometric.verify_methods', []);

        // Numeric verify code + a configured device map → the map is authoritative;
        // unlisted codes (PIN, Other) resolve to null so no chip is shown.
        if ($isNumeric && $map !== []) {
            return array_key_exists((int) $raw, $map)
                ? PunchMethod::tryFrom((string) $map[(int) $raw])
                : null;
        }

        return PunchMethod::fromDevice($raw);
    }

    /** Convenience: the stored string value (or null). */
    public static function value(int|string|null $raw): ?string
    {
        return self::resolve($raw)?->value;
    }
}
