@props([
    'model',                 // Livewire property name to bind
    'options' => [],         // array of ['value' => .., 'label' => ..]
    'placeholder' => null,   // when set, also shown as a selectable "clear" row
    'label' => null,
    'live' => true,          // push updates to the server on change
    'required' => false,
])

{{-- Clean, theme-aware dropdown (Flux Free's <select> falls back to the native
     OS list, which looks unstyled; this Alpine listbox stays on-brand). --}}
<div
    x-data="{
        open: false,
        value: @entangle($model){{ $live ? '.live' : '' }},
        options: {{ \Illuminate\Support\Js::from(collect($options)->map(fn ($o) => ['value' => (string) $o['value'], 'label' => $o['label']])->values()) }},
        get selectedLabel() {
            const o = this.options.find((o) => o.value === String(this.value ?? ''));
            return o ? o.label : null;
        },
        get hasEmptyOption() { return this.options.some((o) => o.value === ''); },
        choose(v) { this.value = v; this.open = false; },
    }"
    {{ $attributes->merge(['class' => 'relative']) }}
    @click.outside="open = false"
    @keydown.escape.stop="open = false"
>
    @if($label)
        <label class="mb-1 block text-sm font-medium text-zinc-800 dark:text-zinc-200">
            {{ $label }}@if($required)<span class="text-brand-500">*</span>@endif
        </label>
    @endif

    <button
        type="button"
        @click="open = !open"
        class="flex w-full min-w-[5rem] items-center justify-between gap-2 rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-left text-sm transition hover:border-zinc-300 focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-400/20 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
        :class="open && 'border-brand-400 ring-2 ring-brand-400/20'"
    >
        <span
            x-text="selectedLabel || @js($placeholder ?? 'Select…')"
            :class="selectedLabel ? 'text-zinc-800 dark:text-zinc-100 font-medium' : 'text-zinc-400'"
            class="truncate"
        ></span>
        <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 transition" x-bind:class="open && 'rotate-180'" />
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1"
        x-cloak
        class="absolute z-50 mt-1.5 max-h-60 w-full overflow-y-auto rounded-xl border border-zinc-100 bg-white p-1 shadow-lg ring-1 ring-black/5 dark:border-zinc-700 dark:bg-zinc-800"
    >
        @if(! is_null($placeholder))
            {{-- Selectable "clear" row — preserves the native <option value=""> prompt --}}
            <button
                type="button"
                x-show="! hasEmptyOption"
                @click="choose('')"
                class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm text-zinc-400 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/40"
                :class="String(value ?? '') === '' && 'bg-brand-50 font-semibold text-brand-600 dark:bg-brand-900/20 dark:text-brand-300'"
            >
                <span class="truncate">{{ $placeholder }}</span>
                <flux:icon.check class="size-4 shrink-0 text-brand-500" x-show="String(value ?? '') === ''" />
            </button>
        @endif
        <template x-for="o in options" :key="o.value">
            <button
                type="button"
                @click="choose(o.value)"
                class="flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm text-zinc-700 transition hover:bg-brand-50 dark:text-zinc-200 dark:hover:bg-brand-900/20"
                :class="o.value === String(value ?? '') && 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-900/20 dark:text-brand-300'"
            >
                <span x-text="o.label" class="truncate"></span>
                <flux:icon.check class="size-4 shrink-0 text-brand-500" x-show="o.value === String(value ?? '')" />
            </button>
        </template>
        <p x-show="options.length === 0" class="px-3 py-2 text-sm text-zinc-400">No options available.</p>
    </div>
</div>
