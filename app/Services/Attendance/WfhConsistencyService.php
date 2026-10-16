<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WfhRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P3-07 (policy approved 16 Oct 2026): an approved WFH request is the
 * authorised, planned work mode; attendances.work_mode is the mode actually
 * recorded. They stay two separate facts and are only compared here.
 *
 *  - approved WFH + recorded WFH          → valid
 *  - approved WFH + recorded another mode → mismatch, review
 *  - no approved WFH + recorded WFH       → unauthorised / unlinked, review
 *
 * A pending request authorises nothing. READ-ONLY: nothing here writes to
 * attendance or to requests — history is reviewed by HR, never rewritten.
 */
class WfhConsistencyService
{
    public const VALID = 'valid';

    public const APPROVED_NOT_WFH = 'approved_not_wfh';

    public const UNAPPROVED_WFH = 'unapproved_wfh';

    /** @var array<string, string> */
    public const LABELS = [
        self::VALID => 'Valid',
        self::APPROVED_NOT_WFH => 'Approved WFH, recorded another mode',
        self::UNAPPROVED_WFH => 'WFH without an approved request',
    ];

    /** Above this many approved requests the query narrows by employee instead of by each request's dates. */
    private const MAX_REQUEST_CLAUSES = 200;

    /**
     * Every attended day where the plan and the record meet: days recorded as
     * WFH, and days an approved request covers. Days without a punch are not
     * attendance and are left out.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone
     * @return Collection<int, array{employee_id: int, date: string, recorded_mode: string, recorded_label: string, request_id: ?int, request_label: ?string, pending_request_id: ?int, half_day: bool, status: string, label: string}>
     */
    public function days(?array $employeeIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null): Collection
    {
        $requests = WfhRequest::query()
            ->whereIn('status', ['approved', 'pending'])
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('start_date', '<=', $to->toDateString()))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('end_date', '>=', $from->toDateString()))
            ->get(['id', 'employee_id', 'start_date', 'end_date', 'status', 'is_half_day', 'half_day_period']);
        $approved = $requests->where('status', 'approved');

        $attendance = Attendance::query()
            ->whereNotNull('check_in')
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $employeeIds))
            ->when($from !== null, fn (Builder $q) => $q->whereDate('date', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('date', '<=', $to->toDateString()))
            ->where(function (Builder $q) use ($approved) {
                $q->where('work_mode', AttendanceMode::Wfh->value);
                if ($approved->count() > self::MAX_REQUEST_CLAUSES) {
                    $q->orWhereIn('employee_id', $approved->pluck('employee_id')->unique()->values()->all());

                    return;
                }
                foreach ($approved as $request) {
                    $q->orWhere(fn (Builder $w) => $w->where('employee_id', $request->employee_id)
                        ->whereDate('date', '>=', $request->start_date->toDateString())
                        ->whereDate('date', '<=', $request->end_date->toDateString()));
                }
            })
            ->orderBy('date')->orderBy('employee_id')
            ->get(['id', 'employee_id', 'date', 'work_mode']);

        $byEmployee = $requests->groupBy('employee_id');

        return $attendance->map(function (Attendance $row) use ($byEmployee) {
            $date = $row->date->toDateString();
            $covering = ($byEmployee->get($row->employee_id) ?? collect())
                ->filter(fn (WfhRequest $r) => $r->start_date->toDateString() <= $date && $r->end_date->toDateString() >= $date);
            $request = $covering->firstWhere('status', 'approved');
            $pending = $covering->firstWhere('status', 'pending');
            $mode = (string) ($row->work_mode ?: AttendanceMode::Office->value);

            $status = match (true) {
                $request !== null && ($mode === AttendanceMode::Wfh->value || ($request->is_half_day && $mode === AttendanceMode::Hybrid->value)) => self::VALID,
                $request !== null => self::APPROVED_NOT_WFH,
                $mode === AttendanceMode::Wfh->value => self::UNAPPROVED_WFH,
                default => null,
            };

            return $status === null ? null : [
                'employee_id' => (int) $row->employee_id,
                'date' => $date,
                'recorded_mode' => $mode,
                'recorded_label' => AttendanceMode::tryFrom($mode)?->label() ?? ucfirst($mode),
                'request_id' => $request?->id,
                'request_label' => $request ? $this->requestLabel($request) : null,
                'pending_request_id' => $request === null ? $pending?->id : null,
                'half_day' => (bool) $request?->is_half_day,
                'status' => $status,
                'label' => self::LABELS[$status],
            ];
        })->filter()->values();
    }

    /**
     * Counts for a set of day rows.
     *
     * @param  Collection<int, array<string, mixed>>  $days
     * @return array{valid: int, approved_not_wfh: int, unapproved_wfh: int, flagged: int, employees_flagged: int}
     */
    public function summary(Collection $days): array
    {
        $flagged = $days->where('status', '!=', self::VALID);

        return [
            self::VALID => $days->where('status', self::VALID)->count(),
            self::APPROVED_NOT_WFH => $days->where('status', self::APPROVED_NOT_WFH)->count(),
            self::UNAPPROVED_WFH => $days->where('status', self::UNAPPROVED_WFH)->count(),
            'flagged' => $flagged->count(),
            'employees_flagged' => $flagged->pluck('employee_id')->unique()->count(),
        ];
    }

    /**
     * One employee's comparison over a window, flagged days newest first.
     *
     * @return array{counts: array{valid: int, approved_not_wfh: int, unapproved_wfh: int, flagged: int, employees_flagged: int}, flagged: array<int, array<string, mixed>>}
     */
    public function forEmployee(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $this->days([$employee->id], $from, $to);

        return [
            'counts' => $this->summary($days),
            'flagged' => $days->where('status', '!=', self::VALID)->sortByDesc('date')->values()->all(),
        ];
    }

    private function requestLabel(WfhRequest $request): string
    {
        $range = $request->start_date->equalTo($request->end_date)
            ? $request->start_date->format('d M Y')
            : $request->start_date->format('d M').' – '.Carbon::parse($request->end_date)->format('d M Y');

        return '#'.$request->id.' · '.$range.($request->is_half_day ? ' (half day)' : '');
    }
}
