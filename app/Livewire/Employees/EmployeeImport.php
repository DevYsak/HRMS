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

    public bool $sendWelcome = false;

    /** Create departments/designations/shifts/offices the file refers to but the system lacks. */
    public bool $autoCreateMasterData = true;

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

        // Kept for the post-import validation report, which the preview data
        // is cleared out from under.
        $quality = $this->parsed['quality'] ?? [];

        $log = $service->import(
            $this->parsed,
            $this->mode,
            auth()->user(),
            is_object($this->file) ? $this->file->getClientOriginalName() : null,
            $this->sendWelcome,
            $this->autoCreateMasterData,
        );

        $this->lastResult = [
            'imported' => $log->imported,
            'updated' => $log->updated,
            'skipped' => $log->skipped,
            'failed' => $log->failed,
            'total_rows' => $log->total_rows,
            'log_id' => $log->id,
            'quality' => array_filter($quality),
        ];
        $this->showPreview = false;
        $this->parsed = [];
        $this->reset('file');

        \Flux::toast(
            "Import complete — {$log->imported} added, {$log->updated} updated, {$log->skipped} skipped, {$log->failed} failed.",
            variant: 'success',
        );
    }

    /**
     * Download the validation summary for an import run: the headline counts,
     * the data-quality tallies, every row-level error, and the employees still
     * carrying a data flag so HR knows exactly what to go and fix.
     */
    public function downloadValidationReport(int $logId)
    {
        $this->authorize('create', Employee::class);

        $log = EmployeeImportLog::with('user')->findOrFail($logId);
        // Quality counters only exist in memory for the run just completed;
        // downloading an older run from the history table omits that block.
        $quality = ($this->lastResult['log_id'] ?? null) === $logId
            ? ($this->lastResult['quality'] ?? [])
            : [];

        $filename = 'employee-import-validation-'.$log->created_at->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($log, $quality) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Employee Import — Validation Report']);
            fputcsv($handle, ['File', $log->filename ?? '—']);
            fputcsv($handle, ['Imported by', $log->user?->name ?? '—']);
            fputcsv($handle, ['Run at', $log->created_at->format('d M Y, H:i')]);
            fputcsv($handle, ['Mode', $log->mode]);
            fputcsv($handle, []);

            fputcsv($handle, ['Summary', 'Count']);
            fputcsv($handle, ['Total rows in file', $log->total_rows]);
            fputcsv($handle, ['New employees', $log->imported]);
            fputcsv($handle, ['Updated employees', $log->updated]);
            fputcsv($handle, ['Skipped (already existed)', $log->skipped]);
            fputcsv($handle, ['Failed (validation errors)', $log->failed]);
            fputcsv($handle, ['Successfully imported', $log->imported + $log->updated]);
            fputcsv($handle, []);

            if ($quality !== []) {
                fputcsv($handle, ['Data quality', 'Count']);
                foreach ($quality as $key => $count) {
                    fputcsv($handle, [ucfirst(str_replace('_', ' ', $key)), $count]);
                }
                fputcsv($handle, []);
            }

            if ($log->errors) {
                fputcsv($handle, ['Validation errors']);
                fputcsv($handle, ['Sheet row', 'Problem']);
                foreach ($log->errors as $entry) {
                    foreach ($entry['messages'] ?? [] as $message) {
                        fputcsv($handle, [$entry['row'] ?? '—', $message]);
                    }
                }
                fputcsv($handle, []);
            }

            // Everyone still needing HR attention, regardless of which run
            // brought them in — this is the actionable to-do list.
            $flagged = Employee::with(['user', 'department'])
                ->where(fn ($q) => $q->where('has_placeholder_email', true)->orWhereNull('joining_date'))
                ->get();

            fputcsv($handle, ['Employees needing data completion', $flagged->count()]);
            fputcsv($handle, ['Employee ID', 'Name', 'Department', 'Email', 'Joining Date', 'Flags']);
            foreach ($flagged as $employee) {
                fputcsv($handle, [
                    $employee->employee_id,
                    $employee->user?->name,
                    $employee->department?->name,
                    $employee->user?->email,
                    $employee->joining_date?->toDateString() ?? '',
                    implode('; ', $employee->dataFlags()),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        return view('livewire.employees.employee-import', [
            'recentLogs' => EmployeeImportLog::with('user')->latest()->limit(10)->get(),
        ])->layout('layouts.app', ['title' => 'Import Employees']);
    }
}
