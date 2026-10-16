<?php

namespace App\Models;

use App\Services\Payroll\PayrollAccess;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Per-payroll instance tracking for one step of a configured approval chain —
 * snapshotted by value from PayrollApprovalPolicy at submit time, so editing
 * the policy later never changes an in-flight payroll's required steps.
 */
#[Fillable([
    'payroll_id', 'level', 'label', 'approver_type', 'specific_user_id',
    'status', 'approver_id', 'acted_at', 'note',
])]
class PayrollApprovalStep extends Model
{
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function specificUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'specific_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * The permission a step's approver must hold (Phase 4 policy: payroll
     * authorisation is permission-based; the step type only names who it is
     * meant for). Finance sign-off needs approve_finance; every other step
     * approve_payroll.
     */
    public static function permissionFor(string $approverType): string
    {
        return $approverType === 'finance' ? 'approve_finance' : 'approve_payroll';
    }

    /**
     * Can this user act on this step: they hold the step's permission with
     * company-wide payroll reach — never because of their role name. A
     * specific-user step is that user only, still with a payroll approval
     * permission and company-wide reach.
     */
    public function isEligible(User $user): bool
    {
        if (! app(PayrollAccess::class)->isCompanyWide($user)) {
            return false;
        }

        if ($this->approver_type === 'specific_user') {
            return $user->id === $this->specific_user_id
                && ($user->hasPermission('approve_payroll') || $user->hasPermission('approve_finance'));
        }

        return $user->hasPermission(self::permissionFor($this->approver_type));
    }

    /**
     * Everyone who may act on this step — the people it notifies.
     *
     * @return Collection<int, User>
     */
    public function eligibleUsers(): Collection
    {
        $candidates = $this->approver_type === 'specific_user'
            ? User::whereKey($this->specific_user_id)->get()
            : User::query()->whereNull('deleted_at')->get();

        return $candidates->filter(fn (User $u) => $this->isEligible($u))->values();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
