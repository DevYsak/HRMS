<?php

namespace App\Livewire\Holidays;

use App\Models\HolidayPaySetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Holiday Pay policy configuration — which pay types employees can request
 * when working a holiday, and how each is calculated. Singleton settings row
 * (same pattern as AttendanceSetting), consumed by HolidayWorkService.
 *
 * Plain scalar properties (not a bound Eloquent model) so the form works
 * reliably through both the browser and Livewire's testing API.
 */
class HolidayPaySettings extends Component
{
    /** @var array<int, string> */
    public array $enabledTypes = [];

    public string $defaultPayType = 'overtime';

    public float $doublePayMultiplier = 2.0;

    public float $compOffDays = 1.0;

    public float $extraLeaveDays = 1.0;

    public float $halfDayCompOffDays = 0.5;

    public ?float $otRatePerHour = null;

    public bool $autoApproveAfterManager = true;

    public string $policyNotes = '';

    protected function rules(): array
    {
        return [
            'enabledTypes' => 'required|array|min:1',
            'defaultPayType' => 'required|in:overtime,comp_off,double_pay,extra_leave,half_day',
            'doublePayMultiplier' => 'required|numeric|min:1|max:5',
            'compOffDays' => 'required|numeric|min:0|max:5',
            'extraLeaveDays' => 'required|numeric|min:0|max:5',
            'halfDayCompOffDays' => 'required|numeric|min:0|max:5',
            'otRatePerHour' => 'nullable|numeric|min:0',
            'policyNotes' => 'nullable|string|max:2000',
        ];
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $settings = HolidayPaySetting::current();
        $this->enabledTypes = $settings->enabledPayTypes();
        $this->defaultPayType = $settings->default_pay_type;
        $this->doublePayMultiplier = (float) $settings->double_pay_multiplier;
        $this->compOffDays = (float) $settings->comp_off_days_per_holiday;
        $this->extraLeaveDays = (float) $settings->extra_leave_days_per_holiday;
        $this->halfDayCompOffDays = (float) $settings->half_day_comp_off_days;
        $this->otRatePerHour = $settings->ot_rate_per_hour !== null ? (float) $settings->ot_rate_per_hour : null;
        $this->autoApproveAfterManager = (bool) $settings->auto_approve_after_manager;
        $this->policyNotes = (string) $settings->policy_notes;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate();

        if (! in_array($this->defaultPayType, $this->enabledTypes, true)) {
            $this->addError('defaultPayType', 'The default pay type must be one of the enabled pay types.');

            return;
        }

        HolidayPaySetting::current()->update([
            'allowed_pay_types' => array_values($this->enabledTypes),
            'default_pay_type' => $this->defaultPayType,
            'double_pay_multiplier' => $this->doublePayMultiplier,
            'comp_off_days_per_holiday' => $this->compOffDays,
            'extra_leave_days_per_holiday' => $this->extraLeaveDays,
            'half_day_comp_off_days' => $this->halfDayCompOffDays,
            'ot_rate_per_hour' => $this->otRatePerHour,
            'auto_approve_after_manager' => $this->autoApproveAfterManager,
            'policy_notes' => $this->policyNotes ?: null,
        ]);

        \Flux::toast('Holiday pay policy updated.', variant: 'success');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        return view('livewire.holidays.holiday-pay-settings', [
            'payTypeLabels' => HolidayPaySetting::payTypeLabels(),
        ])->layout('layouts.app', ['title' => 'Holiday Pay Policy']);
    }
}
