<?php

namespace App\Exports;

use App\Services\EmployeeImportService;

/**
 * Blank import template: column headings and one illustrative sample row.
 * Rendered to a file by SpreadsheetService.
 */
class EmployeeTemplateExport
{
    public function __construct(private EmployeeImportService $service) {}

    /** @return array<int, string> */
    public function headings(): array
    {
        return $this->service->templateHeadings();
    }

    /** @return array<int, array<int, mixed>> */
    public function rows(): array
    {
        return [$this->service->sampleRow()];
    }
}
