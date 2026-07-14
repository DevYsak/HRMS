<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team within a department (v4 Part 3). Has a primary Team Lead and an
 * optional secondary lead used as approval backup when the primary is on leave.
 */
#[Fillable([
    'company_id', 'department_id', 'name', 'team_lead_id', 'secondary_lead_id', 'status',
])]
class DepartmentTeam extends Model
{
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'team_lead_id');
    }

    public function secondaryLead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'secondary_lead_id');
    }

    /** @return HasMany<DepartmentTeamMember> */
    public function memberships(): HasMany
    {
        return $this->hasMany(DepartmentTeamMember::class);
    }

    /** @return HasMany<DepartmentTeamMember> */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
