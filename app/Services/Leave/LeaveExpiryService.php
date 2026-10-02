<?php

namespace App\Services\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Notifications\LeaveExpiringNotification;
use App\Services\Audit\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Expiring credit lots (Phase 2C) — carry forward, add-on, comp-off.
 *
 * Only the unconsumed part of a lot expires (leave taken before the date
 * consumed it first), posted as an EXPIRY entry dated the lot's expiry date;
 * history is never erased. Safe to run daily and to retry: a lot with
 * nothing left posts nothing.
 */
class LeaveExpiryService
{
    /** Days before expiry at which the employee is told. */
    public const NOTICE_WINDOWS = [30, 7];

    public function __construct(private readonly LeaveLedgerService $ledger) {}

    /**
     * Expire every lot whose expiry date has passed.
     *
     * @return array{expired_lots: int, expired_days: float, failed: int}
     */
    public function expireDue(?CarbonInterface $today = null): array
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();
        $result = ['expired_lots' => 0, 'expired_days' => 0.0, 'failed' => 0];

        $this->lots()
            ->whereDate('expires_on', '<', $today->toDateString())
            ->orderBy('expires_on')
            ->get()
            ->each(function (LeaveLedgerEntry $lot) use (&$result) {
                try {
                    $expiry = $this->ledger->expireCredit($lot, $lot->expires_on->copy(), null,
                        'Unused '.str_replace('_', ' ', $lot->entry_type).' expired on '.$lot->expires_on->format('d M Y'));

                    if ($expiry === null) {
                        return;
                    }

                    $this->ledger->rebuild($this->balanceOf($lot));

                    app(AuditService::class)->event('LEAVE_CREDIT_EXPIRED', AuditService::LEAVE, $expiry,
                        new: ['lot_id' => $lot->id, 'bucket' => $lot->bucket, 'days' => -(float) $expiry->days, 'expires_on' => $lot->expires_on->toDateString()],
                        reason: 'Scheduled expiry', subjectEmployeeId: $lot->employee_id);

                    $result['expired_lots']++;
                    $result['expired_days'] = round($result['expired_days'] - (float) $expiry->days, 2);
                } catch (Throwable) {
                    $result['failed']++;
                }
            });

        return $result;
    }

    /**
     * Lots with days left that expire within $withinDays of today.
     *
     * @return Collection<int, array{lot: LeaveLedgerEntry, employee_id: int, leave_type: ?string, bucket: string, remaining: float, expires_on: Carbon, days_left: int}>
     */
    public function upcoming(int $withinDays = 30, ?int $employeeId = null, ?CarbonInterface $today = null): Collection
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();

        return $this->lots()
            ->with('leaveType')
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $today->copy()->addDays($withinDays)->toDateString())
            ->orderBy('expires_on')
            ->get()
            ->map(fn (LeaveLedgerEntry $lot) => [
                'lot' => $lot,
                'employee_id' => $lot->employee_id,
                'leave_type' => $lot->leaveType?->name,
                'bucket' => str_replace('_', ' ', $lot->entry_type),
                'remaining' => $this->ledger->remaining($lot),
                'expires_on' => $lot->expires_on->copy(),
                'days_left' => (int) $today->diffInDays($lot->expires_on),
            ])
            ->filter(fn (array $r) => $r['remaining'] > 0.005)
            ->values();
    }

    /** Tell employees about lots expiring in exactly 30 or 7 days; once per lot per window. */
    public function notifyUpcoming(?CarbonInterface $today = null): int
    {
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();
        $sent = 0;

        foreach (self::NOTICE_WINDOWS as $window) {
            $this->upcoming($window, null, $today)
                ->filter(fn (array $r) => $r['days_left'] === $window)
                ->each(function (array $r) use ($window, &$sent) {
                    $user = Employee::find($r['employee_id'])?->user;

                    if ($user === null || $user->notifications()
                        ->where('type', LeaveExpiringNotification::class)
                        ->where('data->lot_id', $r['lot']->id)
                        ->where('data->window', $window)
                        ->exists()) {
                        return;
                    }

                    $user->notify(new LeaveExpiringNotification(
                        (string) $r['leave_type'], $r['bucket'], $r['remaining'], $r['expires_on']->toDateString(), $r['lot']->id, $window,
                    ));
                    $sent++;
                });
        }

        return $sent;
    }

    /** Live credit lots that carry an expiry date. */
    private function lots(): Builder
    {
        return LeaveLedgerEntry::query()
            ->whereIn('entry_type', LeaveLedgerEntry::CREDIT_TYPES)
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversedBy')
            ->whereNotNull('expires_on')
            ->where('days', '>', 0);
    }

    private function balanceOf(LeaveLedgerEntry $lot): LeaveBalance
    {
        return LeaveBalance::where('employee_id', $lot->employee_id)
            ->where('leave_type_id', $lot->leave_type_id)
            ->where('leave_year_id', $lot->leave_year_id)
            ->firstOrFail();
    }
}
