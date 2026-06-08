<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\PipGoal;
use App\Models\PipRecord;
use App\Models\User;
use App\Models\WarningLetter;
use App\Notifications\PipActivatedNotification;
use App\Notifications\PipCreatedNotification;
use App\Notifications\PipOutcomeNotification;
use Illuminate\Support\Facades\DB;

class PipService
{
    public function __construct(private readonly TimelineService $timeline) {}

    /**
     * Create a new PIP record with goals.
     *
     * @param  array{review_period_days: int, start_date: string, end_date: string, action_plan: ?string, success_criteria: ?string, goals: array}  $data
     */
    public function create(Employee $employee, array $data, User $manager, ?WarningLetter $warningLetter = null): PipRecord
    {
        return DB::transaction(function () use ($employee, $data, $manager, $warningLetter) {
            $pip = PipRecord::create([
                'employee_id' => $employee->id,
                'warning_letter_id' => $warningLetter?->id,
                'manager_id' => $manager->id,
                'review_period_days' => $data['review_period_days'] ?? 90,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'action_plan' => $data['action_plan'] ?? null,
                'success_criteria' => $data['success_criteria'] ?? null,
                'manager_notes' => $data['manager_notes'] ?? null,
                'current_reviewer_stage' => 'hr',
                'status' => 'draft',
            ]);

            foreach ($data['goals'] ?? [] as $goalData) {
                PipGoal::create([
                    'pip_record_id' => $pip->id,
                    'title' => $goalData['title'],
                    'description' => $goalData['description'] ?? null,
                    'target_date' => $goalData['target_date'] ?? null,
                    'weightage' => $goalData['weightage'] ?? 0,
                    'status' => 'not_started',
                ]);
            }

            $pip->manager->notify(new PipCreatedNotification($pip));

            return $pip;
        });
    }

    /**
     * HR activates a draft PIP — notifies employee.
     */
    public function activate(PipRecord $pip, User $hr): void
    {
        if ($pip->status !== 'draft') {
            throw new \DomainException('Only draft PIPs can be activated.');
        }

        $pip->update([
            'status' => 'active',
            'hr_reviewer_id' => $hr->id,
            'current_reviewer_stage' => 'manager',
        ]);

        $this->timeline->record(
            $pip->employee,
            'pip_created',
            'PIP Activated',
            "Review period: {$pip->review_period_days} days",
            $pip,
            $hr,
        );

        $pip->employee->user->notify(new PipActivatedNotification($pip));
    }

    /**
     * Update progress on a specific PIP goal.
     */
    public function updateGoalProgress(PipGoal $goal, float $progressPercent, ?string $notes, User $manager): void
    {
        $status = match (true) {
            $progressPercent >= 100 => 'achieved',
            $progressPercent > 0 => 'in_progress',
            default => 'not_started',
        };

        $goal->update([
            'progress_percent' => min(100, $progressPercent),
            'status' => $status,
            'manager_notes' => $notes ?? $goal->manager_notes,
        ]);

        // Record milestone if >50% progress hit for first time
        if ($progressPercent >= 50 && $status === 'in_progress') {
            $this->timeline->record(
                $goal->pipRecord->employee,
                'pip_milestone',
                'PIP Milestone: 50%+ progress on "'.$goal->title.'"',
                null,
                $goal->pipRecord,
                $manager,
            );
        }
    }

    /**
     * Record final outcome and advance stage.
     */
    public function recordOutcome(PipRecord $pip, string $outcome, User $reviewer): void
    {
        $allowedOutcomes = ['successful', 'extended', 'failed', 'escalated'];

        if (! in_array($outcome, $allowedOutcomes, true)) {
            throw new \DomainException("Invalid outcome: {$outcome}.");
        }

        if (! in_array($pip->status, ['active', 'under_review'], true)) {
            throw new \DomainException('Only active PIPs under review can have an outcome recorded.');
        }

        $pip->update([
            'outcome' => $outcome,
            'outcome_date' => now()->toDateString(),
            'status' => $outcome,
        ]);

        $this->timeline->record(
            $pip->employee,
            'pip_outcome',
            'PIP Outcome: '.ucfirst($outcome),
            null,
            $pip,
            $reviewer,
        );

        $pip->employee->user->notify(new PipOutcomeNotification($pip));
    }

    /**
     * Add a new milestone goal to a PIP.
     *
     * @param  array{title: string, description: ?string, target_date: ?string, weightage: ?float}  $data
     */
    public function addGoal(PipRecord $pip, array $data): PipGoal
    {
        if (! in_array($pip->status, ['draft', 'active'], true)) {
            throw new \DomainException('Goals can only be added to draft or active PIPs.');
        }

        return PipGoal::create([
            'pip_record_id' => $pip->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'weightage' => $data['weightage'] ?? 0,
            'status' => 'not_started',
        ]);
    }

    /**
     * Employee records their comments/response on the PIP.
     */
    public function submitEmployeeComment(PipRecord $pip, User $employee, string $comment): void
    {
        if ($pip->employee->user_id !== $employee->id) {
            throw new \DomainException('You may only comment on your own PIP.');
        }

        $pip->update(['employee_comments' => $comment]);

        $this->timeline->record(
            $pip->employee,
            'pip_comment',
            'Employee added comments to PIP',
            null,
            $pip,
            $employee,
            visibleToEmployee: true,
        );
    }

    /**
     * Advance PIP reviewer stage through the approval chain.
     */
    public function advance(PipRecord $pip, string $nextStage, User $reviewer): void
    {
        $validStages = ['manager', 'hr', 'dept_head', 'super_admin'];

        if (! in_array($nextStage, $validStages, true)) {
            throw new \DomainException("Invalid reviewer stage: {$nextStage}.");
        }

        $updates = ['current_reviewer_stage' => $nextStage, 'status' => 'under_review'];

        if ($nextStage === 'hr') {
            $updates['manager_notes'] = $pip->manager_notes;
        }

        $pip->update($updates);
    }
}
