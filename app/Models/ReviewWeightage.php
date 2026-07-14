<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reviewer-role weight configuration (v4 Phase D). A row with a null
 * department_id is the company default; a department row overrides it.
 */
#[Fillable(['department_id', 'reviewer_role', 'weight_percent'])]
class ReviewWeightage extends Model
{
    /** @var array<string, float> */
    public const DEFAULTS = [
        'self' => 20.0,
        'team_lead' => 50.0,
        'department_head' => 30.0,
    ];

    protected function casts(): array
    {
        return ['weight_percent' => 'float'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Resolve the weight for a role: department override → company default
     * row → hard-coded spec default.
     */
    public static function weightFor(string $role, ?int $departmentId = null): float
    {
        if ($departmentId !== null) {
            $override = static::where('department_id', $departmentId)->where('reviewer_role', $role)->value('weight_percent');
            if ($override !== null) {
                return (float) $override;
            }
        }

        $default = static::whereNull('department_id')->where('reviewer_role', $role)->value('weight_percent');

        return $default !== null ? (float) $default : (self::DEFAULTS[$role] ?? 0.0);
    }
}
