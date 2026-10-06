<?php

namespace App\Livewire\Settings;

use App\Services\DataTransfer\DataExportService;
use App\Services\DataTransfer\HolidayImportService;
use App\Services\ModuleFeatureService;
use App\Services\SpreadsheetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import / Export centre: one place for HR to download data and bulk-load it.
 *
 * Export needs "Export Data", import needs "Import Data" (both HR Admin by
 * default). Exports are limited to the viewer's reach; imports are
 * preview-first and only ever add valid rows. The existing specialised
 * importers (employees, historical leave, historical payroll) are linked
 * from here for whoever can open them.
 */
class ImportExportCentre extends Component
{
    use WithFileUploads;

    public string $format = 'xlsx';

    public string $from = '';

    public string $to = '';

    public int $year;

    /** @var TemporaryUploadedFile|null */
    public $holidayFile = null;

    public string $holidayFileName = '';

    /** @var array<int, array{line: int, data: array<string, mixed>, errors: array<int, string>}> */
    public array $holidayPreview = [];

    public function mount(): void
    {
        abort_unless($this->canExport() || $this->canImport(), 403);

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->year = (int) now()->year;
    }

    public function export(string $dataset, DataExportService $exports): ?BinaryFileResponse
    {
        abort_unless($this->canExport(), 403);

        $this->validate([
            'format' => ['required', 'in:xlsx,csv'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        try {
            return $exports->download($dataset, Auth::user(), [
                'from' => $this->from, 'to' => $this->to, 'year' => $this->year,
            ], $this->format);
        } catch (\InvalidArgumentException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return null;
        }
    }

    public function downloadHolidayTemplate(SpreadsheetService $spreadsheets): BinaryFileResponse
    {
        abort_unless($this->canImport(), 403);

        return $spreadsheets->download(HolidayImportService::HEADINGS, [
            ['New Year\'s Day', ($this->year + 1).'-01-01', 'national', '', 'UK', 'Yes', 'No', 'Yes', 'Bank holiday'],
            ['Company Foundation Day', '15/03/'.($this->year + 1), 'company', 'Celebration', 'UK', 'Yes', 'No', 'No', ''],
        ], 'holiday-import-template.xlsx');
    }

    /** A new file replaces the previous preview straight away. */
    public function updatedHolidayFile(SpreadsheetService $spreadsheets, HolidayImportService $holidays): void
    {
        abort_unless($this->canImport(), 403);

        $this->holidayPreview = [];
        $this->validate(['holidayFile' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:2048']]);

        $this->holidayFileName = $this->holidayFile->getClientOriginalName();

        try {
            $rows = $spreadsheets->read($this->holidayFile->getRealPath(), $this->holidayFile->getClientOriginalExtension());
        } catch (\Throwable) {
            $this->addError('holidayFile', 'The file could not be read. Save it as .xlsx or .csv from the template.');

            return;
        }

        if ($rows !== [] && ! array_key_exists('name', $rows[0]) && ! array_key_exists('date', $rows[0])) {
            $this->addError('holidayFile', 'The first row must be the template headings (Name, Date, Type …).');

            return;
        }

        $this->holidayPreview = $holidays->preview($rows);

        if ($this->holidayPreview === []) {
            $this->addError('holidayFile', 'The file has no holiday rows.');
        }
    }

    public function importHolidays(HolidayImportService $holidays): void
    {
        abort_unless($this->canImport(), 403);

        if ($this->holidayPreview === []) {
            return;
        }

        $result = $holidays->import($this->holidayPreview, Auth::user(), $this->holidayFileName ?: 'spreadsheet');

        $this->reset(['holidayFile', 'holidayFileName', 'holidayPreview']);

        \Flux::toast("Imported {$result['created']} holiday(s)".($result['skipped'] ? ", skipped {$result['skipped']}" : '').'.', variant: 'success');
    }

    public function clearHolidayPreview(): void
    {
        $this->reset(['holidayFile', 'holidayFileName', 'holidayPreview']);
        $this->resetErrorBag();
    }

    private function canExport(): bool
    {
        return (bool) Auth::user()?->hasPermission('data_export');
    }

    private function canImport(): bool
    {
        return (bool) Auth::user()?->hasPermission('data_import');
    }

    /**
     * The specialised importers that already exist, for whoever can open them.
     *
     * @return array<int, array{label: string, description: string, icon: string, route: string}>
     */
    private function otherImporters(): array
    {
        $user = Auth::user();

        return array_values(array_filter([
            $user->canManageEmployees()
                ? ['label' => 'Employees', 'description' => 'Create or update employees in bulk, with a template and preview', 'icon' => 'users', 'route' => 'employees.import'] : null,
            $user->hasPermission('manage_leave_balances')
                ? ['label' => 'Historical leave balances', 'description' => 'Load leave years kept outside the system', 'icon' => 'calendar-days', 'route' => 'time-off.historical-balances'] : null,
            $user->canRunPayroll() && app(ModuleFeatureService::class)->payrollEnabled()
                ? ['label' => 'Historical payroll', 'description' => 'Bring in past payslips', 'icon' => 'banknotes', 'route' => 'payroll.historical-import'] : null,
        ]));
    }

    public function render()
    {
        $valid = collect($this->holidayPreview)->where('errors', [])->count();

        return view('livewire.settings.import-export-centre', [
            'datasets' => DataExportService::DATASETS,
            'canExport' => $this->canExport(),
            'canImport' => $this->canImport(),
            'otherImporters' => $this->otherImporters(),
            'previewValid' => $valid,
            'previewInvalid' => count($this->holidayPreview) - $valid,
        ])->layout('layouts.app', ['title' => 'Import / Export']);
    }
}
