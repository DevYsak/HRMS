<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable(['shift_start', 'shift_end', 'weekly_off_days', 'late_grace_period', 'late_warning_threshold', 'auto_checkout_buffer_minutes', 'ot_auto_close_time', 'ot_rate_per_hour', 'requires_location', 'requires_qr', 'requires_photo'])]
class AttendanceSetting extends Model
{
    protected $casts = [
        'weekly_off_days' => 'array',
        'ot_rate_per_hour' => 'float',
        'auto_checkout_buffer_minutes' => 'integer',
        'requires_location' => 'boolean',
        'requires_qr' => 'boolean',
        'requires_photo' => 'boolean',
    ];

    /**
     * Memoised so the weekly-off lookup stays a single query even when called
     * per employee per day while building a month-long report.
     *
     * @var array<int, int>|null
     */
    private static ?array $weeklyOffCache = null;

    protected static function booted(): void
    {
        // Any change to the settings row invalidates the memo.
        static::saved(fn () => static::flushWeeklyOffCache());
        static::deleted(fn () => static::flushWeeklyOffCache());
    }

    public static function flushWeeklyOffCache(): void
    {
        static::$weeklyOffCache = null;
    }

    /**
     * Non-working weekdays as Carbon dayOfWeek numbers (0 = Sunday … 6 = Saturday).
     * Falls back to Sunday-only, which is what the system assumed everywhere
     * before this became configurable.
     *
     * @return array<int, int>
     */
    public static function weeklyOffDays(): array
    {
        if (static::$weeklyOffCache !== null) {
            return static::$weeklyOffCache;
        }

        $raw = static::query()->value('weekly_off_days');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $days = is_array($raw)
            ? array_values(array_unique(array_map('intval', $raw)))
            : [];

        $days = array_values(array_filter($days, fn (int $d) => $d >= 0 && $d <= 6));

        // A configuration marking all seven days off would make every employee
        // permanently absent, so fall back rather than honour it.
        return static::$weeklyOffCache = ($days !== [] && count($days) < 7)
            ? $days
            : [Carbon::SUNDAY];
    }

    /** Is this date a non-working day under the configured working week? */
    public static function isWeeklyOff(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeek, static::weeklyOffDays(), true);
    }

    /** The configured off days as short names, e.g. "Sun, Sat". */
    public static function weeklyOffLabel(): string
    {
        $names = [
            Carbon::SUNDAY => 'Sun', Carbon::MONDAY => 'Mon', Carbon::TUESDAY => 'Tue',
            Carbon::WEDNESDAY => 'Wed', Carbon::THURSDAY => 'Thu', Carbon::FRIDAY => 'Fri',
            Carbon::SATURDAY => 'Sat',
        ];

        $off = static::weeklyOffDays();
        sort($off);

        return implode(', ', array_map(fn (int $d) => $names[$d], $off));
    }

    /** Working days in an inclusive date range, excluding weekly offs. */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $count = 0;

        while ($cursor->lte($end)) {
            if (! static::isWeeklyOff($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }
}
