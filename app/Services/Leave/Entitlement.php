<?php

namespace App\Services\Leave;

/**
 * A calculated holiday entitlement, with its working shown.
 *
 * Storing a single "35 days" loses the only information anyone asks for when
 * checking holiday pay: how much of it is the statutory minimum and how much
 * the contract added. Both are kept, along with the pro-rata factor and the
 * method used, so a figure can always be explained rather than just asserted.
 */
readonly class Entitlement
{
    public function __construct(
        public float $statutoryDays,
        public float $contractualDays,
        public float $bankHolidayDays,
        public float $proRataFactor,
        public string $method,
        public string $explanation,
        /**
         * True when no working pattern was recorded and a default was assumed.
         *
         * The figure is then a placeholder, not a fact about this employee.
         * Callers must surface this rather than presenting the number as
         * verified — an authoritative-looking entitlement built on a guess is
         * worse than no figure at all, because nobody knows to question it.
         */
        public bool $patternAssumed = false,
    ) {}

    /** What the employee may actually book, statutory plus contractual. */
    public function totalDays(): float
    {
        return round($this->statutoryDays + $this->contractualDays, 2);
    }

    /**
     * Bank holidays that come out of the entitlement rather than sitting on
     * top of it. Zero when the policy pays them additionally.
     */
    public function bookableDays(): float
    {
        return round(max(0, $this->totalDays() - $this->bankHolidayDays), 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'statutory_days' => $this->statutoryDays,
            'contractual_days' => $this->contractualDays,
            'total_days' => $this->totalDays(),
            'bank_holiday_days' => $this->bankHolidayDays,
            'bookable_days' => $this->bookableDays(),
            'pro_rata_factor' => $this->proRataFactor,
            'method' => $this->method,
            'explanation' => $this->explanation,
            'pattern_assumed' => $this->patternAssumed,
        ];
    }
}
