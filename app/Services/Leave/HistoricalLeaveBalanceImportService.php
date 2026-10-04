<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Services\LeaveBalanceService;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import of what closed leave years actually ended at.
 *
 * The years we are migrating were not kept in this system, and for most of
 * them HR holds a closing balance and nothing else — no usage, no encashment.
 * The importer's whole job is to carry that shape through intact: a missing
 * "used" arrives as unknown and is stored as unknown. Writing zero there
 * would be the system asserting the employee took no leave that year, which
 * nobody knows, and it would then let the engine derive a carry-forward
 * figure from an invented input.
 *
 * Nothing here calculates carry forward. An imported year is a record of the
 * past; what moves into the new year is a decision HR makes afterwards on the
 * Carry Forward screen, against these figures.
 *
 * @see LeaveCarryForwardService for the decision that follows
 */
class HistoricalLeaveBalanceImportService
{
    /** Row is complete and an eligible amount can be derived from it. */
    public const STATUS_KNOWN = 'known';

    /** Closing balance recorded, usage not — HR must state the amount. */
    public const STATUS_AWAITING_HR = 'awaiting_hr_decision';

    /** A balance for this employee/type/year already exists. */
    public const STATUS_DUPLICATE = 'duplicate';

    /** The file contradicts itself, or contradicts what is already stored. */
    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_INVALID = 'invalid';

    /** Accepted spellings for "we do not have this figure". */
    private const UNKNOWN_TOKENS = ['', 'n/a', 'na', 'not available', 'unknown', '-', '—', 'null'];

    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * @return array<int, string>
     */
    public function templateHeadings(): array
    {
        return ['employee_code', 'leave_year', 'leave_type', 'closing_balance', 'used', 'encashed', 'remarks'];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function sampleRows(): array
    {
        return [
            ['CNS018', '2025/26', 'AL', '10', 'Not Available', 'Not Available', 'Closing balance only'],
            ['CNS021', '2025/26', 'AL', '28', '10', '0', 'Full figures from the HR sheet'],
        ];
    }

    /**
     * Classify every row without writing anything.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function parse(array $rows): array
    {
        $summary = [
            self::STATUS_KNOWN => 0,
            self::STATUS_AWAITING_HR => 0,
            self::STATUS_DUPLICATE => 0,
            self::STATUS_CONFLICT => 0,
            self::STATUS_INVALID => 0,
        ];

        $out = [];
        $seen = [];
        $line = 1;

        foreach ($rows as $raw) {
            $line++;
            $r = $this->normalize($raw);

            if ($this->isBlank($r)) {
                continue;
            }

            $errors = [];

            $employee = $this->resolveEmployee($r['employee_code'] ?? '', $errors);
            $year = $this->resolveYear($r['leave_year'] ?? '', $errors);
            $type = $this->resolveType($r['leave_type'] ?? '', $errors);

            $closing = $this->number($r['closing_balance'] ?? '', 'closing_balance', $errors, required: true);
            $used = $this->optionalNumber($r['used'] ?? '', 'used', $errors);
            $encashed = $this->optionalNumber($r['encashed'] ?? '', 'encashed', $errors);

            // A year is only coherent if what was taken fits inside what was
            // held. Checked before status so a contradictory row is never
            // presented as importable.
            $conflict = null;
            if ($errors === [] && $closing !== null && $used !== null && $used > $closing) {
                $conflict = "Used ({$used}) exceeds the closing balance ({$closing}).";
            }

            $key = ($employee?->id ?? '?').'|'.($type?->id ?? '?').'|'.($year?->id ?? '?');
            $duplicateInFile = isset($seen[$key]);
            $duplicateStored = $employee && $type && $year
                && LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('year', $year->legacyYear())
                    ->exists();

            $status = match (true) {
                $errors !== [] => self::STATUS_INVALID,
                $conflict !== null => self::STATUS_CONFLICT,
                $duplicateInFile || $duplicateStored => self::STATUS_DUPLICATE,
                // The distinction the whole workflow turns on: with no usage
                // figure there is nothing to derive an eligible amount from.
                $used === null || $encashed === null => self::STATUS_AWAITING_HR,
                default => self::STATUS_KNOWN,
            };

            if ($conflict !== null) {
                $errors[] = $conflict;
            }

            if ($duplicateStored) {
                $errors[] = 'A balance is already recorded for this employee, type and year.';
            } elseif ($duplicateInFile) {
                $errors[] = 'This employee, type and year appear earlier in the file, on line '.$seen[$key].'.';
            }

            if ($status !== self::STATUS_INVALID && $status !== self::STATUS_CONFLICT && ! $duplicateStored) {
                $seen[$key] = $line;
            }

            $summary[$status]++;

            $out[] = [
                'line' => $line,
                // The uploaded values exactly as read. import() re-validates
                // from these, so nothing the preview concluded is trusted.
                'source' => $raw,
                'status' => $status,
                'errors' => $errors,
                'data' => [
                    'employee_id' => $employee?->id,
                    'employee_name' => $employee?->user?->name ?? ($r['employee_code'] ?? ''),
                    'employee_code' => $r['employee_code'] ?? '',
                    'leave_year_id' => $year?->id,
                    'leave_year_label' => $year?->label ?? ($r['leave_year'] ?? ''),
                    'leave_type_id' => $type?->id,
                    'leave_type_code' => $type?->code ?? ($r['leave_type'] ?? ''),
                    'closing_balance' => $closing,
                    'used' => $used,
                    'encashed' => $encashed,
                    // What the screen shows where a figure is absent. Never a
                    // zero standing in for one.
                    'used_label' => $used === null ? 'Not Available' : (string) $used,
                    'encashed_label' => $encashed === null ? 'Not Available' : (string) $encashed,
                    'remarks' => $r['remarks'] ?? '',
                ],
            ];
        }

        return ['rows' => $out, 'summary' => $summary];
    }

    /**
     * Write the rows worth writing. Duplicates, conflicts and invalid rows are
     * skipped — never overwritten, never guessed at.
     *
     * @param  array{rows: array<int, array<string, mixed>>}  $parsed
     * @return array{imported: int, skipped: int, awaiting_decision: int}
     */
    public function import(array $parsed, User $actor): array
    {
        $imported = 0;
        $skipped = 0;
        $awaiting = 0;

        // Never trust a preview. Statuses, ids and figures are re-derived from
        // the uploaded values with exactly the rules the preview used — and
        // against the database as it is now — so a tampered or stale preview
        // cannot carry a rejected, duplicate or current-year row through.
        $parsed = $this->parse(array_map(fn (array $row) => (array) ($row['source'] ?? []), $parsed['rows'] ?? []));

        DB::transaction(function () use ($parsed, $actor, &$imported, &$skipped, &$awaiting) {
            foreach ($parsed['rows'] as $row) {
                if (! in_array($row['status'], [self::STATUS_KNOWN, self::STATUS_AWAITING_HR], true)) {
                    $skipped++;

                    continue;
                }

                $d = $row['data'];

                // HR does not import their own balance; another HR user must.
                if ((int) Employee::whereKey($d['employee_id'])->value('user_id') === (int) $actor->id) {
                    $skipped++;

                    continue;
                }

                $this->balances->setHistoricalBalance(
                    Employee::findOrFail($d['employee_id']),
                    LeaveType::findOrFail($d['leave_type_id']),
                    LeaveYear::findOrFail($d['leave_year_id']),
                    (float) $d['closing_balance'],
                    // Null travels all the way through: unknown is recorded as
                    // unknown, not resolved to zero on the way in.
                    $d['used'],
                    $d['encashed'],
                    'Historical balance import',
                    $d['remarks'] !== '' ? $d['remarks'] : null,
                    $actor,
                );

                $imported++;

                if ($row['status'] === self::STATUS_AWAITING_HR) {
                    $awaiting++;
                }
            }
        });

        return ['imported' => $imported, 'skipped' => $skipped, 'awaiting_decision' => $awaiting];
    }

    private function resolveEmployee(string $code, array &$errors): ?Employee
    {
        $code = trim($code);

        if ($code === '') {
            $errors[] = 'employee_code is required.';

            return null;
        }

        // The code is the authoritative identity — email is not, because HR
        // sheets reuse and mistype them. Both shapes the business writes are
        // accepted: the staff code (CNS018) and the numeric biometric code.
        $employee = Employee::with('user')
            ->where(function ($q) use ($code) {
                $q->where('employee_id', $code);

                if (ctype_digit($code)) {
                    $q->orWhere('employee_code', (int) $code);
                }
            })
            ->first();

        if ($employee === null) {
            $errors[] = "No employee matches employee_code '{$code}'.";
        }

        return $employee;
    }

    private function resolveYear(string $label, array &$errors): ?LeaveYear
    {
        $label = trim($label);

        if ($label === '') {
            $errors[] = 'leave_year is required.';

            return null;
        }

        $year = LeaveYear::where('label', $label)->first();

        if ($year === null) {
            $errors[] = "No leave year matches '{$label}'. Expected a label such as 2025/26.";

            return null;
        }

        // History is for years that have ended. The live year's balance is
        // being used right now; overwriting it would discard real usage.
        if ($year->starts_on->gte(app(LeaveYearResolver::class)->current()->starts_on)) {
            $errors[] = "{$year->label} is the current or a future leave year. Only closed leave years can be imported.";

            return null;
        }

        return $year;
    }

    private function resolveType(string $code, array &$errors): ?LeaveType
    {
        $code = trim($code);

        if ($code === '') {
            $errors[] = 'leave_type is required.';

            return null;
        }

        $type = LeaveType::withTrashed()
            ->where('code', strtoupper($code))
            ->orWhere('name', $code)
            ->first();

        if ($type === null) {
            $errors[] = "No leave type matches '{$code}'.";
        }

        return $type;
    }

    private function number(string $value, string $field, array &$errors, bool $required = false): ?float
    {
        $value = trim($value);

        if ($value === '') {
            if ($required) {
                $errors[] = "{$field} is required.";
            }

            return null;
        }

        if (! is_numeric($value)) {
            $errors[] = "{$field} must be a number, got '{$value}'.";

            return null;
        }

        if ((float) $value < 0) {
            $errors[] = "{$field} cannot be negative.";

            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * A figure that may legitimately be absent. Anything the business writes
     * for "we do not have this" reads as unknown; a number reads as a record.
     */
    private function optionalNumber(string $value, string $field, array &$errors): ?float
    {
        if (in_array(strtolower(trim($value)), self::UNKNOWN_TOKENS, true)) {
            return null;
        }

        return $this->number($value, $field, $errors);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function normalize(array $raw): array
    {
        $out = [];

        foreach ($raw as $key => $value) {
            $out[strtolower(trim((string) $key))] = trim((string) $value);
        }

        return $out;
    }

    /** @param  array<string, string>  $r */
    private function isBlank(array $r): bool
    {
        return implode('', array_values($r)) === '';
    }
}
