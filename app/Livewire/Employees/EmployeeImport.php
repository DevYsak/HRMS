<?php

namespace App\Livewire\Employees;

use App\Exports\EmployeesExport;
use App\Exports\EmployeeTemplateExport;
use App\Models\Employee;
use App\Models\EmployeeImportLog;
use App\Services\EmployeeImportService;
use App\Services\SpreadsheetService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Bulk employee import: download template / export existing, upload → validate
 * → preview → import (skip or update existing), with an import-log history.
 * (Phase 2 — Feature 2.)
 */
class EmployeeImport extends Component
{
    use WithFileUploads;

    public $file;

    public string $mode = 'skip';           // skip | update

    public bool $sendWelcome = true;

    /** @var array{rows:array, summary:array} */
    public array $parsed = [];

    public bool $showPreview = false;

    public ?array $lastResult = null;

    public function mount(): void
    {
        $this->authorize('create', Employee::class);
    }

    public function downloadTemplate(SpreadsheetService $sheets)
    {
        $export = app(EmployeeTemplateExport::class);

        return $sheets->download($export->headings(), $export->rows(), 'employee-import-template.xlsx');
    }

    public function downloadTemplateCsv(SpreadsheetService $sheets)
    {
        $export = app(EmployeeTemplateExport::class);

        return $sheets->download($export->headings(), $export->rows(), 'employee-import-template.csv');
    }

    public function exportEmployees(SpreadsheetService $sheets)
    {
        $export = new EmployeesExport;

        return $sheets->download($export->headings(), $export->rows(), 'employees-'.now()->format('Ymd').'.xlsx');
    }

    public function analyze(EmployeeImportService $service, SpreadsheetService $sheets): void
    {
        $this->authorize('create', Employee::class);
        $this->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ]);

        $rows = $sheets->read($this->file->getRealPath(), $this->file->getClientOriginalExtension());

        $this->parsed = $service->parse($rows);
        $this->showPreview = true;
        $this->lastResult = null;
    }

    public function runImport(EmployeeImportService $service): void
    {
        $this->authorize('create', Employee::class);

        if (empty($this->parsed['rows'])) {
            \Flux::toast('Analyze a file before importing.', variant: 'warning');

            return;
        }

        $log = $service->import(
            $this->parsed,
            $this->mode,
            auth()->user(),
            is_object($this->file) ? $this->file->getClientOriginalName() : null,
            $this->sendWelcome,
        );

        $this->lastResult = [
            'imported' => $log->imported,
            'updated' => $log->updated,
            'skipped' => $log->skipped,
            'failed' => $log->failed,
        ];
        $this->showPreview = false;
        $this->parsed = [];
        $this->reset('file');

        \Flux::toast(
            "Import complete — {$log->imported} added, {$log->updated} updated, {$log->skipped} skipped, {$log->failed} failed.",
            variant: 'success',
        );
    }

    public function render()
    {
        return view('livewire.employees.employee-import', [
            'recentLogs' => EmployeeImportLog::with('user')->latest()->limit(10)->get(),
        ])->layout('layouts.app', ['title' => 'Import Employees']);
    }
}
