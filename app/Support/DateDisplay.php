<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The single place that turns a stored date or time into the text a person
 * reads, honouring their Settings → Preferences choice (date format, 12/24-hour
 * clock, timezone).
 *
 * Presentation only: stored values, calculations, form inputs (Y-m-d / H:i),
 * file names and exports keep their machine formats and never come through here.
 *
 * Timezone rule: only instants (created_at, approved_at, audit times) are moved
 * into the reader's timezone, via {@see dateTime()}. Date-only columns (work
 * dates, leave dates, holidays) and attendance wall-clock times (punches, shift
 * times) are shown exactly as recorded — converting a midnight date would show
 * the previous day, and punches are the site's local time by design.
 */
class DateDisplay
{
    /** @var array<string, string> stored PHP format => label shown in Preferences */
    public const DATE_FORMATS = [
        'd M Y' => 'DD MMM YYYY',
        'd/m/Y' => 'DD/MM/YYYY',
        'm/d/Y' => 'MM/DD/YYYY',
        'Y-m-d' => 'YYYY-MM-DD',
    ];

    /** @var array<string, string> stored PHP format => label shown in Preferences */
    public const TIME_FORMATS = [
        'H:i' => '24-hour',
        'h:i A' => '12-hour',
    ];

    /** @var array<string, string> the year-less companion of each date format */
    private const SHORT_FORMATS = [
        'd M Y' => 'd M',
        'd/m/Y' => 'd/m',
        'm/d/Y' => 'm/d',
        'Y-m-d' => 'm-d',
    ];

    public const DEFAULT_DATE_FORMAT = 'd M Y';

    /** New accounts start on the 12-hour clock; saved preferences always win. */
    public const DEFAULT_TIME_FORMAT = 'h:i A';

    public const EMPTY = '—';

    public function __construct(private ?User $user = null) {}

    /** A formatter for a specific reader (e.g. a mail recipient) instead of the signed-in user. */
    public static function for(?User $user): self
    {
        return new self($user);
    }

    public function dateFormat(): string
    {
        $format = $this->reader()?->date_format;

        return isset(self::DATE_FORMATS[$format]) ? $format : self::DEFAULT_DATE_FORMAT;
    }

    public function timeFormat(): string
    {
        $format = $this->reader()?->time_format;

        return isset(self::TIME_FORMATS[$format]) ? $format : self::DEFAULT_TIME_FORMAT;
    }

    public function timezone(): string
    {
        $timezone = $this->reader()?->timezone;

        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return (string) config('app.timezone');
    }

    /** "11 Oct 2026" / "11/10/2026" … — a calendar date, never timezone-shifted. */
    public function date(mixed $value): string
    {
        $carbon = $this->parse($value);

        return $carbon ? $carbon->format($this->dateFormat()) : self::EMPTY;
    }

    /** "11 Oct" / "11/10" … — the same date without the year, for compact lists. */
    public function shortDate(mixed $value): string
    {
        $carbon = $this->parse($value);

        return $carbon ? $carbon->format(self::SHORT_FORMATS[$this->dateFormat()]) : self::EMPTY;
    }

    /** "09:30" / "09:30 AM" — a wall-clock time (punch, shift, OT window), never timezone-shifted. */
    public function time(mixed $value): string
    {
        $carbon = $this->parse($value);

        return $carbon ? $carbon->format($this->timeFormat()) : self::EMPTY;
    }

    /** "11 Oct 2026, 09:30" — an instant, shown in the reader's timezone. */
    public function dateTime(mixed $value): string
    {
        $carbon = $this->parse($value);

        if (! $carbon) {
            return self::EMPTY;
        }

        return $carbon->setTimezone($this->timezone())->format($this->dateFormat().', '.$this->timeFormat());
    }

    /** A live example of the chosen formats, for the Preferences page. */
    public function example(?string $dateFormat = null, ?string $timeFormat = null): string
    {
        $now = CarbonImmutable::now()->setTimezone($this->timezone());

        return $now->format(($dateFormat ?? $this->dateFormat()).', '.($timeFormat ?? $this->timeFormat()));
    }

    private function reader(): ?User
    {
        if ($this->user) {
            return $this->user;
        }

        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function parse(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }

            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }
}
