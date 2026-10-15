<?php

namespace App\Enums;

/** The result of a QA test case (latest on the case, every one in its history). */
enum QaTestStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Partial = 'partial';
    case Blocked = 'blocked';
    case KnownBaseline = 'known_baseline';
    case NotTested = 'not_tested';
    case RetestRequired = 'retest_required';
    case FixedRetestPending = 'fixed_retest_pending';
    case FixedVerified = 'fixed_verified';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Fail => 'FAIL',
            self::Partial => 'PARTIAL',
            self::Blocked => 'BLOCKED',
            self::KnownBaseline => 'KNOWN BASELINE',
            self::NotTested => 'NOT TESTED',
            self::RetestRequired => 'RETEST REQUIRED',
            self::FixedRetestPending => 'FIXED / RETEST PENDING',
            self::FixedVerified => 'FIXED / VERIFIED',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Pass, self::FixedVerified => 'emerald',
            self::Fail => 'rose',
            self::Partial => 'amber',
            self::Blocked => 'orange',
            self::KnownBaseline => 'violet',
            self::NotTested => 'zinc',
            self::RetestRequired, self::FixedRetestPending => 'sky',
        };
    }

    /** A tester must explain these in a note. */
    public function requiresNote(): bool
    {
        return in_array($this, [self::Fail, self::Blocked], true);
    }

    /** Counts as passing for the QA score (a verified fix passes; a known baseline is reported separately). */
    public function isPassing(): bool
    {
        return in_array($this, [self::Pass, self::FixedVerified], true);
    }

    /** Statuses a tester may mark by hand. */
    public static function manual(): array
    {
        return [self::Pass, self::Fail, self::Partial, self::Blocked, self::RetestRequired, self::KnownBaseline];
    }
}
