<?php

namespace App\Enums;

/** Where a QA finding is in its life: found → fixed → retested → verified (or consciously accepted). */
enum QaFindingStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Fixing = 'fixing';
    case Fixed = 'fixed';
    case RetestRequired = 'retest_required';
    case HrCorrectionRequired = 'hr_correction_required';
    case Verified = 'verified';
    case AcceptedRisk = 'accepted_risk';
    case WontFix = 'wont_fix';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Fixing => 'Fixing',
            self::Fixed => 'Fixed',
            self::RetestRequired => 'Retest Required',
            self::HrCorrectionRequired => 'HR Data Correction Required',
            self::Verified => 'Verified',
            self::AcceptedRisk => 'Accepted Risk',
            self::WontFix => "Won't Fix",
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'rose',
            self::Investigating, self::Fixing => 'amber',
            self::Fixed, self::RetestRequired => 'sky',
            self::HrCorrectionRequired => 'orange',
            self::Verified => 'emerald',
            self::AcceptedRisk => 'violet',
            self::WontFix => 'zinc',
        };
    }

    /** Still a live issue: it counts against readiness. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Open, self::Investigating, self::Fixing, self::Fixed, self::RetestRequired, self::HrCorrectionRequired], true);
    }

    /** Closing as an accepted risk or won't-fix must be explained. */
    public function requiresNote(): bool
    {
        return in_array($this, [self::AcceptedRisk, self::WontFix], true);
    }
}
