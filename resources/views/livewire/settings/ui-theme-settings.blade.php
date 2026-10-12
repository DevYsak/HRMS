<x-pages::settings.layout :heading="__('Brand & Layout')" :subheading="__('The look of the whole panel — typography, layout, sidebar, components and colours. Preview before you save; defaults are the current NEXCORE design.')">
    @if($previewFontUrl)
        <link href="{{ $previewFontUrl }}" rel="stylesheet" />
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]" data-ui-theme-settings>
        {{-- ── Form ── --}}
        <div class="min-w-0 space-y-5">
            <div class="-mx-1 overflow-x-auto px-1">
                <div class="flex min-w-max gap-1 border-b border-zinc-200 dark:border-white/10" role="tablist">
                    @foreach(\App\Livewire\Settings\UiThemeSettings::TABS as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" data-ui-tab="{{ $key }}"
                            @class(['relative whitespace-nowrap px-3.5 py-2 text-sm font-semibold transition-colors', 'text-orange-600 dark:text-orange-400' => $tab === $key, 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $key])>
                            {{ $label }}
                            @if($tab === $key)<span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500"></span>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            @if($tab === 'branding')
                <div class="space-y-3">
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">Logos, sidebar icons and the favicon are uploaded on Company settings and already switch with light and dark mode.</p>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach($branding as $item)
                            <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700" data-branding-item>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $item['label'] }}</p>
                                <div class="mt-2 flex h-14 items-center justify-center rounded-lg {{ str_contains($item['label'], 'dark') ? 'bg-zinc-900' : 'bg-zinc-50' }}">
                                    @if($item['url'])
                                        <img src="{{ $item['url'] }}" alt="{{ $item['label'] }}" class="max-h-10 max-w-full object-contain">
                                    @else
                                        <span class="text-xs text-zinc-400">Default</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if(\Illuminate\Support\Facades\Route::has('settings.general'))
                        <flux:button size="sm" icon="photo" :href="route('settings.general')" wire:navigate>Manage logos &amp; favicon</flux:button>
                    @endif
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach($schema as $key => $field)
                        <div class="min-w-0" wire:key="ui-{{ $key }}" data-ui-field="{{ $key }}">
                            <label for="ui-{{ $key }}" class="mb-1.5 flex items-baseline justify-between gap-2 text-sm font-medium text-zinc-800 dark:text-white">
                                <span>{{ $field['label'] }}</span>
                                @if($field['type'] === 'px')<span class="text-xs font-normal tabular-nums text-zinc-500">{{ $values[$key] ?? $field['default'] }}px</span>@endif
                            </label>
                            @if($field['type'] === 'select')
                                <select id="ui-{{ $key }}" wire:model.live="values.{{ $key }}"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                    @foreach($field['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}{{ $value === $field['default'] ? ' (default)' : '' }}</option>
                                    @endforeach
                                </select>
                            @elseif($field['type'] === 'color')
                                <div class="flex items-center gap-2">
                                    <input id="ui-{{ $key }}" type="color" wire:model.live.debounce.200ms="values.{{ $key }}" class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ $field['label'] }}">
                                    <flux:input wire:model.live.debounce.400ms="values.{{ $key }}" maxlength="7" class="min-w-0 font-mono" aria-label="{{ $field['label'] }} hex value" />
                                </div>
                            @else
                                <input id="ui-{{ $key }}" type="range" min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="1" wire:model.live.debounce.150ms="values.{{ $key }}" class="w-full accent-orange-500">
                                <div class="mt-1 flex justify-between text-[10px] text-zinc-400"><span>{{ $field['min'] }}</span><span>default {{ $field['default'] }}</span><span>{{ $field['max'] }}</span></div>
                            @endif
                            @if(! empty($field['hint']))<p class="mt-1 text-xs text-zinc-500">{{ $field['hint'] }}</p>@endif
                            <flux:error name="values.{{ $key }}" />
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button variant="primary" wire:click="save" data-ui-save>Save brand &amp; layout</flux:button>
                <flux:button variant="ghost" wire:click="resetToDefaults" wire:confirm="Load the default NEXCORE design into the form? Nothing changes until you save.">Reset to defaults</flux:button>
                <span class="text-xs text-zinc-500" data-ui-changed>{{ $changedCount }} {{ \Illuminate\Support\Str::plural('setting', $changedCount) }} differ from the default design</span>
            </div>
        </div>

        {{-- ── Live preview (unsaved values, scoped to this box) ── --}}
        <aside class="min-w-0 xl:sticky xl:top-6 xl:self-start" aria-label="Live preview">
            <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-zinc-500">Live preview</p>
            <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700" style="{{ $preview }};--ui-surface-dark:var(--ui-surface);--ui-border-dark:var(--ui-border)" data-ui-preview>
                <div style="background: var(--pv-bg); font-family: var(--font-sans); font-size: var(--pv-font-size); line-height: var(--pv-line-height); font-weight: var(--pv-body-weight); color: #18181b;">
                    <div class="flex items-center justify-between border-b border-zinc-200 bg-white px-3" style="height: var(--pv-header-height);">
                        <span style="font-weight: var(--pv-heading-weight);">NEXCORE</span>
                        <span class="size-6 rounded-full" style="background: var(--pv-primary);"></span>
                    </div>
                    <div class="flex">
                        <div class="shrink-0 border-r border-zinc-200 bg-[#FFF8F1] p-2" style="width: var(--pv-sidebar-width);">
                            @foreach(['Dashboard', 'Attendance', 'Leave', 'Payroll'] as $i => $item)
                                <div class="flex items-center gap-2 rounded-lg px-2" style="height: var(--pv-sidebar-item); margin-block: var(--pv-sidebar-gap); font-size: var(--pv-sidebar-text); {{ $i === 0 ? 'background: var(--pv-primary-soft); color: var(--pv-primary-strong);' : 'color: #5C5349;' }}">
                                    <span class="shrink-0 rounded" style="width: var(--pv-sidebar-icon); height: var(--pv-sidebar-icon); background: var(--pv-primary); opacity: .85;"></span>
                                    <span class="truncate">{{ $item }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="min-w-0 flex-1 p-3" style="display: grid; gap: var(--ui-section-gap);">
                            <div style="font-size: var(--pv-heading-size); font-weight: var(--pv-heading-weight); line-height: 1.2;">Team View</div>
                            <div class="ui-surface" style="padding: var(--ui-card-padding);">
                                <div class="flex items-center justify-between gap-2">
                                    <span style="font-weight: var(--pv-heading-weight);">Present Today</span>
                                    <span class="ui-badge-shape px-2 py-0.5 text-[10px] font-bold uppercase" style="background: var(--pv-primary-soft); color: var(--pv-primary-strong);">Live</span>
                                </div>
                                <div class="mt-1" style="font-size: calc(var(--pv-font-size) * 2); font-weight: var(--pv-heading-weight);">42</div>
                                <span class="ui-badge-shape mt-1 inline-flex px-2 py-0.5 text-[10px] font-bold" style="background: #f4f4f5; color: var(--ui-secondary);">Secondary</span>
                            </div>
                            <div class="ui-surface overflow-hidden">
                                <table class="w-full text-left" style="font-size: var(--pv-font-size);">
                                    @foreach(['Ari Alpha', 'Bea Beta', 'Mona Manager'] as $name)
                                        <tr class="border-b border-zinc-100 last:border-0"><td class="px-3" style="padding-block: var(--pv-row-py);">{{ $name }}</td><td class="px-3 text-right" style="padding-block: var(--pv-row-py); color: var(--pv-primary-strong);">On time</td></tr>
                                    @endforeach
                                </table>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" value="Search…" readonly aria-label="Preview input" class="min-w-0 flex-1 border border-zinc-200 bg-white px-2 text-sm" style="height: var(--pv-input-height); border-radius: var(--pv-input-radius);">
                                <button type="button" class="px-3 text-sm font-semibold text-white" style="height: var(--pv-button-height); border-radius: var(--pv-button-radius); background: var(--pv-primary);" tabindex="-1">Approve</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="mt-2 text-xs text-zinc-500">The preview shows light mode with your unsaved choices. Saving applies them everywhere — sidebar, header, dashboards, attendance, leave, payroll, performance, profile, settings, tables, forms and modals.</p>
        </aside>
    </div>
</x-pages::settings.layout>
