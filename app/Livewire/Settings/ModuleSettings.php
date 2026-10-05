<?php

namespace App\Livewire\Settings;

use App\Models\ModuleSetting;
use App\Models\User;
use App\Services\ModuleFeatureService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * System Settings › Modules: switch Payroll & Payslips on or off.
 *
 * Availability only — no record is deleted or changed, and permissions still
 * apply when the module is on (module enabled AND permission). Disabling asks
 * for an explicit confirmation; every change is audited by the service.
 */
class ModuleSettings extends Component
{
    public bool $confirmingDisable = false;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    /** Turning payroll ON needs no confirmation; turning it OFF does. */
    public function enablePayroll(): void
    {
        $this->authorize('manage-settings');
        app(ModuleFeatureService::class)->setPayroll(true, Auth::user());
        \Flux::toast('Payroll & Payslips enabled.', variant: 'success');
    }

    public function askDisablePayroll(): void
    {
        $this->authorize('manage-settings');
        $this->confirmingDisable = true;
    }

    public function confirmDisablePayroll(): void
    {
        $this->authorize('manage-settings');
        app(ModuleFeatureService::class)->setPayroll(false, Auth::user());
        $this->confirmingDisable = false;
        \Flux::toast('Payroll & Payslips disabled. Existing records are preserved.');
    }

    public function togglePayslips(): void
    {
        $this->authorize('manage-settings');
        $features = app(ModuleFeatureService::class);

        try {
            $features->setPayslips(! $features->payslipsEnabled(), Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function render()
    {
        $features = app(ModuleFeatureService::class);
        $setting = ModuleSetting::current();

        return view('livewire.settings.module-settings', [
            'payrollEnabled' => $features->payrollEnabled(),
            'payslipsEnabled' => $features->payslipsEnabled(),
            'updatedBy' => $setting->updated_by ? User::find($setting->updated_by)?->name : null,
            'updatedAt' => $setting->updated_by ? $setting->updated_at : null,
        ])->layout('layouts.app', ['title' => 'Modules']);
    }
}
