<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveBulkRun;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveRolloverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * HR → Leave Management → Year Rollover (Phase 2C/2D).
 *
 * Preview → confirm. SAFE rows process in one action; NEEDS_HR_REVIEW rows
 * are resolved one by one with an explicit carry amount and reason; nothing
 * is processed twice. Export gives the same preview as CSV.
 */
class LeaveYearRollover extends Component
{
    public ?int $fromYearId = null;

    public string $statusFilter = '';

    /** Review row being resolved. */
    public ?int $reviewBalanceId = null;

    public string $reviewCarry = '';

    public string $reviewReason = '';

    /** @var array<string, mixed>|null */
    public ?array $lastRun = null;

    public function mount(LeaveYearResolver $years): void
    {
        $this->authorize('run_leave_rollover');

        $this->fromYearId = $years->previous($years->current())->id;
    }

    #[Computed]
    public function fromYear(): LeaveYear
    {
        return LeaveYear::findOrFail($this->fromYearId);
    }

    #[Computed]
    public function toYear(): LeaveYear
    {
        return app(LeaveYearResolver::class)->next($this->fromYear);
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        return app(LeaveRolloverService::class)->preview($this->fromYear, $this->toYear);
    }

    public function processSafe(): void
    {
        $this->authorize('run_leave_rollover');

        try {
            $result = app(LeaveRolloverService::class)->process($this->fromYear, $this->toYear, Auth::user(), trigger: 'hr_screen');
            $this->lastRun = collect($result)->except('run')->all() + ['run_id' => $result['run']->id];
            session()->flash('success', "Processed {$result['processed']} row(s); {$result['needs_review']} need review, {$result['failed']} failed, {$result['provisioned']} new-year entitlement(s) provisioned.");
        } catch (Throwable $e) {
            report($e);
            session()->flash('error', 'Rollover stopped: '.$e->getMessage());
        }

        unset($this->rows);
    }

    public function startReview(int $balanceId, float $suggested): void
    {
        $this->authorize('run_leave_rollover');

        $this->reviewBalanceId = $balanceId;
        $this->reviewCarry = (string) $suggested;
        $this->reviewReason = '';
        $this->resetErrorBag();
    }

    public function resolveReview(): void
    {
        $this->authorize('run_leave_rollover');

        $this->validate([
            'reviewCarry' => ['required', 'numeric', 'min:0'],
            'reviewReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            app(LeaveRolloverService::class)->resolveReview(
                LeaveBalance::findOrFail($this->reviewBalanceId), (float) $this->reviewCarry, Auth::user(), $this->reviewReason,
            );
            session()->flash('success', 'Row resolved and rolled over.');
            $this->reviewBalanceId = null;
        } catch (\DomainException $e) {
            $this->addError('reviewCarry', $e->getMessage());
        }

        unset($this->rows);
    }

    public function export(): StreamedResponse
    {
        $this->authorize('export_leave');

        $rows = $this->rows;
        $name = 'leave-rollover-'.str_replace('/', '-', $this->fromYear->label).'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'Code', 'Department', 'Leave Type', 'Closing', 'Carry', 'Expire', 'New Base', 'New Opening', 'Carry Expires', 'Status', 'Reason']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['employee'], $r['employee_code'], $r['department'], $r['leave_type'], $r['closing'], $r['carry'], $r['expire'],
                    $r['new_base'], $r['new_opening'], $r['expires_on'], $r['status'], $r['reason']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        $rows = $this->rows;

        return view('livewire.time-off.leave-year-rollover', [
            'visible' => $this->statusFilter ? $rows->where('status', $this->statusFilter)->values() : $rows,
            'counts' => [
                'SAFE' => $rows->where('status', LeaveRolloverService::SAFE)->count(),
                'NEEDS_HR_REVIEW' => $rows->where('status', LeaveRolloverService::NEEDS_HR_REVIEW)->count(),
                'BLOCKED' => $rows->where('status', LeaveRolloverService::BLOCKED)->count(),
                'PROCESSED' => $rows->where('status', LeaveRolloverService::PROCESSED)->count(),
            ],
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            'runs' => LeaveBulkRun::with('creator')->where('kind', LeaveBulkRun::KIND_ROLLOVER)
                ->where('leave_year_id', $this->fromYearId)->latest('id')->limit(10)->get(),
        ]);
    }
}
