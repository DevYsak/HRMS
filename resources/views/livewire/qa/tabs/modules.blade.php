<div data-tab-panel="modules">
    @include('livewire.qa.partials.grid', [
        'gridIntro' => 'Each module across eight readiness dimensions. Unchecked cells are NOT TESTED and count against Module Readiness.',
        'gridCorner' => 'Module',
        'gridCategory' => \App\Models\QaChecklistItem::MODULE,
        'gridRows' => collect(\App\Services\Qa\QaReadiness::MODULES)->mapWithKeys(fn ($m) => [$m => $m])->all(),
        'gridCols' => \App\Services\Qa\QaReadiness::MODULE_DIMENSIONS,
        'cellKey' => fn ($row, $col) => $col,
    ])
</div>
