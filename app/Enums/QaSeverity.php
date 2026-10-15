<?php

namespace App\Enums;

enum QaSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::High => 'rose',
            self::Medium => 'amber',
            self::Low => 'sky',
            self::Info => 'zinc',
        };
    }

    /** Higher is worse; used to sort. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 5,
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Info => 1,
        };
    }
}
