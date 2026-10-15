<div class="space-y-3" data-tab-panel="responsive">
    <div class="inline-flex rounded-lg bg-white p-1 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10" role="group" aria-label="Theme">
        @foreach(['light' => 'Light', 'dark' => 'Dark'] as $key => $label)
            <button type="button" wire:click="$set('theme', '{{ $key }}')" @class([
                'rounded-md px-3 py-1 text-sm font-medium',
                'bg-orange-500 text-white' => $theme === $key,
                'text-zinc-600 dark:text-zinc-300' => $theme !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @include('livewire.qa.partials.grid', [
        'gridIntro' => 'Key pages at each viewport width, '.($theme === 'dark' ? 'dark' : 'light').' theme. No horizontal page scroll, readable text, reachable actions.',
        'gridCorner' => 'Page',
        'gridCategory' => \App\Models\QaChecklistItem::RESPONSIVE,
        'gridRows' => collect(\App\Services\Qa\QaReadiness::RESPONSIVE_PAGES)->mapWithKeys(fn ($p) => [$p => $p])->all(),
        'gridCols' => collect(\App\Services\Qa\QaReadiness::VIEWPORTS)->mapWithKeys(fn ($w) => [$w => $w.'px'])->all(),
        'cellKey' => fn ($row, $col) => $col.':'.($theme === 'dark' ? 'dark' : 'light'),
    ])
</div>
