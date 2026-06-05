<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    // Pre-joining
    case Draft = 'draft';

    // Active lifecycle
    case Onboarding = 'onboarding';
    case Probation = 'probation';
    case Confirmed = 'confirmed';
    case Active = 'active';

    // Exit pipeline
    case NoticePeriod = 'notice_period';
    case Resigned = 'resigned';
    case Terminated = 'terminated';
    case Absconded = 'absconded';

    // Terminal / legacy
    case OnLeave = 'on-leave';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Onboarding => 'Onboarding',
            self::Probation => 'Probation',
            self::Confirmed => 'Confirmed',
            self::Active => 'Active',
            self::NoticePeriod => 'Notice Period',
            self::Resigned => 'Resigned',
            self::Terminated => 'Terminated',
            self::Absconded => 'Absconded',
            self::OnLeave => 'On Leave',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Onboarding => 'sky',
            self::Probation => 'amber',
            self::Confirmed => 'lime',
            self::Active => 'green',
            self::NoticePeriod => 'orange',
            self::Resigned => 'red',
            self::Terminated => 'red',
            self::Absconded => 'fuchsia',
            self::OnLeave => 'blue',
            self::Inactive => 'zinc',
            self::Archived => 'zinc',
        };
    }

    /** States that represent current, billable headcount. */
    public function isActive(): bool
    {
        return \in_array($this, [self::Probation, self::Confirmed, self::Active, self::OnLeave]);
    }

    /** States where the employee no longer works at the company. */
    public function isExited(): bool
    {
        return \in_array($this, [self::Resigned, self::Terminated, self::Absconded]);
    }

    /** Terminal states — no further transitions expected. */
    public function isArchived(): bool
    {
        return \in_array($this, [self::Inactive, self::Archived, self::Resigned, self::Terminated, self::Absconded]);
    }

    /** Valid next statuses reachable from this one via normal lifecycle transitions. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Onboarding],
            self::Onboarding => [self::Probation, self::Active],
            self::Probation => [self::Confirmed, self::Active, self::Terminated, self::Absconded],
            self::Confirmed => [self::Active],
            self::Active => [self::NoticePeriod, self::Terminated, self::Absconded, self::OnLeave],
            self::NoticePeriod => [self::Resigned, self::Active, self::Terminated],
            self::OnLeave => [self::Active],
            self::Resigned,
            self::Terminated,
            self::Absconded => [self::Archived],
            self::Inactive => [self::Archived],
            self::Confirmed,
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return \in_array($next, $this->allowedTransitions(), true);
    }
}
