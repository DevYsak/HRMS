<?php

namespace App\Services\Increments;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Mail\IncrementLetterMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\IncrementCycle;
use App\Models\IncrementProposal;
use App\Models\JobTitle;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Notifications\IncrementAppliedNotification;
use App\Notifications\PipCreatedNotification;
use App\Services\Performance\PipService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Layer 3 of the increment engine (v4 Phase E, spec Part 4.3–4.4):
 * cycle lifecycle, draft proposals from the calibration output, budget
 * enforcement, and applying approved increments (effective-dated
 * employee_salaries rows, SalaryRevision trail, increment letter PDF via
 * email — one of the few email-permitted actions — plus in-app
 * notification, PIP flag on band E).
 */
class IncrementService
{
    public function __construct(private readonly CalibrationService $calibration) {}

    public function openCycle(string $financialYear, string $effectiveDate, float $budgetPercent, User $actor): IncrementCycle
    {
        $cycle = IncrementCycle::create([
            'financial_year' => $financialYear,
            'effective_date' => $effectiveDate,
            'budget_percent' => $budgetPercent,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        foreach (IncrementCycle::DEFAULT_MATRIX as $band => $range) {
            $cycle->matrix()->create([
                'band' => $band,
                'min_percent' => $range['min'],
                'max_percent' => $range['max'],
                'default_percent' => $range['default'],
            ]);
        }

        AuditLog::record($cycle, 'created', null, $cycle->toArray());

        return $cycle;
    }

    /**
     * Layer 1+2 for everyone: compute annual scores, calibrate per
     * department, and draft a proposal per active employee at the matrix
     * default %. Moves the cycle to `calibration`.
     */
    public function generateProposals(IncrementCycle $cycle, User $actor): int
    {
        if (! in_array($cycle->status, ['draft', 'calibration'], true)) {
            throw new \DomainException('Proposals can only be generated while the cycle is in draft or calibration.');
        }

        $employees = Employee::with(['user', 'department'])
            ->where('status', EmployeeStatus::Active)
            ->get();

        $generated = 0;

        DB::transaction(function () use ($cycle, $actor, $employees, &$generated) {
            $scoredByDept = [];
            $insufficient = [];

            foreach ($employees as $employee) {
                ['score' => $score, 'quarters' => $quarters] = $this->calibration->annualScore($employee, $cycle);

                if ($score === null) {
                    $insufficient[] = ['employee' => $employee, 'quarters' => $quarters];
                } else {
                    $scoredByDept[$employee->department_id ?? 0][] = [
                        'employee' => $employee, 'score' => $score, 'quarters' => $quarters,
                    ];
                }
            }

            foreach ($scoredByDept as $rows) {
                $calibrated = $this->calibration->calibrateDepartment(collect($rows));

                foreach ($calibrated as $row) {
                    $this->draftProposal($cycle, $row['employee'], $actor, $row['score'], $row['quarters'], $row['z'], $row['band']);
                    $generated++;
                }
            }

            // Mid-year joiners with < 2 quarters: flagged for manual decision.
            foreach ($insufficient as $row) {
                $this->draftProposal($cycle, $row['employee'], $actor, null, $row['quarters'], null, null);
                $generated++;
            }

            $cycle->update(['status' => 'calibration']);
        });

        return $generated;
    }

    /** Director/HR band override on the calibration screen — always logged. */
    public function overrideBand(IncrementProposal $proposal, string $band, string $reason, User $actor): void
    {
        $this->assertCycleEditable($proposal->cycle);

        if (! array_key_exists($band, IncrementCycle::DEFAULT_MATRIX)) {
            throw new \DomainException('Invalid band.');
        }

        $old = $proposal->only(['band', 'proposed_percent', 'proposed_amount']);

        $matrix = $proposal->cycle->matrixFor($band);
        $percent = $matrix?->default_percent ?? 0;

        $proposal->update([
            'band' => $band,
            'band_overridden' => true,
            'override_reason' => $reason,
            'proposed_percent' => $percent,
            'proposed_amount' => round($proposal->current_gross * $percent / 100, 2),
            'new_gross' => round($proposal->current_gross * (1 + $percent / 100), 2),
        ]);

        AuditLog::record($proposal, 'band_overridden', $old, $proposal->fresh()->only(['band', 'override_reason', 'proposed_percent']));
    }

    /** Adjust a proposal's % within its band range (leads/heads stage). */
    public function updateProposal(IncrementProposal $proposal, float $percent, ?string $remarks, User $actor, bool $promotionFlag = false, ?string $newDesignation = null): void
    {
        $this->assertCycleEditable($proposal->cycle);

        if (! $proposal->isEligible()) {
            throw new \DomainException('This employee is flagged "insufficient data" — set a band first (override) before proposing a percentage.');
        }

        $matrix = $proposal->cycle->matrixFor($proposal->band);

        if ($matrix && ($percent < $matrix->min_percent || $percent > $matrix->max_percent)) {
            throw new \DomainException(
                "Band {$proposal->band} allows {$matrix->min_percent}%–{$matrix->max_percent}%."
            );
        }

        $old = $proposal->only(['proposed_percent', 'proposed_amount', 'remarks']);

        $proposal->update([
            'proposed_percent' => $percent,
            'proposed_amount' => round($proposal->current_gross * $percent / 100, 2),
            'new_gross' => round($proposal->current_gross * (1 + $percent / 100), 2),
            'remarks' => $remarks,
            'promotion_flag' => $promotionFlag,
            'new_designation' => $promotionFlag ? $newDesignation : null,
            'proposed_by' => $actor->id,
            'status' => 'pending',
        ]);

        AuditLog::record($proposal, 'proposal_updated', $old, $proposal->fresh()->only(['proposed_percent', 'proposed_amount']));
    }

    public function submitForApproval(IncrementCycle $cycle, User $actor): void
    {
        if ($cycle->status !== 'calibration') {
            throw new \DomainException('Only cycles in calibration can be submitted for approval.');
        }

        $cycle->update(['status' => 'proposed']);
        AuditLog::record($cycle, 'submitted_for_approval');

        // Calibration-ready ping to the approvers (in-app only).
        User::whereIn('role', [UserRole::Director, UserRole::SuperAdmin])->get()
            ->each(fn (User $u) => $u->notify(new IncrementAppliedNotification(null, $cycle, 'cycle_proposed')));
    }

    /**
     * Director/HR final approval — hard budget gate (spec Part 4.3): the
     * committed annual cost must not exceed budget_percent of payroll.
     */
    public function approveCycle(IncrementCycle $cycle, User $actor): void
    {
        if ($cycle->status !== 'proposed') {
            throw new \DomainException('Only proposed cycles can be approved.');
        }

        $budget = $cycle->budgetAmount();
        $committed = $cycle->committedAmount();

        if ($committed > $budget) {
            throw new \DomainException(
                'Over budget: committed ₹'.number_format($committed, 2).' exceeds the pool of ₹'.number_format($budget, 2).
                '. Reduce proposals or raise the cycle budget (with justification).'
            );
        }

        DB::transaction(function () use ($cycle, $actor) {
            // Untouched drafts approve at the matrix default %; rejected rows stay out.
            $cycle->proposals()->whereIn('status', ['draft', 'pending'])->update(['status' => 'approved', 'approved_by' => $actor->id]);
            $cycle->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
        });

        AuditLog::record($cycle, 'approved', null, ['budget' => $budget, 'committed' => $committed]);
    }

    /**
     * Apply the approved cycle: effective-dated salary rows + SalaryRevision
     * trail, increment letters (PDF → email + in-app), promotion designation
     * updates, PIP flag for band E.
     */
    public function applyCycle(IncrementCycle $cycle, User $actor): int
    {
        if ($cycle->status !== 'approved') {
            throw new \DomainException('Only approved cycles can be applied.');
        }

        $applied = 0;

        foreach ($cycle->proposals()->with('employee.user')->get() as $proposal) {
            if ($proposal->band === 'E' && $proposal->employee) {
                $this->flagPip($proposal, $actor);
            }

            if ($proposal->status !== 'approved' || $proposal->proposed_percent <= 0) {
                continue;
            }

            DB::transaction(function () use ($cycle, $proposal) {
                $this->applySalaryUplift($proposal, $cycle->effective_date);

                if ($proposal->promotion_flag && $proposal->new_designation) {
                    $this->applyPromotion($proposal);
                }
            });

            $this->issueLetter($proposal, $actor);
            $applied++;
        }

        $cycle->update(['status' => 'applied']);
        AuditLog::record($cycle, 'applied', null, ['proposals_applied' => $applied]);

        return $applied;
    }

    /** Structural monthly gross = sum of earning-component salary rows effective today. */
    public function currentGross(Employee $employee, ?Carbon $onDate = null): float
    {
        return (float) EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->effectiveOn($onDate ?? Carbon::today())
            ->with('component')
            ->get()
            ->filter(fn (EmployeeSalary $row) => $row->component?->effective_component_type === 'earning')
            ->sum('amount');
    }

    private function draftProposal(IncrementCycle $cycle, Employee $employee, User $actor, ?float $score, int $quarters, ?float $z, ?string $band): void
    {
        $gross = $this->currentGross($employee);
        $matrix = $band ? $cycle->matrixFor($band) : null;
        $percent = $matrix?->default_percent ?? 0;

        IncrementProposal::updateOrCreate(
            ['increment_cycle_id' => $cycle->id, 'employee_id' => $employee->id],
            [
                'annual_raw_score' => $score,
                'quarters_counted' => $quarters,
                'calibrated_z' => $z,
                'band' => $band,
                'current_gross' => $gross,
                'proposed_percent' => $percent,
                'proposed_amount' => round($gross * $percent / 100, 2),
                'new_gross' => round($gross * (1 + $percent / 100), 2),
                'status' => 'draft',
                'proposed_by' => $actor->id,
            ],
        );
    }

    /**
     * Close every earning salary row the day before the effective date and
     * open a new row uplifted by the approved % — never deletes history.
     */
    private function applySalaryUplift(IncrementProposal $proposal, CarbonInterface $effectiveDate): void
    {
        $factor = 1 + $proposal->proposed_percent / 100;

        $rows = EmployeeSalary::query()
            ->where('employee_id', $proposal->employee_id)
            ->effectiveOn(Carbon::parse($effectiveDate->toDateString()))
            ->with('component')
            ->get()
            ->filter(fn (EmployeeSalary $row) => $row->component?->effective_component_type === 'earning');

        foreach ($rows as $row) {
            $row->update(['effective_to' => $effectiveDate->copy()->subDay()->toDateString()]);

            EmployeeSalary::create([
                'employee_id' => $row->employee_id,
                'salary_component_id' => $row->salary_component_id,
                'amount' => round((float) $row->amount * $factor, 2),
                'effective_from' => $effectiveDate->toDateString(),
                'effective_to' => null,
            ]);
        }

        $revision = SalaryRevision::create([
            'employee_id' => $proposal->employee_id,
            'effective_date' => $effectiveDate->toDateString(),
            'reason' => "Annual increment {$proposal->cycle->financial_year}: band {$proposal->band}, {$proposal->proposed_percent}%",
            'approved_by' => $proposal->approved_by,
            'old_ctc' => round($proposal->current_gross * 12, 2),
            'new_ctc' => round($proposal->new_gross * 12, 2),
            'structure_snapshot' => $rows->map(fn ($r) => [
                'component' => $r->component?->name,
                'old_amount' => (float) $r->amount,
                'new_amount' => round((float) $r->amount * $factor, 2),
            ])->values()->all(),
        ]);

        AuditLog::record($revision, 'created', null, $revision->toArray());
    }

    private function applyPromotion(IncrementProposal $proposal): void
    {
        $employee = $proposal->employee;
        $old = ['job_title_id' => $employee->job_title_id];

        $title = JobTitle::firstOrCreate(
            ['name' => $proposal->new_designation],
            ['department_id' => $employee->department_id],
        );

        $employee->update(['job_title_id' => $title->id]);
        AuditLog::record($employee, 'promoted', $old, ['job_title_id' => $title->id, 'designation' => $title->name]);
    }

    /** Generate the letter PDF, store it, email it (permitted), notify in-app. */
    private function issueLetter(IncrementProposal $proposal, User $actor): void
    {
        $employee = $proposal->employee;

        if (! $employee?->user) {
            return;
        }

        $pdf = Pdf::loadView('pdf.increment-letter', [
            'proposal' => $proposal->load('cycle'),
            'employee' => $employee->load(['user', 'jobTitle', 'department']),
        ]);

        $path = "documents/increment-letters/{$employee->id}/increment-{$proposal->cycle->financial_year}.pdf";
        Storage::disk('local')->put($path, $pdf->output());
        $proposal->update(['letter_path' => $path]);

        if ($employee->user->email) {
            Mail::to($employee->user->email)->queue(new IncrementLetterMail($proposal));
        }

        $employee->user->notify(new IncrementAppliedNotification($proposal, $proposal->cycle, 'increment_applied'));
    }

    /** Band E → auto-draft a 90-day PIP and alert HR + department head. */
    private function flagPip(IncrementProposal $proposal, User $actor): void
    {
        $employee = $proposal->employee;

        if ($employee->activePip()->exists()) {
            return;
        }

        $managerUser = $employee->activeTeam()?->teamLead?->user
            ?? $employee->manager
            ?? $actor;

        $pip = app(PipService::class)->create($employee, [
            'start_date' => $proposal->cycle->effective_date->toDateString(),
            'end_date' => $proposal->cycle->effective_date->copy()->addDays(90)->toDateString(),
            'action_plan' => "Auto-flagged from increment cycle {$proposal->cycle->financial_year}: calibration band E (Needs Improvement).",
            'success_criteria' => 'Reach at least band C in the next two quarterly reviews.',
        ], $managerUser);

        // Spec: notify HR Admin + Department Head (manager already notified by PipService).
        $notifiables = User::where('role', UserRole::HrAdmin)->get();
        if ($head = $employee->department?->head) {
            $notifiables->push($head);
        }
        $notifiables->unique('id')->each(fn (User $u) => $u->notify(new PipCreatedNotification($pip)));

        AuditLog::record($pip, 'auto_flagged_band_e', null, ['increment_proposal_id' => $proposal->id]);
    }

    private function assertCycleEditable(IncrementCycle $cycle): void
    {
        if (! $cycle->isEditable()) {
            throw new \DomainException("Cycle is {$cycle->status} and can no longer be edited.");
        }
    }
}
