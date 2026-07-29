<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Admin-configured step definitions for the payroll approval workflow —
 * a checklist template, not per-payroll state. See PayrollApprovalStep for
 * the per-payroll instance tracking snapshotted from this at submit time.
 */
#[Fillable(['level', 'label', 'approver_type', 'specific_user_id', 'is_active'])]
class PayrollApprovalPolicy extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function specificUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }

    /** @return Collection<int, self> */
    public static function activeSteps(): Collection
    {
        return self::where('is_active', true)->orderBy('level')->get();
    }

    /** Re-sequence every row 1..N by current level order — keeps levels contiguous after a reorder/delete. */
    public static function renumber(): void
    {
        DB::transaction(function () {
            self::orderBy('level')->orderBy('id')->get()->values()
                ->each(fn (self $policy, int $index) => $policy->update(['level' => $index + 1]));
        });
    }
}
