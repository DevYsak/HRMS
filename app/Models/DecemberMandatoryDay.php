<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One Mandatory December Leave (MDL) date — a company shutdown day.
 *
 * MDL is never a leave balance: it cannot be requested, does not count as
 * available leave, and is not drawn from CSL. The policy says how many of
 * these a December should have (leave_policies.mandatory_leave_days).
 */
#[Fillable(['year', 'date', 'description'])]
class DecemberMandatoryDay extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** Check if a given date is a December Mandatory Leave day. */
    public static function isMandatory(CarbonInterface $date): bool
    {
        return static::where('year', $date->year)
            ->where('date', $date->toDateString())
            ->exists();
    }

    /**
     * The MDL dates that fall inside a leave year (a July–June year holds
     * exactly one December).
     *
     * @return Collection<int, self>
     */
    public static function forLeaveYear(LeaveYear $year): Collection
    {
        return static::query()
            ->whereDate('date', '>=', $year->starts_on->toDateString())
            ->whereDate('date', '<=', $year->ends_on->toDateString())
            ->orderBy('date')
            ->get();
    }
}
