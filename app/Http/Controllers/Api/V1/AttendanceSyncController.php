<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PunchMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttendanceSyncRequest;
use App\Models\AttendanceDailySummary;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/attendance/sync
 *
 * Ingests per-day attendance summaries calculated by the Python engine into
 * `attendance_daily_summaries`, upserted by (employee_id, date) so re-syncing
 * the same day is idempotent. Records whose employee_code is unknown are
 * skipped and reported back rather than failing the whole batch.
 */
class AttendanceSyncController extends Controller
{
    public function __invoke(AttendanceSyncRequest $request): JsonResponse
    {
        $records = $request->validated('records');

        // Resolve device PINs to internal employee ids in one query.
        $employeeMap = Employee::query()
            ->whereNotNull('employee_code')
            ->pluck('id', 'employee_code');

        $synced = 0;
        $skipped = [];
        $now = now();

        DB::transaction(function () use ($records, $employeeMap, $now, &$synced, &$skipped) {
            foreach ($records as $record) {
                $code = (int) $record['employee_code'];
                $employeeId = $employeeMap[$code] ?? null;

                if ($employeeId === null) {
                    $skipped[] = ['employee_code' => $code, 'date' => $record['date'] ?? null, 'reason' => 'unknown employee_code'];

                    continue;
                }

                $date = Carbon::parse($record['date'])->toDateString();

                AttendanceDailySummary::updateOrCreate(
                    ['employee_id' => $employeeId, 'date' => $date],
                    [
                        'employee_code' => $code,
                        'first_punch' => $this->normalisePunch($record['first_punch'] ?? null, $date),
                        'last_punch' => $this->normalisePunch($record['last_punch'] ?? null, $date),
                        'first_punch_method' => PunchMethod::fromDevice($record['first_punch_method'] ?? null)?->value,
                        'last_punch_method' => PunchMethod::fromDevice($record['last_punch_method'] ?? null)?->value,
                        'break_minutes' => (int) ($record['break_minutes'] ?? 0),
                        'working_hours' => (float) ($record['working_hours'] ?? 0),
                        'late_minutes' => (int) ($record['late_minutes'] ?? 0),
                        'early_leave_minutes' => (int) ($record['early_leave_minutes'] ?? 0),
                        'overtime_minutes' => (int) ($record['overtime_minutes'] ?? 0),
                        'status' => $record['status'] ?? 'present',
                        'device_serial' => $record['device_serial'] ?? null,
                        'raw_punch_count' => (int) ($record['raw_punch_count'] ?? 0),
                        'synced_at' => $now,
                    ]
                );

                $synced++;
            }
        });

        return response()->json([
            'success' => true,
            'synced' => $synced,
            'skipped' => count($skipped),
            'skipped_records' => $skipped,
        ], $skipped === [] ? 200 : 207);
    }

    /**
     * Normalise a punch value to a full datetime string. If the engine sends a
     * time-only value ("09:05:00"), anchor it to the record's date.
     */
    private function normalisePunch(?string $value, string $date): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $punch = Carbon::parse($value);

        // Time-only strings parse to "today" — re-anchor to the attendance date.
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', trim($value)) === 1) {
            $punch->setDateFrom(Carbon::parse($date));
        }

        return $punch->toDateTimeString();
    }
}
