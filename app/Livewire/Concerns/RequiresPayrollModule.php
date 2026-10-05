<?php

namespace App\Livewire\Concerns;

use App\Services\ModuleFeatureService;

/**
 * A payroll component refuses to load — or to run any action — while the
 * Payroll & Payslips module is switched off. Livewire calls this boot hook on
 * the first render and on every later request, so a page opened before the
 * switch was turned off cannot keep acting.
 */
trait RequiresPayrollModule
{
    public function bootRequiresPayrollModule(): void
    {
        app(ModuleFeatureService::class)->assertPayrollEnabled();
    }
}
