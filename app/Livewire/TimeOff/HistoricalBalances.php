<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveBalance;
use App\Models\LeaveYear;
use App\Services\Leave\HistoricalLeaveBalanceImportService;
use App\Services\SpreadsheetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Bulk migration of closed leave years: upload, look at what it would do,
 * then write it.
 *
 * The preview is the point. Most of these years reach us as a closing balance
 * and nothing else, and the screen has to say so plainly — "Not Available"
 * where a figure is missing, "Awaiting HR Decision" where that means no
 * carry-forward amount can be derived. Nothing is written until HR has seen
 * that, and nothing that already exists is overwritten.
 */
class HistoricalBalances extends Component
{
    use WithFileUploads;

    public $file;

    /**
     * Display-only preview. Locked so the browser cannot edit it, and the
     * import re-validates from the uploaded values regardless.
     *
     * @var array{rows:array, summary:array}|array{}
     */
    #[Locked]
    public array $parsed = [];

    public bool $showPreview = false;

    public ?array $lastResult = null;

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('manage_leave_balances');
    }

    public function downloadTemplate(SpreadsheetService $sheets, HistoricalLeaveBalanceImportService $service)
    {
        $this->authorize('manage_leave_balances');

        return $sheets->download(
            $service->templateHeadings(),
            $service->sampleRows(),
            'historical-leave-balances-template.xlsx',
        );
    }

    public function analyze(HistoricalLeaveBalanceImportService $service, SpreadsheetService $sheets): void
    {
        $this->authorize('manage_leave_balances');

        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ]);

        $rows = $sheets->read($this->file->getRealPath(), $this->file->getClientOriginalExtension());

        $this->parsed = $service->parse($rows);
        $this->showPreview = true;
        $this->lastResult = null;
    }

    public function runImport(HistoricalLeaveBalanceImportService $service): void
    {
        $this->authorize('manage_leave_balances');

        if (empty($this->parsed['rows'])) {
            \Flux::toast('Analyse a file before importing.', variant: 'warning');

            return;
        }

        $this->lastResult = $service->import($this->parsed, Auth::user());

        $this->parsed = [];
        $this->showPreview = false;
        $this->file = null;

        $awaiting = $this->lastResult['awaiting_decision'];

        \Flux::toast(
            "Imported {$this->lastResult['imported']} balance(s), skipped {$this->lastResult['skipped']}."
            .($awaiting > 0 ? " {$awaiting} await an HR carry-forward decision." : ''),
            variant: 'success',
        );
    }

    public function render()
    {
        $rows = collect($this->parsed['rows'] ?? [])
            ->when($this->statusFilter !== '', fn ($c) => $c->where('status', $this->statusFilter));

        return view('livewire.time-off.historical-balances', [
            'rows' => $rows,
            'summary' => $this->parsed['summary'] ?? [],
            'years' => LeaveYear::orderByDesc('starts_on')->get(),
            'importedCount' => LeaveBalance::whereNotNull('leave_year_id')->count(),
        ])->layout('layouts.app', ['title' => 'Historical Leave Balances']);
    }
}
