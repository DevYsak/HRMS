<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Onboarding = 'onboarding';
    case Probation = 'probation';
    case OnLeave = 'on-leave';
    case Resigned = 'resigned';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match($this) {
            self::Active => 'Active',
            self::Onboarding => 'Onboarding',
            self::Probation => 'Probation',
            self::OnLeave => 'On Leave',
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
        };
    }
}
