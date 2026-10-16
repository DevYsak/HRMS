<?php

namespace App\Livewire\Performance;

use App\Models\IncrementCycle;
use App\Models\IncrementProposal;
use App\Services\Increments\IncrementService;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Increment engine control room (v4 Phase E): open the FY cycle, generate
 * calibrated proposals, adjust within band ranges, override bands with a
 * logged reason, watch the budget bar, then approve (Director/Super Admin)
 * and apply.
 *
 * Reach is the manage_employees scope: a department-scoped holder (e.g. a
 * Director) sees and adjusts only their own people's proposals and never
 * the company payroll pool; opening, generating, submitting, approving and
 * applying a cycle act on everyone, so they need company-wide reach.
 */
class IncrementCenter extends Component
{
    public ?int $cycleId = null;

    // Open-cycle form
    public bool $showCycleForm = false;

    public string $financialYear = '';

    public string $effectiveDate = '';

    public string $budgetPercent = '10';

    // Band override modal
    public ?int $overrideProposalId = null;

    public string $overrideBand = 'C';

    public string $overrideReason = '';

    // Inline proposal edits: [proposal_id => ['percent' =>, 'remarks' =>, 'promo' =>, 'designation' =>]]
    public array $edits = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->cycleId = IncrementCycle::orderByDesc('effective_date')->value('id');
    }

    public function selectCycle(int $id): void
    {
        $this->cycleId = $id;
        $this->edits = [];
    }

    public function newCycleForm(): void
    {
        $this->assertCompanyWide();

        $july = now()->month >= 7 ? now()->year : now()->year - 1;
        $this->financialYear = ($july + 1).'-'.substr((string) ($july + 2), 2);
        $this->effectiveDate = ($july + 1).'-07-01';
        $this->budgetPercent = '10';
        $this->showCycleForm = true;
    }

    public function openCycle(IncrementService $service): void
    {
        $this->assertCompanyWide();
        $this->validate([
            'financialYear' => 'required|string|max:9|unique:increment_cycles,financial_year',
            'effectiveDate' => 'required|date',
            'budgetPercent' => 'required|numeric|min:0.1|max:100',
        ]);

        $cycle = $service->openCycle($this->financialYear, $this->effectiveDate, (float) $this->budgetPercent, Auth::user());

        $this->cycleId = $cycle->id;
        $this->showCycleForm = false;
        \Flux::toast("Increment cycle {$cycle->financial_year} opened.");
    }

    public function generate(IncrementService $service): void
    {
        $this->assertCompanyWide();
        try {
            $count = $service->generateProposals($this->cycle(), Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast("{$count} proposals generated and calibrated.");
    }

    public function saveProposal(int $proposalId, IncrementService $service): void
    {
        $edit = $this->edits[$proposalId] ?? [];
        $proposal = $this->proposalInReach($proposalId);

        try {
            $service->updateProposal(
                $proposal,
                (float) ($edit['percent'] ?? $proposal->proposed_percent),
                trim($edit['remarks'] ?? '') ?: null,
                Auth::user(),
                (bool) ($edit['promo'] ?? false),
                trim($edit['designation'] ?? '') ?: null,
            );
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        unset($this->edits[$proposalId]);
        \Flux::toast('Proposal updated.');
    }

    public function openOverride(int $proposalId): void
    {
        $proposal = $this->proposalInReach($proposalId);
        $this->overrideProposalId = $proposal->id;
        $this->overrideBand = $proposal->band ?? 'C';
        $this->overrideReason = '';
    }

    public function applyOverride(IncrementService $service): void
    {
        $this->validate([
            'overrideBand' => 'required|in:A,B,C,D,E',
            'overrideReason' => 'required|string|min:10|max:500',
        ], [
            'overrideReason.required' => 'Every band override needs a logged reason.',
            'overrideReason.min' => 'Please give a meaningful reason (at least 10 characters).',
        ]);

        $proposal = $this->proposalInReach((int) $this->overrideProposalId);

        try {
            $service->overrideBand($proposal, $this->overrideBand, $this->overrideReason, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->overrideProposalId = null;
        \Flux::toast('Band overridden — logged to the audit trail.');
    }

    public function submitCycle(IncrementService $service): void
    {
        $this->assertCompanyWide();
        try {
            $service->submitForApproval($this->cycle(), Auth::user());
            \Flux::toast('Cycle submitted — Directors notified for final approval.');
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');
        }
    }

    public function approveCycle(IncrementService $service): void
    {
        abort_unless(Auth::user()->canApproveFinance() || Auth::user()->isSuperAdmin(), 403);
        $this->assertCompanyWide();

        try {
            $held = $service->approveCycle($this->cycle(), Auth::user());
            \Flux::toast($held > 0
                ? 'Cycle approved within budget. Your own raise is held for another approver.'
                : 'Cycle approved within budget.');
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');
        }
    }

    /** Approve a raise held back at cycle approval (it was that approver's own). */
    public function approveHeld(int $proposalId, IncrementService $service): void
    {
        abort_unless(Auth::user()->canApproveFinance() || Auth::user()->isSuperAdmin(), 403);

        $proposal = $this->proposalInReach($proposalId);

        try {
            $service->approveHeldProposal($proposal, Auth::user());
            \Flux::toast('Held raise approved.');
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');
        }
    }

    public function applyCycle(IncrementService $service): void
    {
        abort_unless(Auth::user()->canApproveFinance() || Auth::user()->isSuperAdmin(), 403);
        $this->assertCompanyWide();

        try {
            $count = $service->applyCycle($this->cycle(), Auth::user());
            \Flux::toast("{$count} increments applied — salary rows updated and letters emailed.");
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');
        }
    }

    public function render()
    {
        $reach = $this->reach();
        $cycle = $this->cycleId
            ? IncrementCycle::with(['matrix', 'proposals' => fn ($q) => $q->when($reach !== null, fn ($p) => $p->whereIn('employee_id', $reach)),
                'proposals.employee.user', 'proposals.employee.department', 'proposals.employee.jobTitle'])->find($this->cycleId)
            : null;

        $byDepartment = $cycle
            ? $cycle->proposals->sortByDesc('annual_raw_score')->groupBy(fn ($p) => $p->employee?->department?->name ?? 'Unassigned')
            : collect();

        $bandCounts = $cycle
            ? $cycle->proposals->whereNotNull('band')->countBy('band')->sortKeys()
            : collect();

        return view('livewire.performance.increment-center', [
            'cycles' => IncrementCycle::orderByDesc('effective_date')->get(),
            'cycle' => $cycle,
            'byDepartment' => $byDepartment,
            'bandCounts' => $bandCounts,
            // The pool is the company payroll: shown only with company-wide reach.
            'companyWide' => $reach === null,
            'budget' => $reach === null ? ($cycle?->budgetAmount() ?? 0) : null,
            'committed' => $reach === null ? ($cycle?->committedAmount() ?? 0) : null,
            'canApprove' => $reach === null && (Auth::user()->canApproveFinance() || Auth::user()->isSuperAdmin()),
        ])->layout('layouts.app', ['title' => 'Increment Center']);
    }

    /** @return array<int, int>|null employee ids in reach; null = company-wide */
    private function reach(): ?array
    {
        return app(ScopeResolver::class)->employeeIds(Auth::user(), 'manage_employees');
    }

    private function assertCompanyWide(): void
    {
        abort_unless($this->reach() === null, 403);
    }

    private function proposalInReach(int $proposalId): IncrementProposal
    {
        $proposal = IncrementProposal::where('increment_cycle_id', $this->cycleId)->findOrFail($proposalId);
        $reach = $this->reach();
        abort_unless($reach === null || in_array((int) $proposal->employee_id, $reach, true), 403);

        return $proposal;
    }

    private function cycle(): IncrementCycle
    {
        return IncrementCycle::findOrFail($this->cycleId);
    }
}
