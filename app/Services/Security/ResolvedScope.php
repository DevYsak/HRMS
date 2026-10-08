<?php

namespace App\Services\Security;

use App\Enums\DataScope;

/**
 * How far one user's grant of one permission reaches.
 *
 * departmentIds is used by SelectedDepartments. shiftIds is set only for the
 * legacy per-user scope (users.scope_shifts), which intersects with
 * departments the way it always has.
 */
final readonly class ResolvedScope
{
    /**
     * @param  array<int, int>  $departmentIds
     * @param  array<int, int>  $shiftIds
     */
    public function __construct(
        public DataScope $scope,
        public array $departmentIds = [],
        public array $shiftIds = [],
        public string $source = 'role_default',
    ) {}

    public function isAll(): bool
    {
        return $this->scope === DataScope::All;
    }

    public function isNone(): bool
    {
        return $this->scope === DataScope::None;
    }
}
