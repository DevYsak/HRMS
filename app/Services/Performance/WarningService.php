<?php

namespace App\Services\Performance;

use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Models\WarningAcknowledgement;
use App\Models\WarningLetter;
use App\Notifications\WarningEscalatedNotification;
use App\Notifications\WarningIssuedNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;

class WarningService
{
    public function __construct(private readonly TimelineService $timeline) {}

    /**
     * Issue a new warning letter (or save as draft).
     *
     * @param  array{warning_type: string, reason: string, description: ?string, issue_date: string, next_review_date: ?string, attachment_path: ?string, department_id: ?int}  $data
     */
    public function issue(Employee $employee, array $data, User $issuer, bool $asDraft = false): WarningLetter
    {
        return DB::transaction(function () use ($employee, $data, $issuer, $asDraft) {
            $warning = WarningLetter::create([
                'employee_id' => $employee->id,
                'issued_by' => $issuer->id,
                'department_id' => $data['department_id'] ?? $employee->department_id,
                'warning_type' => $data['warning_type'],
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'issue_date' => $data['issue_date'],
                'next_review_date' => $data['next_review_date'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'previous_warning_id' => $data['previous_warning_id'] ?? null,
                'status' => $asDraft ? 'draft' : 'issued',
            ]);

            if (! $asDraft) {
                $this->timeline->record(
                    $employee,
                    'warning_issued',
                    $warning->warningTypeLabel().' Issued',
                    $data['reason'],
                    $warning,
                    $issuer,
                );

                $employee->user->notify(new WarningIssuedNotification($warning));
            }

            return $warning;
        });
    }

    /**
     * Employee acknowledges a warning.
     */
    public function acknowledge(WarningLetter $warning, User $employee, ?string $acknowledgementText = null): WarningAcknowledgement
    {
        if ($warning->status !== 'issued') {
            throw new \DomainException('Only issued warnings can be acknowledged.');
        }

        if ($warning->isAcknowledged()) {
            throw new \DomainException('This warning has already been acknowledged.');
        }

        return DB::transaction(function () use ($warning, $employee, $acknowledgementText) {
            $ack = WarningAcknowledgement::create([
                'warning_letter_id' => $warning->id,
                'acknowledged_by' => $employee->id,
                'acknowledgement_text' => $acknowledgementText,
                'acknowledged_at' => now(),
                'ip_address' => Request::ip(),
            ]);

            $warning->update(['status' => 'acknowledged']);

            $this->timeline->record(
                $warning->employee,
                'warning_acknowledged',
                $warning->warningTypeLabel().' Acknowledged',
                null,
                $warning,
                $employee,
                visibleToEmployee: true,
            );

            return $ack;
        });
    }

    /**
     * Escalate a warning to the next level in the chain.
     */
    public function escalate(WarningLetter $warning, User $issuer, array $newData = []): WarningLetter
    {
        $nextType = $warning->nextWarningType();

        if ($nextType === null) {
            throw new \DomainException('This warning type has no further escalation level.');
        }

        $escalated = $this->issue(
            $warning->employee,
            array_merge([
                'warning_type' => $nextType,
                'reason' => $newData['reason'] ?? $warning->reason,
                'description' => $newData['description'] ?? null,
                'issue_date' => now()->toDateString(),
                'next_review_date' => $newData['next_review_date'] ?? null,
                'previous_warning_id' => $warning->id,
                'department_id' => $warning->department_id,
            ], $newData),
            $issuer,
        );

        $this->timeline->record(
            $escalated->employee,
            'warning_escalated',
            $warning->warningTypeLabel().' Escalated to '.$escalated->warningTypeLabel(),
            $newData['reason'] ?? $warning->reason,
            $escalated,
            $issuer,
        );

        $escalated->employee->user->notify(new WarningEscalatedNotification($escalated));

        return $escalated;
    }

    /**
     * Generate the formal warning letter PDF and store it in the employee's
     * documents (issued copies require acknowledgement).
     */
    public function generatePdf(WarningLetter $warning, User $generatedBy): Document
    {
        $warning->loadMissing(['employee.user', 'employee.department', 'employee.jobTitle', 'employee.manager', 'employee.employmentType', 'department', 'issuedBy', 'previousWarning']);

        $pdf = Pdf::loadView('pdf.reports.warning-letter', ['warning' => $warning]);

        $fileName = "warning-letter-{$warning->id}-".now()->format('Ymd-His').'.pdf';
        $path = "documents/warning-letters/{$warning->id}/{$fileName}";

        Storage::disk('local')->put($path, $pdf->output());

        $nextVersion = ($warning->documents()->max('version') ?? 0) + 1;

        return $warning->documents()->create([
            'title' => $warning->warningTypeLabel().' — '.$warning->employee->user->name,
            'description' => 'Formally generated warning letter (v'.$nextVersion.').',
            'file_path' => $path,
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'file_size' => Storage::disk('local')->size($path),
            'version' => $nextVersion,
            'category' => 'warning_letter',
            'visibility' => 'restricted',
            'employee_id' => $warning->employee_id,
            'requires_acknowledgement' => true,
            'uploaded_by' => $generatedBy->id,
        ]);
    }

    /**
     * Close a warning with mandatory HR/manager comment.
     */
    public function close(WarningLetter $warning, User $closer, string $comment): void
    {
        if ($warning->status === 'closed') {
            throw new \DomainException('Warning is already closed.');
        }

        $field = $closer->isHrAdmin() ? 'hr_comments' : 'manager_comments';

        $warning->update([
            'status' => 'closed',
            $field => $comment,
            'closed_at' => now(),
            'closed_by' => $closer->id,
        ]);
    }
}
