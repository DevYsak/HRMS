<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\PerformanceReview;
use App\Models\PipRecord;
use App\Models\PromotionRecommendation;
use App\Models\User;
use App\Models\WarningLetter;

/**
 * Who may open a stored document (spec v3.1 §3.9 / §4).
 *
 *   - Document managers (HR Admin / Super Admin)       → every document.
 *   - Company-wide documents and policies             → all staff, unless the
 *                                                        policy is addressed
 *                                                        to one employee.
 *   - Employee documents (contracts, letters, PIPs)   → that employee only.
 *   - Payslip documents                               → the employee, and
 *                                                        payroll staff (Finance
 *                                                        / HR) — never a
 *                                                        Director or Manager.
 *
 * A signed link only proves the URL was issued; this decides whether the
 * person holding it may read the file.
 */
class DocumentPolicy
{
    /** Records whose documents follow the performance workflow's own access. */
    private const PERFORMANCE_RECORDS = [
        PipRecord::class,
        PromotionRecommendation::class,
        WarningLetter::class,
        PerformanceReview::class,
    ];

    public function view(User $user, Document $document): bool
    {
        if ($user->canManageDocuments()) {
            return true;
        }

        $ownEmployeeId = $user->employee?->id;
        $isOwn = $ownEmployeeId !== null && (int) $document->employee_id === (int) $ownEmployeeId;

        if ($isOwn || $document->visibility === 'all') {
            return true;
        }

        if ($document->category === 'policy' && $document->employee_id === null) {
            return true;
        }

        // A PIP, promotion, warning-letter or review document belongs to that
        // workflow: whoever runs it for the employee (performance reviewers,
        // employee managers) may open it — inside their reach. Their own
        // record's documents are already covered by $isOwn above.
        if (in_array($document->documentable_type, self::PERFORMANCE_RECORDS, true)
            && ($user->canReviewPerformance() || $user->canManageEmployees())
            && $document->employee !== null
            && $user->coversEmployee($document->employee)) {
            return true;
        }

        return $document->category === 'payslip' && $user->canRunPayroll();
    }

    /** Acknowledging is reading plus the document actually asking for it. */
    public function acknowledge(User $user, Document $document): bool
    {
        return (bool) $document->requires_acknowledgement && $this->view($user, $document);
    }
}
