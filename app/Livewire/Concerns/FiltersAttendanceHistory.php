<?php

namespace App\Livewire\Concerns;

use App\Enums\AttendanceMode;
use App\Services\Attendance\WorkingDayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My Attendance — Attendance History filters, pagination, sorting and export.
 *
 * Works on the selected month's canonical rows (monthHistory: the
 * WorkingDayResolver classification overlaid with AttendanceCalculator
 * figures). Filtering happens here on the server; nothing is recalculated
 * and nothing outside the signed-in employee's own month is ever read.
 *
 * The form edits draft values (filterFrom … filterMode); "Apply Filters"
 * validates them and copies them to the applied values (historyFrom …),
 * which live in the query string so a refresh keeps the view.
 */
trait FiltersAttendanceHistory
{
    /** Applied filters — inside the selected month (empty = whole month / all). */
    #[Url(as: 'from', except: '')]
    public string $historyFrom = '';

    #[Url(as: 'to', except: '')]
    public string $historyTo = '';

    #[Url(as: 'status', except: '')]
    public string $historyStatus = '';

    #[Url(as: 'mode', except: '')]
    public string $historyMode = '';

    /** Draft filter values bound to the form until "Apply Filters". */
    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterStatus = '';

    public string $filterMode = '';

    #[Url(as: 'page', except: 1)]
    public int $historyPage = 1;

    #[Url(as: 'per', except: 10)]
    public int $historyPerPage = 10;

    /** date | worked | status */
    #[Url(as: 'sort', except: 'date')]
    public string $historySort = 'date';

    /** desc | asc */
    #[Url(as: 'dir', except: 'desc')]
    public string $historyDir = 'desc';

    /** Statuses offered by the Attendance History filter. */
    public const HISTORY_STATUSES = [
        'present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'weekly_off' => 'Weekly Off',
        'holiday' => 'Holiday', 'leave' => 'On Leave', 'regularised' => 'Regularised',
        'auto_checkout' => 'Auto Checkout', 'missing_checkout' => 'Missing Check-Out',
    ];

    /** Work modes offered by the Mode filter (the ones the history records). */
    public const HISTORY_MODES = ['office', 'wfh', 'hybrid'];

    public const HISTORY_PAGE_SIZES = [10, 20, 50];

    /**
     * Make query-string state safe on first load: a bad or out-of-range
     * value is dropped, the month follows the From date when no month is
     * given, and the form starts from the applied values.
     */
    protected function initialiseHistoryFilters(): void
    {
        if ($this->historyMonth !== '' && ! preg_match('/^\d{4}-\d{2}$/', $this->historyMonth)) {
            $this->historyMonth = '';
        }
        if ($this->historyMonth === '' && $this->isHistoryDate($this->historyFrom)) {
            $this->historyMonth = substr($this->historyFrom, 0, 7);
        }
        if ($this->historyMonth !== '') {
            $this->historyMonth = $this->clampHistory($this->historyCursor())->format('Y-m');
        }

        [$min, $max] = $this->historyDateBounds();
        foreach (['historyFrom', 'historyTo'] as $field) {
            if ($this->{$field} !== '' && (! $this->isHistoryDate($this->{$field}) || $this->{$field} < $min || $this->{$field} > $max)) {
                $this->{$field} = '';
            }
        }
        if ($this->historyFrom !== '' && $this->historyTo !== '' && $this->historyTo < $this->historyFrom) {
            $this->historyTo = '';
        }
        if (! array_key_exists($this->historyStatus, self::HISTORY_STATUSES)) {
            $this->historyStatus = '';
        }
        if (! in_array($this->historyMode, self::HISTORY_MODES, true)) {
            $this->historyMode = '';
        }
        if (! in_array($this->historyPerPage, self::HISTORY_PAGE_SIZES, true)) {
            $this->historyPerPage = 10;
        }
        if (! in_array($this->historySort, ['date', 'worked', 'status'], true)) {
            $this->historySort = 'date';
        }
        if (! in_array($this->historyDir, ['asc', 'desc'], true)) {
            $this->historyDir = 'desc';
        }
        $this->historyPage = max(1, $this->historyPage);
        $this->syncHistoryDrafts();
    }

    /** Validate the draft filters and apply them together (back to page 1). */
    public function applyHistoryFilters(): void
    {
        [$min, $max] = $this->historyDateBounds();
        $range = Carbon::parse($min)->format('d M Y').' – '.Carbon::parse($max)->format('d M Y');

        $this->resetErrorBag(['filterFrom', 'filterTo', 'filterStatus', 'filterMode']);
        $this->validate([
            'filterFrom' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'filterTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'filterStatus' => ['nullable', 'in:'.implode(',', array_keys(self::HISTORY_STATUSES))],
            'filterMode' => ['nullable', 'in:'.implode(',', self::HISTORY_MODES)],
        ], [
            '*.after_or_equal' => "Choose a date within {$range}.",
            '*.before_or_equal' => "Choose a date within {$range}.",
            '*.date_format' => 'Enter a valid date.',
            '*.in' => 'Choose an option from the list.',
        ]);

        if ($this->filterFrom !== '' && $this->filterTo !== '' && $this->filterTo < $this->filterFrom) {
            $this->addError('filterTo', 'The To date cannot be before the From date.');

            return;
        }

        $this->historyFrom = $this->filterFrom;
        $this->historyTo = $this->filterTo;
        $this->historyStatus = $this->filterStatus;
        $this->historyMode = $this->filterMode;
        $this->historyPage = 1;
    }

    /** Reset every history filter to its default — the month stays. */
    public function clearHistoryFilters(): void
    {
        $this->reset('historyFrom', 'historyTo', 'historyStatus', 'historyMode', 'historyPage');
        $this->resetErrorBag(['filterFrom', 'filterTo', 'filterStatus', 'filterMode']);
        $this->syncHistoryDrafts();
    }

    /**
     * A new month: dates from another month no longer apply, so a date
     * outside the new month is dropped; status, mode, sort and page size are
     * kept. Back to page 1.
     */
    protected function resetHistoryRangeForMonth(): void
    {
        [$min, $max] = $this->historyDateBounds();
        foreach (['historyFrom', 'historyTo'] as $field) {
            if ($this->{$field} !== '' && ($this->{$field} < $min || $this->{$field} > $max)) {
                $this->{$field} = '';
            }
        }
        $this->historyPage = 1;
        $this->resetErrorBag(['filterFrom', 'filterTo']);
        $this->syncHistoryDrafts();
    }

    public function setHistoryPage(int $page): void
    {
        $pages = max(1, (int) ceil($this->historyRows()->count() / $this->historyPerPage));
        $this->historyPage = max(1, min($page, $pages));
        unset($this->historyPageData);
    }

    public function updatedHistoryPerPage(): void
    {
        if (! in_array($this->historyPerPage, self::HISTORY_PAGE_SIZES, true)) {
            $this->historyPerPage = 10;
        }
        $this->historyPage = 1;
    }

    /** Sort by a column; the same column again flips the direction. */
    public function sortHistory(string $column): void
    {
        if (! in_array($column, ['date', 'worked', 'status'], true)) {
            return;
        }

        $this->historyDir = $this->historySort === $column && $this->historyDir === 'desc' ? 'asc' : 'desc';
        $this->historySort = $column;
        $this->historyPage = 1;
    }

    /** Whether any applied filter narrows the month. */
    public function historyFiltersActive(): bool
    {
        return $this->historyFrom !== '' || $this->historyTo !== '' || $this->historyStatus !== '' || $this->historyMode !== '';
    }

    /**
     * The days a history filter can pick: the selected month, up to today.
     *
     * @return array{0: string, 1: string}
     */
    public function historyDateBounds(): array
    {
        $month = $this->historyCursor();
        $start = $month->copy()->startOfMonth();
        $joined = Auth::user()?->employee?->joining_date;
        if ($joined && Carbon::parse($joined)->gt($start)) {
            $start = Carbon::parse($joined)->startOfDay();
        }

        return [$start->toDateString(), $month->copy()->endOfMonth()->min(Carbon::today())->toDateString()];
    }

    /**
     * Narrow the month's rows (monthHistory, unchanged) by the applied
     * filters. Matches on the statuses and flags each row already carries —
     * weekly off, holiday and leave are their own statuses and never Absent.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    protected function filterHistoryRows(iterable $rows): Collection
    {
        $from = $this->historyFrom ?: null;
        $to = $this->historyTo ?: null;

        return collect($rows)
            ->filter(fn (array $r) => ($from === null || $r['date'] >= $from) && ($to === null || $r['date'] <= $to))
            ->filter(fn (array $r) => match ($this->historyStatus) {
                'present' => in_array($r['status'], ['Present', 'WFH', 'Half day', WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL], true),
                'late' => $r['status'] === 'Late',
                'absent' => $r['status'] === 'Absent',
                'weekly_off' => $r['status'] === WorkingDayResolver::WEEKLY_OFF_LABEL,
                'holiday' => in_array($r['status'], ['Holiday', 'MDL shutdown'], true),
                'leave' => str_starts_with((string) $r['status'], 'Leave'),
                'regularised' => (bool) ($r['regularised'] ?? false),
                'auto_checkout' => (bool) ($r['auto_checkout'] ?? false),
                'missing_checkout' => (bool) ($r['missing_checkout'] ?? false) && ! ($r['auto_checkout'] ?? false),
                default => true,
            })
            ->filter(fn (array $r) => $this->historyMode === '' || ($r['mode'] ?? null) === $this->historyMode)
            ->values();
    }

    /**
     * The month's days up to today (employment only), filtered and sorted —
     * what the table pages through and what the export writes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function historyRows(): Collection
    {
        $today = Carbon::today()->toDateString();
        $rows = collect($this->monthHistory['rows'] ?? [])
            ->reject(fn (array $r) => $r['date'] > $today || $r['status'] === 'Not employed');

        $sorted = $this->filterHistoryRows($rows)->sortBy(
            fn (array $r) => match ($this->historySort) {
                'worked' => sprintf('%06d|%s', (int) $r['worked_minutes'], $r['date']),
                'status' => $r['status'].'|'.$r['date'],
                default => $r['date'],
            },
            SORT_STRING,
            $this->historyDir === 'desc',
        );

        return $sorted->values();
    }

    /**
     * One page of the filtered history.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, total: int, month_total: int, page: int, pages: int, from: int, to: int}
     */
    #[Computed]
    public function historyPageData(): array
    {
        $all = $this->historyRows();
        $total = $all->count();
        $pages = max(1, (int) ceil($total / $this->historyPerPage));
        $page = max(1, min($this->historyPage, $pages));
        $offset = ($page - 1) * $this->historyPerPage;
        $today = Carbon::today()->toDateString();

        return [
            'rows' => $all->slice($offset, $this->historyPerPage)->values(),
            'total' => $total,
            'month_total' => collect($this->monthHistory['rows'] ?? [])->filter(fn (array $r) => $r['date'] <= $today && $r['status'] !== 'Not employed')->count(),
            'page' => $page,
            'pages' => $pages,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => min($offset + $this->historyPerPage, $total),
        ];
    }

    /**
     * Download the filtered history (every page, current sort) as CSV — the
     * signed-in employee's own days only, exactly as the table shows them.
     */
    public function exportHistory(): ?StreamedResponse
    {
        $employee = Auth::user()?->employee;
        if (! $employee) {
            return null;
        }

        $rows = $this->historyRows();
        $hm = fn (int $m): string => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
        $filename = 'attendance-history-'.($this->monthHistory['month'] ?? now()->format('Y-m'))
            .($this->historyFiltersActive() ? '-filtered' : '').'.csv';

        return response()->streamDownload(function () use ($rows, $hm) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Day', 'Status', 'First In', 'Last Out', 'Worked', 'Break', 'Exception', 'Mode']);
            foreach ($rows as $r) {
                $hasPunch = $r['check_in'] !== null;
                fputcsv($out, [
                    $r['date'],
                    Carbon::parse($r['date'])->format('D'),
                    $r['status'],
                    $r['check_in'] ?? '',
                    $r['check_out'] ?? '',
                    $hasPunch ? $hm((int) $r['worked_minutes']) : '',
                    $hasPunch ? $hm((int) $r['break_minutes']) : '',
                    implode('; ', $this->historyExceptions($r)),
                    $r['mode'] ? AttendanceMode::tryFromValue($r['mode'])->label() : '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * A day's exception labels, from the row's own flags.
     *
     * @param  array<string, mixed>  $r
     * @return array<int, string>
     */
    public function historyExceptions(array $r, bool $liveToday = false): array
    {
        return array_values(array_filter([
            ($r['auto_checkout'] ?? false) ? 'Auto Checkout' : null,
            ($r['missing_checkout'] ?? false) && ! ($r['auto_checkout'] ?? false) && ! $liveToday ? 'Missing OUT' : null,
            ($r['regularised'] ?? false) ? 'Regularised' : null,
            ($r['regularisation'] ?? null) && ! ($r['regularised'] ?? false) ? 'Regularisation '.$r['regularisation'] : null,
            $r['status'] === 'Late' && ($r['late_minutes'] ?? 0) > 0 ? 'Late '.$r['late_minutes'].'m' : null,
            ($r['ot_hours'] ?? 0) > 0 ? 'OT '.$r['ot_hours'].'h' : null,
            $r['holiday'] ?? null,
        ]));
    }

    private function syncHistoryDrafts(): void
    {
        $this->filterFrom = $this->historyFrom;
        $this->filterTo = $this->historyTo;
        $this->filterStatus = $this->historyStatus;
        $this->filterMode = $this->historyMode;
    }

    private function isHistoryDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
