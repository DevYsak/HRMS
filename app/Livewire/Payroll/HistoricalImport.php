<?php

namespace App\Livewire\Payroll;

use App\Models\PayrollImportLog;
use App\Services\PayrollHistoricalImportService;
use App\Services\SpreadsheetService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Phase 5: bulk-import pre-Pulse payslip history from a spreadsheet — parse
 * → preview → import → log, mirroring EmployeeImport's exact shape.
 */
class HistoricalImport extends Component
{
    use WithFileUploads;

    public $file;

    /** @var array{rows:array, summary:array} */
    public array $parsed = [];

    public bool $showPreview = false;

    public ?array $lastResult = null;

    public function downloadTemplate(SpreadsheetService $sheets, PayrollHistoricalImportService $service)
    {
        return $sheets->download($service->templateHeadings(), [$service->sampleRow()], 'historical-payslip-import-template.xlsx');
    }

    public function downloadTemplateCsv(SpreadsheetService $sheets, PayrollHistoricalImportService $service)
    {
        return $sheets->download($service->templateHeadings(), [$service->sampleRow()], 'historical-payslip-import-template.csv');
    }

    public function analyze(PayrollHistoricalImportService $service, SpreadsheetService $sheets): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ]);

        $rows = $sheets->read($this->file->getRealPath(), $this->file->getClientOriginalExtension());

        $this->parsed = $service->parse($rows);
        $this->showPreview = true;
        $this->lastResult = null;
    }

    public function runImport(PayrollHistoricalImportService $service): void
    {
        if (empty($this->parsed['rows'])) {
            \Flux::toast('Analyze a file before importing.', variant: 'warning');

            return;
        }

        $log = $service->import(
            $this->parsed,
            auth()->user(),
            is_object($this->file) ? $this->file->getClientOriginalName() : null,
        );

        $this->lastResult = [
            'imported' => $log->imported,
            'skipped' => $log->skipped,
            'failed' => $log->failed,
        ];
        $this->showPreview = false;
        $this->parsed = [];
        $this->reset('file');

        \Flux::toast(
            "Import complete — {$log->imported} added, {$log->skipped} skipped (already exist), {$log->failed} failed.",
            variant: 'success',
        );
    }

    public function render()
    {
        return view('livewire.payroll.historical-import', [
            'recentLogs' => PayrollImportLog::with('user')->latest()->limit(10)->get(),
        ])->layout('layouts.app', ['title' => 'Historical Payroll Import']);
    }
}
