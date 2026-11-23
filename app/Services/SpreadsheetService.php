<?php

namespace App\Services;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Thin wrapper around openspout for reading/writing xlsx & csv. Keeps the Excel
 * library isolated to one place so the rest of the app deals in plain arrays.
 */
class SpreadsheetService
{
    /**
     * Stream an xlsx/csv download built from a heading row and data rows.
     * Format is inferred from the filename extension.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function download(array $headings, array $rows, string $filename): BinaryFileResponse
    {
        $isCsv = str_ends_with(strtolower($filename), '.csv');
        $tmp = tempnam(sys_get_temp_dir(), 'sheet_');

        $writer = $isCsv ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', array_values($row))));
        }
        $writer->close();

        return response()
            ->download($tmp, $filename)
            ->deleteFileAfterSend(true);
    }

    /**
     * Stream an xlsx workbook of several sheets. Each sheet gets a bold,
     * frozen heading row with filters, and column widths when given.
     *
     * @param  list<array{name: string, headings: list<string>, rows: list<list<mixed>>, widths?: list<float>}>  $sheets
     */
    public function downloadWorkbook(array $sheets, string $filename): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'book_');
        $heading = (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('F97316');

        $writer = new XlsxWriter;
        $writer->openToFile($tmp);

        foreach (array_values($sheets) as $index => $sheet) {
            $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            // Excel caps a sheet name at 31 characters and refuses : \ / ? * [ ].
            $current->setName(Str::limit(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $sheet['name']), 31, ''));
            $current->setSheetView((new SheetView)->withFreezeRow(2));

            foreach ($sheet['widths'] ?? [] as $column => $width) {
                $current->setColumnWidth($width, $column + 1);
            }

            $writer->addRow(Row::fromValuesWithStyle($sheet['headings'], $heading));
            foreach ($sheet['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', array_values($row))));
            }

            if ($sheet['rows'] !== []) {
                $current->setAutoFilter(new AutoFilter(0, 1, count($sheet['headings']) - 1, count($sheet['rows']) + 1));
            }
        }

        $writer->close();

        return response()
            ->download($tmp, $filename)
            ->deleteFileAfterSend(true);
    }

    /**
     * Read a spreadsheet into associative rows keyed by the slugged header.
     *
     * @return array<int, array<string, string>>
     */
    public function read(string $path, string $extension): array
    {
        $reader = in_array(strtolower($extension), ['csv', 'txt'], true) ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $headers = null;
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                if ($headers === null) {
                    $headers = array_map(fn ($h) => Str::slug((string) $h, '_'), $cells);

                    continue;
                }

                $assoc = [];
                foreach ($headers as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $assoc[$key] = $this->cellToString($cells[$i] ?? '');
                }
                $out[] = $assoc;
            }
            break; // first sheet only
        }

        $reader->close();

        return $out;
    }

    /**
     * A cell as text. Date-formatted xlsx cells arrive as DateTime objects,
     * which a plain string cast cannot handle: a date becomes Y-m-d, a
     * time-only cell (Excel's 1899 epoch) H:i, anything else Y-m-d H:i.
     */
    private function cellToString(mixed $cell): string
    {
        if ($cell instanceof \DateTimeInterface) {
            if ((int) $cell->format('Y') < 1901) {
                return $cell->format('H:i');
            }

            return $cell->format('H:i:s') === '00:00:00' ? $cell->format('Y-m-d') : $cell->format('Y-m-d H:i');
        }

        if ($cell instanceof \DateInterval) {
            return $cell->format('%H:%I');
        }

        return (string) $cell;
    }
}
