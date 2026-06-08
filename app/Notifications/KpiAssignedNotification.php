<?php

namespace App\Notifications;

use App\Models\EmployeeKpi;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class KpiAssignedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly EmployeeKpi $kpi) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $component = $this->kpi->component->name ?? 'KPI';
        $target = $this->kpi->target_value;
        $due = $this->kpi->due_date?->format('d M Y') ?? 'N/A';

        return [
            'type' => 'kpi_assigned',
            'title' => 'New KPI Assigned: '.$component,
            'body' => "You have been assigned a new KPI — {$component} with a target of {$target} due by {$due}.",
            'action' => 'View My KPIs',
            'url' => '/performance/my-kpis',
            'icon' => 'chart-bar',
            'color' => 'blue',
        ];
    }
}
