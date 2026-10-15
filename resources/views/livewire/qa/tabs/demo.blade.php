<div data-tab-panel="demo">
    @include('livewire.qa.partials.grid', [
        'gridIntro' => 'Per role: a fictional demo account that logs in, lands on the right page, and can walk the demo script. Demo accounts live in E2E / staging only.',
        'gridCorner' => 'Role',
        'gridCategory' => \App\Models\QaChecklistItem::DEMO,
        'gridRows' => \App\Services\Qa\QaReadiness::DEMO_ROLES,
        'gridCols' => \App\Services\Qa\QaReadiness::DEMO_STEPS,
        'cellKey' => fn ($row, $col) => $col,
    ])
</div>
