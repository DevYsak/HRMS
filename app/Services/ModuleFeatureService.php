<?php

namespace App\Services;

use App\Models\ModuleSetting;
use App\Models\User;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The one source for whether a company module is switched on.
 *
 * Payroll & Payslips is a master switch: payslips are only ever available
 * while payroll is. A switch only controls availability — it never grants a
 * permission (module enabled AND permission), and turning it off never
 * deletes or changes payroll, payslip, salary, incentive, reimbursement or
 * encashment records.
 *
 * Bound per request (scoped), so the row is read once however many menus,
 * routes and components ask.
 */
class ModuleFeatureService
{
    private ?ModuleSetting $setting = null;

    public function payrollEnabled(): bool
    {
        return $this->setting()->payroll_enabled;
    }

    /** Payslips need payroll: off whenever payroll is off. */
    public function payslipsEnabled(): bool
    {
        return $this->payrollEnabled() && $this->setting()->payslips_enabled;
    }

    /**
     * Refuse when payroll is switched off — the backend gate behind every
     * hidden button, for Livewire actions, controllers and jobs alike.
     *
     * @throws HttpException 403
     */
    public function assertPayrollEnabled(): void
    {
        if (! $this->payrollEnabled()) {
            throw new HttpException(403, 'The Payroll & Payslips module is disabled.');
        }
    }

    /** @throws HttpException 403 */
    public function assertPayslipsEnabled(): void
    {
        if (! $this->payslipsEnabled()) {
            throw new HttpException(403, 'Payslips are disabled.');
        }
    }

    /**
     * Switch Payroll & Payslips on or off. Turning payroll off turns
     * payslips off with it; turning it back on restores payslips too.
     * Audited with the old and new values and who changed them.
     */
    public function setPayroll(bool $enabled, User $actor): void
    {
        $this->change(['payroll_enabled' => $enabled, 'payslips_enabled' => $enabled], $actor);
    }

    /** @throws DomainException when payroll itself is off */
    public function setPayslips(bool $enabled, User $actor): void
    {
        if ($enabled && ! $this->payrollEnabled()) {
            throw new DomainException('Payslips cannot be enabled while the Payroll module is disabled.');
        }

        $this->change(['payslips_enabled' => $enabled], $actor);
    }

    private function change(array $values, User $actor): void
    {
        DB::transaction(function () use ($values, $actor) {
            $setting = ModuleSetting::current();
            $old = $setting->only(array_keys($values));

            if ($old == $values) {
                return;
            }

            $setting->update($values + ['updated_by' => $actor->id]);

            app(AuditService::class)->event('MODULE_SETTING_CHANGED', AuditService::SETTINGS, $setting,
                old: $old, new: $values + ['changed_by' => $actor->email, 'changed_at' => now()->toDateTimeString()],
                reason: 'Payroll & Payslips module availability changed.');
        });

        $this->setting = ModuleSetting::current()->fresh();
    }

    private function setting(): ModuleSetting
    {
        return $this->setting ??= ModuleSetting::current();
    }
}
