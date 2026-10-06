<?php

namespace App\Observers;

use App\Models\DecemberMandatoryDay;
use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Models\PayrollApprovalPolicy;
use App\Models\PublicHoliday;
use App\Models\SalaryComponent;
use App\Models\SalaryCycle;
use App\Models\SalaryStructure;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Audits configuration records — notification channels and recipients,
 * payroll settings, holidays, MDL dates, attendance / leave / company
 * settings — wherever they are changed from, as categorised events with
 * only the fields that actually changed.
 *
 * Registered in AppServiceProvider for each configuration model.
 */
class ConfigurationAuditObserver
{
    /** Bookkeeping columns that never make a change worth recording on their own. */
    private const IGNORED = ['created_at', 'updated_at', 'deleted_at', 'sort_order'];

    /**
     * Fields a dedicated, explicit audit entry already covers (the template
     * editor records notification.template_updated itself).
     *
     * @var array<class-string, array<int, string>>
     */
    private const COVERED_ELSEWHERE = [
        NotificationSetting::class => ['custom_subject', 'custom_body'],
        NotificationRoleSetting::class => ['custom_subject', 'custom_body'],
    ];

    public function created(Model $model): void
    {
        $this->record($model, 'created', null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        $changes = array_diff_key($changes, array_flip(self::COVERED_ELSEWHERE[$model::class] ?? []));

        if ($changes === []) {
            return;
        }

        $this->record($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', $this->clean($model->getAttributes()), null);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(Model $model, string $action, ?array $old, ?array $new): void
    {
        $category = $this->isPayroll($model) ? AuditService::PAYROLL : AuditService::SETTINGS;

        app(AuditService::class)->event(
            Str::upper(Str::snake(class_basename($model))).'_'.Str::upper($action),
            $category,
            $model,
            old: $old,
            new: $new,
            module: $this->module($model),
            action: $action,
        );
    }

    private function isPayroll(Model $model): bool
    {
        return $model instanceof PayrollApprovalPolicy
            || $model instanceof SalaryCycle
            || $model instanceof SalaryComponent
            || $model instanceof SalaryStructure;
    }

    private function module(Model $model): string
    {
        return match (true) {
            $this->isPayroll($model) => 'payroll_settings',
            $model instanceof PublicHoliday, $model instanceof DecemberMandatoryDay => 'holidays',
            $model instanceof NotificationSetting, $model instanceof NotificationRoleSetting => 'notifications',
            default => 'settings',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        return array_diff_key($values, array_flip(self::IGNORED));
    }
}
