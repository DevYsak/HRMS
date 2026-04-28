<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Onboarding = 'onboarding';
    case Probation = 'probation';
    case NoticePeriod = 'notice_period';
    case OnLeave = 'on-leave';
    case Inactive = 'inactive';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Onboarding => 'Onboarding',
            self::Probation => 'Probation',
            self::NoticePeriod => 'Notice Period',
            self::OnLeave => 'On Leave',
            self::Inactive => 'Inactive',
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
        };
    }

    /** Returns true for states where the employee no longer works at the company. */
    public function isArchived(): bool
    {
        return in_array($this, [self::Inactive, self::Resigned, self::Terminated]);
    }
}
