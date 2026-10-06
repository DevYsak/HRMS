<?php

namespace App\Services\DataTransfer;

use App\Enums\HolidayType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-load holidays from a spreadsheet. Preview-first: every row is checked
 * and the HR user sees what will be created and what is wrong before anything
 * is written. Only valid rows are imported; existing holidays are never
 * changed or duplicated.
 */
class HolidayImportService
{
    /** The template / export layout. */
    public const HEADINGS = ['Name', 'Date', 'Type', 'Category', 'Country', 'Paid', 'Optional', 'Recurring', 'Description'];

    /** Date formats accepted, day first (UK / India), never month first. */
    private const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];

    /**
     * Check every row.
     *
     * @param  array<int, array<string, string>>  $rows  slug-keyed rows from SpreadsheetService::read()
     * @return array<int, array{line: int, data: array<string, mixed>, errors: array<int, string>}>
     */
    public function preview(array $rows): array
    {
        $seen = [];
        $out = [];

        foreach (array_values($rows) as $i => $row) {
            if (collect($row)->every(fn ($v) => trim((string) $v) === '')) {
                continue;
            }

            [$data, $errors] = $this->parse($row);

            if ($errors === []) {
                $key = $this->key($data);

                if (isset($seen[$key])) {
                    $errors[] = "Duplicates row {$seen[$key]} of this file.";
                } elseif ($this->exists($data)) {
                    $errors[] = 'This holiday already exists.';
                } else {
                    $seen[$key] = $i + 2;
                }
            }

            // +2: one for the heading row, one because spreadsheets count from 1.
            $out[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $out;
    }

    /**
     * Create the valid rows of a preview. Each row is checked again, so a
     * holiday added since the preview is skipped rather than duplicated.
     *
     * @param  array<int, array{line: int, data: array<string, mixed>, errors: array<int, string>}>  $preview
     * @return array{created: int, skipped: int}
     */
    public function import(array $preview, User $actor, string $sourceName = 'spreadsheet'): array
    {
        $rows = collect($preview)->filter(fn (array $r) => $r['errors'] === [])->pluck('data');
        $created = 0;

        DB::transaction(function () use ($rows, $actor, $sourceName, &$created) {
            foreach ($rows as $raw) {
                [$data, $errors] = $this->parse($this->unparse($raw));

                if ($errors !== [] || $this->exists($data)) {
                    continue;
                }

                PublicHoliday::create($data + [
                    'is_active' => true,
                    'created_by' => $actor->id,
                    'source' => Str::limit('Import / Export centre: '.$sourceName, 250),
                ]);
                $created++;
            }
        });

        $skipped = count($preview) - $created;

        app(AuditService::class)->event('DATA_IMPORTED', AuditService::IMPORTS, $actor,
            new: ['dataset' => 'holidays', 'file' => $sourceName, 'created' => $created, 'skipped' => $skipped]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function parse(array $row): array
    {
        $errors = [];
        $get = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        $name = $get('name');
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 120) {
            $errors[] = 'Name is longer than 120 characters.';
        }

        $date = $this->date($get('date'));
        if ($date === null) {
            $errors[] = $get('date') === ''
                ? 'Date is required.'
                : 'Date "'.$get('date').'" is not a date — use YYYY-MM-DD or DD/MM/YYYY.';
        }

        $type = $this->type($get('type'));
        if ($type === null) {
            $errors[] = 'Type "'.$get('type').'" is not one of: '.collect(HolidayType::cases())->map->value->implode(', ').'.';
        }

        $country = strtoupper($get('country')) ?: $this->defaultCountry();
        if (! preg_match('/^[A-Z]{2,5}$/', $country)) {
            $errors[] = 'Country "'.$get('country').'" should be a short code such as UK or IN.';
        }

        $category = $get('category');
        if (mb_strlen($category) > 60) {
            $errors[] = 'Category is longer than 60 characters.';
        }

        $description = $get('description');
        if (mb_strlen($description) > 1000) {
            $errors[] = 'Description is longer than 1000 characters.';
        }

        $flags = [];
        foreach (['paid' => true, 'optional' => false, 'recurring' => false] as $flag => $default) {
            $flags[$flag] = $this->bool($get($flag), $default);
            if ($flags[$flag] === null) {
                $errors[] = ucfirst($flag).' "'.$get($flag).'" should be Yes or No.';
            }
        }

        $data = [
            'name' => $name,
            'date' => $date?->toDateString(),
            'holiday_type' => $type?->value,
            'category' => $category !== '' ? $category : null,
            'country' => $country,
            'is_paid' => (bool) $flags['paid'],
            'is_optional' => (bool) $flags['optional'] || $type === HolidayType::Optional,
            'is_recurring' => (bool) $flags['recurring'],
            'description' => $description !== '' ? $description : null,
        ];

        return [$data, $errors];
    }

    /**
     * Back to the raw row shape, so import() re-checks with the same rules.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function unparse(array $data): array
    {
        return [
            'name' => (string) $data['name'],
            'date' => (string) $data['date'],
            'type' => (string) $data['holiday_type'],
            'category' => (string) ($data['category'] ?? ''),
            'country' => (string) $data['country'],
            'paid' => $data['is_paid'] ? 'yes' : 'no',
            'optional' => $data['is_optional'] ? 'yes' : 'no',
            'recurring' => $data['is_recurring'] ? 'yes' : 'no',
            'description' => (string) ($data['description'] ?? ''),
        ];
    }

    private function date(string $value): ?Carbon
    {
        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }

            // createFromFormat rolls 31/02 over to March; refuse that.
            if ($date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /** A type by value ("company") or label ("Company Holiday"); blank means National. */
    private function type(string $value): ?HolidayType
    {
        if ($value === '') {
            return HolidayType::National;
        }

        $needle = Str::lower($value);

        return collect(HolidayType::cases())->first(
            fn (HolidayType $t) => $t->value === $needle || Str::lower($t->label()) === $needle,
        );
    }

    private function bool(string $value, bool $default): ?bool
    {
        return match (Str::lower($value)) {
            '' => $default,
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    /** The company's holiday calendar, the one every employee follows by default. */
    private function defaultCountry(): string
    {
        return DB::table('companies')->value('holiday_calendar') ?: HolidayResolver::FALLBACK_CALENDAR;
    }

    /** @param array<string, mixed> $data */
    private function key(array $data): string
    {
        return $data['date'].'|'.$data['country'].'|'.Str::lower($data['name']);
    }

    /** @param array<string, mixed> $data */
    private function exists(array $data): bool
    {
        return PublicHoliday::query()
            ->whereDate('date', $data['date'])
            ->where('country', $data['country'])
            ->whereRaw('LOWER(name) = ?', [Str::lower($data['name'])])
            ->exists();
    }
}
