<?php

namespace App\Enums;

enum QaPhaseStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case NeedsRetest = 'needs_retest';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not Started',
            self::InProgress => 'In Progress',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::NeedsRetest => 'Needs Retest',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'zinc',
            self::InProgress => 'sky',
            self::Blocked => 'rose',
            self::Completed => 'emerald',
            self::NeedsRetest => 'amber',
        };
    }
}
