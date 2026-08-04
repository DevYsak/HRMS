<?php

namespace App\Services\Profile;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeePayrollSettings;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applies profile changes according to the tier a field belongs to.
 *
 * Every write path goes through here — the self-service page, the HR page and
 * the approval queue — so the tier rules are enforced in one place rather than
 * re-checked in each component. A Blade template hiding an input is presentation;
 * this is the actual control.
 */
class ProfileChangeService
{
    /**
     * Apply an EDITABLE field immediately.
     *
     * @throws \DomainException when the field is not employee-editable
     * @throws ValidationException
     */
    public function updateEditable(Employee $employee, string $field, mixed $value, User $actor): void
    {
        if (! ProfileFieldRegistry::isEditable($field)) {
            throw new \DomainException(
                ProfileFieldRegistry::label($field).' cannot be changed directly.'
            );
        }

        $value = $this->validated($field, $value);
        $old = ProfileFieldRegistry::valueFor($employee, $field);

        DB::transaction(function () use ($employee, $field, $value, $old, $actor) {
            $this->write($employee, $field, $value);

            AuditLog::record(
                $employee,
                'profile_updated',
                [$field => $this->stringify($old)],
                [$field => $this->stringify($value)],
                reason: 'Self-service update by '.$actor->name,
                subjectEmployeeId: $employee->id,
            );
        });
    }

    /**
     * Raise a request for an APPROVAL-tier field. The stored value is left
     * untouched until HR approves — the employee record must never show a
     * value that has not been accepted.
     *
     * @throws \DomainException when the field is not requestable, or a request is already open
     */
    public function requestChange(
        Employee $employee,
        string $field,
        mixed $value,
        User $actor,
        ?string $reason = null,
        ?string $attachmentPath = null,
    ): ProfileChangeRequest {
        if (! ProfileFieldRegistry::needsApproval($field)) {
            throw new \DomainException(
                ProfileFieldRegistry::isLocked($field)
                    ? ProfileFieldRegistry::label($field).' is managed by HR and cannot be requested.'
                    : ProfileFieldRegistry::label($field).' can be changed directly — no request needed.'
            );
        }

        $existing = ProfileChangeRequest::where('employee_id', $employee->id)
            ->where('field', $field)
            ->pending()
            ->exists();

        if ($existing) {
            throw new \DomainException(
                'A change to '.ProfileFieldRegistry::label($field).' is already awaiting review.'
            );
        }

        $value = $this->validated($field, $value);

        $request = ProfileChangeRequest::create([
            'employee_id' => $employee->id,
            'requested_by' => $actor->id,
            'field' => $field,
            'old_value' => $this->stringify(ProfileFieldRegistry::valueFor($employee, $field)),
            'new_value' => $this->stringify($value),
            'reason' => $reason,
            'attachment_path' => $attachmentPath,
            'status' => ProfileChangeRequest::STATUS_PENDING,
        ]);

        AuditLog::record(
            $request,
            'requested',
            null,
            ['field' => $field, 'new_value' => $request->new_value],
            reason: $reason,
            subjectEmployeeId: $employee->id,
        );

        return $request;
    }

    /** Approve a pending request and write the value onto the employee. */
    public function approve(ProfileChangeRequest $request, User $reviewer, ?string $comment = null): ProfileChangeRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($request, $reviewer, $comment) {
            $employee = $request->employee;

            $this->write($employee, $request->field, $request->new_value);

            $request->update([
                'status' => ProfileChangeRequest::STATUS_APPROVED,
                'reviewer_id' => $reviewer->id,
                'reviewer_comment' => $comment,
                'reviewed_at' => now(),
                'claimed_by' => null,
                'claimed_at' => null,
            ]);

            AuditLog::record(
                $employee,
                'profile_updated',
                [$request->field => $request->old_value],
                [$request->field => $request->new_value],
                reason: 'Approved by '.$reviewer->name.($comment ? ' — '.$comment : ''),
                subjectEmployeeId: $employee->id,
            );

            return $request->fresh();
        });
    }

    /** Reject a pending request. The employee record is left untouched. */
    public function reject(ProfileChangeRequest $request, User $reviewer, ?string $comment = null): ProfileChangeRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => ProfileChangeRequest::STATUS_REJECTED,
            'reviewer_id' => $reviewer->id,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
            'claimed_by' => null,
            'claimed_at' => null,
        ]);

        AuditLog::record(
            $request,
            'rejected',
            null,
            ['field' => $request->field],
            reason: $comment,
            subjectEmployeeId: $request->employee_id,
        );

        return $request->fresh();
    }

    /** The requester withdrawing their own pending request. */
    public function cancel(ProfileChangeRequest $request, User $actor): ProfileChangeRequest
    {
        $this->assertPending($request);

        if ((int) $request->requested_by !== $actor->id) {
            throw new \DomainException('Only the person who raised a request can withdraw it.');
        }

        $request->update([
            'status' => ProfileChangeRequest::STATUS_CANCELLED,
            'reviewed_at' => now(),
            'claimed_by' => null,
            'claimed_at' => null,
        ]);

        return $request->fresh();
    }

    /**
     * HR editing a field directly, bypassing the request flow. Locked and
     * approval-tier fields are both writable here — that is the point of the
     * HR surface — but every change is still audited.
     */
    public function updateAsHr(Employee $employee, string $field, mixed $value, User $actor): void
    {
        if (! ProfileFieldRegistry::has($field)) {
            throw new \DomainException("Unknown profile field '{$field}'.");
        }

        if (! ProfileFieldRegistry::isHrEditable($field)) {
            throw new \DomainException(
                ProfileFieldRegistry::label($field).' is changed through its own workflow, not here. '
                .ProfileFieldRegistry::lockReason($field)
            );
        }

        $value = $this->validated($field, $value);
        $old = ProfileFieldRegistry::valueFor($employee, $field);

        DB::transaction(function () use ($employee, $field, $value, $old, $actor) {
            $this->write($employee, $field, $value);

            AuditLog::record(
                $employee,
                'profile_updated',
                [$field => $this->stringify($old)],
                [$field => $this->stringify($value)],
                reason: 'Updated by HR ('.$actor->name.')',
                subjectEmployeeId: $employee->id,
            );
        });
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function assertPending(ProfileChangeRequest $request): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('This request has already been resolved.');
        }
    }

    /**
     * Validate against the registry's own rules, so a field's constraints live
     * beside its tier rather than being restated in each component.
     */
    private function validated(string $field, mixed $value): mixed
    {
        $rules = ProfileFieldRegistry::rulesFor($field);

        // A media field's registry rules ("image", "max:2048") describe the
        // *upload*, and are enforced at the boundary where the file is
        // received. What reaches this service is the stored path, so applying
        // the upload rules here would reject every valid save.
        if ((ProfileFieldRegistry::get($field)['type'] ?? null) === 'image') {
            $rules = ['nullable', 'string', 'max:2048'];
        }

        Validator::make(
            [$field => $value],
            [$field => $rules],
            [],
            [$field => ProfileFieldRegistry::label($field)],
        )->validate();

        return $value;
    }

    /** Write a value to whichever table actually holds the field. */
    private function write(Employee $employee, string $field, mixed $value): void
    {
        match (ProfileFieldRegistry::source($field)) {
            ProfileFieldRegistry::SOURCE_USER => $employee->user?->update([$field => $value]),
            ProfileFieldRegistry::SOURCE_PAYROLL => $this->payrollSettings($employee)->update([$field => $value]),
            default => $employee->update([$field => $value]),
        };
    }

    /** Payroll settings are created lazily — an employee may not have a row yet. */
    private function payrollSettings(Employee $employee): EmployeePayrollSettings
    {
        return $employee->payrollSettings
            ?? tap(EmployeePayrollSettings::defaults($employee->id))->save()->refresh();
    }

    /** Requests store text, so values round-trip regardless of column type. */
    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }
}
