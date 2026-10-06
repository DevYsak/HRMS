<?php

namespace App\Services\Leave;

use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Leave Assistant panel in Apply Leave: explains, from LIVE data only,
 * what the employee has and what their chosen request means — available
 * CSL, carry forward, pending reservations, Comp Off, MDL shutdown dates,
 * the leave year, paid / unpaid, half-day and encashment eligibility,
 * weekends and holidays in the range, and a suggested leave type.
 *
 * Every figure comes from the same services that validate a request
 * (EmployeeLeaveOverviewService, LeaveBalanceCalculator, LeaveRuleResolver,
 * LeaveService, HolidayResolver, WorkingDayResolver, DecemberMandatoryDay).
 * Nothing here decides anything: final validation stays in
 * LeaveService::submitRequest. No policy text is generated — each message
 * is a fixed sentence filled with a looked-up number.
 */
class LeaveAssistantService
{
    public function __construct(
        private LeaveService $leave,
        private LeaveRuleResolver $rules,
        private HolidayResolver $holidays,
        private WorkingDayResolver $workingDays,
    ) {}

    /**
     * @param  array<string, mixed>  $overview  EmployeeLeaveOverviewService::for($employee)
     * @return array{facts: array<string, mixed>, messages: array<int, array{tone: string, text: string}>, suggestion: ?array{type_id: int, name: string, why: string}}
     */
    public function explain(
        Employee $employee,
        array $overview,
        ?LeaveType $type,
        ?string $start,
        ?string $end,
        bool $halfDay = false,
        string $paidStatus = 'paid',
    ): array {
        $csl = $overview['csl'] ?? null;
        $compOff = $overview['comp_off'] ?? null;

        $facts = [
            'leave_year' => $overview['year']->label ?? null,
            'csl_name' => $csl['type']->name ?? 'CSL',
            'csl_available' => $this->num($csl['summary']['available_to_request'] ?? null),
            'csl_carry_forward' => $this->num($csl['summary']['carry_forward'] ?? null),
            'csl_pending' => $this->num($csl['summary']['pending'] ?? null),
            'comp_off_available' => $this->num($compOff['summary']['available_to_request'] ?? null),
            'mdl_upcoming' => collect($overview['mdl']['dates'] ?? [])->where('status', 'upcoming')
                ->map(fn ($d) => $d['date']->format('D d M'))->values()->all(),
        ];

        $messages = [];
        $messages[] = $this->info("You currently have {$this->days($facts['csl_available'])} {$facts['csl_name']} available to request in {$facts['leave_year']}.");

        if ($facts['csl_carry_forward'] > 0) {
            $messages[] = $this->info("That includes {$this->days($facts['csl_carry_forward'])} carried forward from last year.");
        }
        if ($facts['csl_pending'] > 0) {
            $messages[] = $this->info("{$this->days($facts['csl_pending'])} are held by your pending requests until they are decided.");
        }
        if ($facts['comp_off_available'] > 0) {
            $messages[] = $this->success("You have {$this->days($facts['comp_off_available'])} of Comp Off available; you may use it instead of {$facts['csl_name']}.");
        }
        if ($facts['mdl_upcoming'] !== []) {
            $messages[] = $this->info('Mandatory December shutdown (MDL) days ahead: '.implode(', ', $facts['mdl_upcoming']).'. These are company leave and never use your balance.');
        }

        $suggestion = null;

        if ($type !== null) {
            $settings = $this->rules->settings($employee, $type);
            $facts['type'] = $type->name;
            $facts['half_day_allowed'] = (bool) $settings['allow_half_day'];
            $facts['paid_allowed'] = (bool) $type->allow_paid_request;
            $facts['unpaid_allowed'] = (bool) $type->allow_unpaid_request;
            $facts['attachment_required'] = (bool) ($settings['attachment_required'] ?? $type->attachment_required);

            $messages[] = $facts['half_day_allowed']
                ? $this->info("Half days are allowed for {$type->name}.")
                : $this->info("{$type->name} must be taken in whole days.");

            if ($facts['attachment_required']) {
                $messages[] = $this->warning("{$type->name} needs a supporting document.");
            }

            $messages = array_merge($messages, $this->encashment($employee, $type));
        }

        $range = $this->range($start, $end, $halfDay);

        if ($range !== null) {
            [$from, $to] = $range;
            $facts = array_merge($facts, $this->rangeFacts($employee, $type, $from, $to, $halfDay));
            $messages = array_merge($messages, $this->rangeMessages($facts));

            if ($type !== null && $facts['requested_days'] !== null) {
                $messages = array_merge($messages, $this->paidMessages($facts, $type, $paidStatus, $csl, $compOff));
                $suggestion = $this->suggest($facts['requested_days'], $csl, $compOff, $type, $paidStatus);
            }
        }

        if ($suggestion !== null) {
            $messages[] = $this->success("Suggested: {$suggestion['name']} — {$suggestion['why']}");
        }

        return ['facts' => $facts, 'messages' => $messages, 'suggestion' => $suggestion];
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function range(?string $start, ?string $end, bool $halfDay): ?array
    {
        if (! $start) {
            return null;
        }

        try {
            $from = Carbon::parse($start)->startOfDay();
            $to = $halfDay || ! $end ? $from->copy() : Carbon::parse($end)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $to->lt($from) ? null : [$from, $to];
    }

    /** @return array<string, mixed> */
    private function rangeFacts(Employee $employee, ?LeaveType $type, Carbon $from, Carbon $to, bool $halfDay): array
    {
        $weekend = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($this->workingDays->isWeeklyOff($d)) {
                $weekend[] = $d->format('D d M');
            }
        }

        // The employee's own calendar only (country, office, department).
        $holidays = $this->holidays->holidaysInRange($from, $to)
            ->filter(fn (PublicHoliday $h) => $this->holidays->appliesTo($h, $employee))
            ->map(fn (PublicHoliday $h) => $h->name.' ('.$h->date->format('D d M').')')->values()->all();

        $mdl = DecemberMandatoryDay::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->get()->map(fn ($d) => Carbon::parse($d->date)->format('D d M'))->all();

        $days = null;
        if ($halfDay) {
            $days = 0.5;
        } elseif ($type !== null) {
            [$bridgedFrom, $bridgedTo] = $this->leave->resolveSandwichBridge($employee, $type, $from->copy(), $to->copy());
            $days = $this->leave->calculateLeaveDays($bridgedFrom, $bridgedTo, (bool) $type->is_sandwich_applicable, (int) ($type->sandwich_min_days ?? 0));
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'requested_days' => $days,
            'weekend_days' => $weekend,
            'starts_or_ends_on_weekly_off' => $this->workingDays->isWeeklyOff($from) || $this->workingDays->isWeeklyOff($to),
            'holidays_in_range' => $holidays,
            'mdl_in_range' => $mdl,
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<int, array{tone: string, text: string}>
     */
    private function rangeMessages(array $facts): array
    {
        $messages = [];

        if ($facts['requested_days'] !== null) {
            $messages[] = $this->info("This request counts as {$this->days($facts['requested_days'])} of leave.");
        }
        if ($facts['weekend_days'] !== [] && ! $facts['starts_or_ends_on_weekly_off']) {
            $messages[] = $this->info('Weekly offs inside the range ('.implode(', ', $facts['weekend_days']).') are not counted as leave.');
        }
        if ($facts['starts_or_ends_on_weekly_off']) {
            $messages[] = $this->error('Leave cannot start or end on a weekly off (Saturday or Sunday). Choose a working day.');
        }
        if ($facts['holidays_in_range'] !== []) {
            $messages[] = $this->error('These dates include a public holiday: '.implode(', ', $facts['holidays_in_range']).'. Holidays are already days off — split the request around them.');
        }
        if ($facts['mdl_in_range'] !== []) {
            $messages[] = $this->error('These dates overlap the Mandatory December shutdown ('.implode(', ', $facts['mdl_in_range']).'). Shutdown days are company leave and do not require CSL — leave them out of the request.');
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>|null  $csl
     * @param  array<string, mixed>|null  $compOff
     * @return array<int, array{tone: string, text: string}>
     */
    private function paidMessages(array $facts, LeaveType $type, string $paidStatus, ?array $csl, ?array $compOff): array
    {
        $messages = [];
        $days = (float) $facts['requested_days'];

        if ($paidStatus === 'unpaid') {
            return [$facts['unpaid_allowed']
                ? $this->info('This request is unpaid leave: it does not use your balance.')
                : $this->error("{$type->name} cannot be taken as unpaid leave.")];
        }

        if (! $facts['paid_allowed']) {
            return [$this->error("{$type->name} cannot be taken as paid leave; request it as unpaid.")];
        }

        $available = match (true) {
            isset($csl['type']) && $csl['type']->id === $type->id => (float) $csl['summary']['available_to_request'],
            isset($compOff['type']) && $compOff['type']->id === $type->id => (float) $compOff['summary']['available_to_request'],
            default => null,
        };

        if ($available === null) {
            $messages[] = $this->info('This request is paid leave.');
        } elseif ($available + 0.001 >= $days) {
            $messages[] = $this->success("Requested leave is paid: {$this->days($days)} of your {$this->days($available)} {$type->name}.");
        } else {
            $messages[] = $this->warning("Only {$this->days(max(0, $available))} {$type->name} available for your {$this->days($days)} request.");
        }

        return $messages;
    }

    /**
     * The leave type that covers the request: CSL first, then Comp Off,
     * otherwise unpaid. Never a split — one request is one type.
     *
     * @param  array<string, mixed>|null  $csl
     * @param  array<string, mixed>|null  $compOff
     * @return array{type_id: int, name: string, why: string}|null
     */
    private function suggest(float $days, ?array $csl, ?array $compOff, LeaveType $selected, string $paidStatus): ?array
    {
        if ($paidStatus === 'unpaid') {
            return null;
        }

        $cslAvailable = (float) ($csl['summary']['available_to_request'] ?? 0);
        $coAvailable = (float) ($compOff['summary']['available_to_request'] ?? 0);

        if ($compOff && $coAvailable + 0.001 >= $days && $selected->id !== $compOff['type']->id) {
            return ['type_id' => $compOff['type']->id, 'name' => $compOff['type']->name, 'why' => "your {$this->days($coAvailable)} of Comp Off covers this request and keeps your {$csl['type']->name} free."];
        }
        if ($csl && $cslAvailable + 0.001 >= $days && $selected->id !== $csl['type']->id) {
            return ['type_id' => $csl['type']->id, 'name' => $csl['type']->name, 'why' => "it covers this request ({$this->days($cslAvailable)} available)."];
        }
        if ($csl && $cslAvailable + 0.001 < $days && (! $compOff || $coAvailable + 0.001 < $days)) {
            return ['type_id' => $selected->id, 'name' => $selected->name.' (unpaid)', 'why' => 'neither your CSL nor your Comp Off covers it; request it as unpaid, or shorten it.'];
        }

        return null;
    }

    /** @return array<int, array{tone: string, text: string}> */
    private function encashment(Employee $employee, LeaveType $type): array
    {
        if (! $type->allow_encashment || $type->category === 'comp_off') {
            return [$this->info("{$type->name} cannot be encashed.")];
        }

        try {
            $cap = $this->leave->encashable($employee, $type);
        } catch (\Throwable) {
            return [];
        }

        // What could be encashed now: the encashable days, within any cap left.
        $remaining = $this->num($cap['remaining_cap'] !== null
            ? min((float) $cap['available'], (float) $cap['remaining_cap'])
            : $cap['available']);

        return [$this->info($remaining > 0
            ? "{$type->name} can be encashed: up to {$this->days($remaining)} this year."
            : "{$type->name} can be encashed, but nothing is encashable right now.")];
    }

    private function num(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function days(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2), '0'), '.');

        return $text.' '.Str::plural('day', abs($value) === 1.0 ? 1 : 2);
    }

    /** @return array{tone: string, text: string} */
    private function info(string $text): array
    {
        return ['tone' => 'info', 'text' => $text];
    }

    /** @return array{tone: string, text: string} */
    private function success(string $text): array
    {
        return ['tone' => 'success', 'text' => $text];
    }

    /** @return array{tone: string, text: string} */
    private function warning(string $text): array
    {
        return ['tone' => 'warning', 'text' => $text];
    }

    /** @return array{tone: string, text: string} */
    private function error(string $text): array
    {
        return ['tone' => 'error', 'text' => $text];
    }
}
