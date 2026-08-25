<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollImportLog;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Parses and imports historical (pre-Pulse) payslip data from a spreadsheet.
 *
 * Mirrors EmployeeImportService's shape (parse → preview → import → log) and
 * stays free of any Excel-library dependency — SpreadsheetService is the only
 * piece that touches openspout.
 *
 * Unlike a real payroll run, this never computes anything: every figure comes
 * from the file. Rows are written straight to `finalized`/`paid` because
 * they represent pay that already happened.
 */
class PayrollHistoricalImportService
{
    /** Marks a payroll as created by this import, so a later run for the same
     * period is allowed to add more employees to it. A payroll WITHOUT this
     * tag is a real run and is never touched — mirrors DemoPayrollHistorySeeder's
     * "never modify a payroll it did not create" rule. */
    public const IMPORT_TAG = 'HISTORICAL IMPORT - created via Payroll > Historical Import.';

    public const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    public const COLUMNS = [
        'employee_id', 'email', 'month', 'year', 'cycle',
        'basic', 'hra', 'special_allowance', 'conveyance', 'medical', 'other_earnings',
        'pf', 'esi', 'professional_tax', 'tds', 'other_deductions',
        'gross_salary', 'total_deductions', 'net_salary',
    ];

    public function templateHeadings(): array
    {
        return [
            'Employee ID', 'Email', 'Month', 'Year', 'Cycle',
            'Basic', 'HRA', 'Special Allowance', 'Conveyance', 'Medical', 'Other Earnings',
            'PF', 'ESI', 'Professional Tax', 'TDS', 'Other Deductions',
            'Gross Salary', 'Total Deductions', 'Net Salary',
        ];
    }

    public function sampleRow(): array
    {
        return [
            'EMP-0017', '', 'January', '2026', 'cycle_a',
            '20000', '10000', '5000', '1600', '1250', '',
            '2400', '', '200', '', '',
            '37850', '2600', '35250',
        ];
    }

    /**
     * Validate + resolve raw rows into a preview.
     *
     * @param  array<int, array<string, mixed>>  $rows  keyed by slugged heading
     * @return array{rows: array<int, array{line:int, status:string, errors:array<int,string>, data:array}>, summary: array<string,int>}
     */
    public function parse(array $rows): array
    {
        $byEmployeeId = Employee::query()->whereNotNull('employee_id')->pluck('id', 'employee_id')
            ->mapWithKeys(fn ($id, $empId) => [(string) $empId => $id]);
        $byEmail = Employee::query()->with('user')->get()
            ->filter(fn (Employee $e) => $e->user?->email)
            ->mapWithKeys(fn (Employee $e) => [Str::lower($e->user->email) => $e->id]);

        $existingPayslipKeys = Payslip::query()
            ->join('payrolls', 'payrolls.id', '=', 'payslips.payroll_id')
            ->get(['payslips.employee_id', 'payrolls.month', 'payrolls.year'])
            ->map(fn ($row) => $row->employee_id.'|'.$row->month.'|'.$row->year)
            ->flip();

        $seenInFile = [];
        $out = [];
        $summary = ['total' => 0, 'new' => 0, 'duplicate' => 0, 'error' => 0];

        foreach ($rows as $i => $raw) {
            $r = $this->normalize($raw);
            if ($this->isBlank($r)) {
                continue;
            }

            $summary['total']++;
            $line = $i + 2;
            $errors = [];

            $employeeId = null;
            $empIdValue = trim($r['employee_id']);
            $emailValue = Str::lower(trim($r['email']));

            if ($empIdValue !== '' && $byEmployeeId->has($empIdValue)) {
                $employeeId = $byEmployeeId[$empIdValue];
            } elseif ($emailValue !== '' && $byEmail->has($emailValue)) {
                $employeeId = $byEmail[$emailValue];
            } else {
                $errors[] = $empIdValue === '' && $emailValue === ''
                    ? 'Employee ID or Email is required.'
                    : "No matching employee found for '".($empIdValue !== '' ? $empIdValue : $emailValue)."'.";
            }

            $month = ucfirst(Str::lower(trim($r['month'])));
            if ($month === '') {
                $errors[] = 'Month is required.';
            } elseif (! in_array($month, self::MONTHS, true)) {
                $errors[] = "Month '{$r['month']}' is not recognised.";
            }

            $year = trim($r['year']);
            if ($year === '' || ! ctype_digit($year) || (int) $year < 2000 || (int) $year > 2100) {
                $errors[] = 'Year must be a valid 4-digit year.';
            }

            $cycle = trim($r['cycle']) !== '' ? Str::lower(trim($r['cycle'])) : 'cycle_a';
            if (! in_array($cycle, ['cycle_a', 'cycle_b'], true)) {
                $errors[] = "Cycle '{$r['cycle']}' must be cycle_a or cycle_b.";
            }

            $earnings = $this->numericLine($r, ['basic' => 'Basic', 'hra' => 'HRA', 'special_allowance' => 'Special Allowance', 'conveyance' => 'Conveyance', 'medical' => 'Medical', 'other_earnings' => 'Other Earnings'], $errors);
            $deductions = $this->numericLine($r, ['pf' => 'PF', 'esi' => 'ESI', 'professional_tax' => 'Professional Tax', 'tds' => 'TDS', 'other_deductions' => 'Other Deductions'], $errors);

            $grossInput = trim($r['gross_salary']);
            $deductionsInput = trim($r['total_deductions']);
            $netInput = trim($r['net_salary']);

            foreach (['gross_salary' => $grossInput, 'total_deductions' => $deductionsInput, 'net_salary' => $netInput] as $label => $value) {
                if ($value !== '' && ! is_numeric($value)) {
                    $errors[] = str_replace('_', ' ', ucfirst($label)).' must be a number.';
                }
            }

            $gross = $grossInput !== '' && is_numeric($grossInput) ? (float) $grossInput : round(array_sum($earnings), 2);
            $totalDeductions = $deductionsInput !== '' && is_numeric($deductionsInput) ? (float) $deductionsInput : round(array_sum($deductions), 2);
            $net = $netInput !== '' && is_numeric($netInput) ? (float) $netInput : round($gross - $totalDeductions, 2);

            if ($gross <= 0 && empty($errors)) {
                $errors[] = 'Provide either Gross Salary or at least one earning line item.';
            }

            $dedupeKey = $employeeId.'|'.$month.'|'.$year;
            $isDuplicate = empty($errors) && ($existingPayslipKeys->has($dedupeKey) || isset($seenInFile[$dedupeKey]));
            if (! empty($errors)) {
                $status = 'error';
            } elseif ($isDuplicate) {
                $status = 'duplicate';
            } else {
                $status = 'new';
                $seenInFile[$dedupeKey] = $line;
            }

            $summary[$status]++;

            $out[] = [
                'line' => $line,
                'status' => $status,
                'errors' => $errors,
                'data' => [
                    'employee_id' => $employeeId,
                    'employee_label' => $empIdValue !== '' ? $empIdValue : $emailValue,
                    'month' => $month,
                    'year' => $year !== '' ? (int) $year : null,
                    'cycle' => $cycle,
                    'earnings' => array_filter($earnings, fn ($v) => $v > 0),
                    'deductions' => array_filter($deductions, fn ($v) => $v > 0),
                    'gross_salary' => $gross,
                    'total_deductions' => $totalDeductions,
                    'net_salary' => $net,
                ],
            ];
        }

        return ['rows' => $out, 'summary' => $summary];
    }

    /**
     * Persist a parsed preview. Only 'new' rows are written; 'duplicate' rows
     * are skipped and 'error' rows are counted as failed. One payroll is
     * reused per (month, year, cycle) group, created only if this import
     * "owns" it or none exists yet.
     */
    public function import(array $parsed, User $actor, ?string $filename = null): PayrollImportLog
    {
        $imported = 0;
        $skipped = 0;
        $errorLog = [];

        foreach ($parsed['rows'] as $row) {
            if ($row['status'] === 'error') {
                $errorLog[] = ['row' => $row['line'], 'messages' => $row['errors']];
            } elseif ($row['status'] === 'duplicate') {
                $skipped++;
            }
        }

        $newRows = Collection::make($parsed['rows'])->filter(fn ($row) => $row['status'] === 'new');

        try {
            DB::transaction(function () use ($newRows, $actor, &$imported, &$errorLog) {
                $payrollCache = [];

                foreach ($newRows as $row) {
                    $data = $row['data'];
                    $periodKey = $data['month'].'|'.$data['year'].'|'.$data['cycle'];

                    if (! isset($payrollCache[$periodKey])) {
                        $payroll = $this->resolvePayroll($data['month'], $data['year'], $data['cycle'], $actor);

                        if (! $payroll) {
                            $errorLog[] = [
                                'row' => $row['line'],
                                'messages' => ["A live payroll already exists for {$data['month']} {$data['year']} ({$data['cycle']}) — historical import cannot add to a real run."],
                            ];

                            continue;
                        }

                        $payrollCache[$periodKey] = $payroll;
                    }

                    $this->writePayslip($payrollCache[$periodKey], $data);
                    $imported++;
                }

                foreach ($payrollCache as $payroll) {
                    $payroll->update([
                        'total_payout' => round((float) $payroll->payslips()->sum('net_salary'), 2),
                        'deductions' => round((float) $payroll->payslips()->sum('total_deductions'), 2),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return PayrollImportLog::create([
                'user_id' => $actor->id,
                'filename' => $filename,
                'total_rows' => $parsed['summary']['total'] ?? count($parsed['rows']),
                'imported' => 0,
                'skipped' => 0,
                'failed' => count($parsed['rows']),
                'errors' => [['row' => 0, 'messages' => ['Import rolled back: '.$e->getMessage()]]],
            ]);
        }

        // $errorLog may have grown inside the transaction (rows that hit a
        // "real payroll exists" conflict), so recount rather than trust the
        // pre-transaction $failed tally.
        $failed = count($errorLog);

        return PayrollImportLog::create([
            'user_id' => $actor->id,
            'filename' => $filename,
            'total_rows' => $parsed['summary']['total'] ?? count($parsed['rows']),
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errorLog ?: null,
        ]);
    }

    /** Reuse an import-owned payroll for this period, create one if none exists, or refuse if a real one is there. */
    private function resolvePayroll(string $month, int $year, string $cycle, User $actor): ?Payroll
    {
        $existing = Payroll::where('month', $month)->where('year', $year)->where('cycle', $cycle)->first();

        if ($existing) {
            return $existing->finance_note === self::IMPORT_TAG ? $existing : null;
        }

        return Payroll::create([
            'month' => $month,
            'year' => $year,
            'cycle' => $cycle,
            'status' => 'finalized',
            'processed_by' => $actor->id,
            'processed_at' => now(),
            'finance_approved_by' => $actor->id,
            'finance_approved_at' => now(),
            'finance_note' => self::IMPORT_TAG,
        ]);
    }

    private function writePayslip(Payroll $payroll, array $data): void
    {
        $payslip = Payslip::create([
            'payroll_id' => $payroll->id,
            'employee_id' => $data['employee_id'],
            'gross_salary' => $data['gross_salary'],
            'total_deductions' => $data['total_deductions'],
            'net_salary' => $data['net_salary'],
            'status' => 'paid',
        ]);

        $earnings = $data['earnings'];
        if (empty($earnings)) {
            $earnings = ['Historical Gross' => $data['gross_salary']];
        }
        foreach ($earnings as $name => $amount) {
            PayslipItem::create(['payslip_id' => $payslip->id, 'name' => $name, 'amount' => $amount, 'type' => 'earning']);
        }

        foreach ($data['deductions'] as $name => $amount) {
            PayslipItem::create(['payslip_id' => $payslip->id, 'name' => $name, 'amount' => $amount, 'type' => 'deduction']);
        }
    }

    /** @return array<string, float> label => amount, reading the given column keys off $r */
    private function numericLine(array $r, array $columns, array &$errors): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $value = trim($r[$key]);
            if ($value === '') {
                $out[$label] = 0.0;

                continue;
            }
            if (! is_numeric($value)) {
                $errors[] = "{$label} must be a number.";
                $out[$label] = 0.0;

                continue;
            }
            $out[$label] = (float) $value;
        }

        return $out;
    }

    /** @return array<string, string> every column key present and string-trimmed */
    private function normalize(array $raw): array
    {
        $out = [];
        foreach (self::COLUMNS as $key) {
            $out[$key] = trim((string) ($raw[$key] ?? ''));
        }

        return $out;
    }

    private function isBlank(array $normalized): bool
    {
        return implode('', $normalized) === '';
    }
}
