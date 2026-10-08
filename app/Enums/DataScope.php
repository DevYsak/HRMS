<?php

namespace App\Enums;

/**
 * How far a permission that touches employee data reaches.
 *
 * Every scope also covers the user's own record; deciding (approving,
 * rejecting, editing) one's own record is refused separately by ApprovalGuard.
 */
enum DataScope: string
{
    /** Only the user's own record. */
    case Own = 'own';

    /** The user's reporting line: direct reports, teams they lead, departments they head. */
    case Team = 'team';

    /** The user's own department and every department they head (plus their team). */
    case Department = 'department';

    /** Departments chosen on the grant (plus their team). */
    case SelectedDepartments = 'selected_departments';

    /** Every employee in the company. */
    case All = 'all';

    /** Nothing, even if the permission is otherwise held. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records only',
            self::Team => 'Team (reporting line)',
            self::Department => 'Own department',
            self::SelectedDepartments => 'Selected departments',
            self::All => 'All departments',
            self::None => 'No access',
        };
    }

    /** Broader scopes rank higher; used to compare and to cap delegation. */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Own => 1,
            self::Team => 2,
            self::Department => 3,
            self::SelectedDepartments => 3,
            self::All => 4,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
