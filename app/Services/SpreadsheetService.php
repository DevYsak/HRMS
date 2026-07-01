<?php

namespace App\Services;

use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
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
                    $assoc[$key] = (string) ($cells[$i] ?? '');
                }
                $out[] = $assoc;
            }
            break; // first sheet only
        }

        $reader->close();

        return $out;
    }
}
