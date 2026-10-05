<?php

namespace App\Livewire\Concerns;

use App\Services\ModuleFeatureService;

/** As RequiresPayrollModule, for the employee payslip pages. */
trait RequiresPayslipsModule
{
    public function bootRequiresPayslipsModule(): void
    {
        app(ModuleFeatureService::class)->assertPayslipsEnabled();
    }
}
