@props(['model', 'people', 'label' => null, 'tone' => 'text-zinc-500'])
{{--
    Searchable people picker for the notification settings: type to filter
    (name or email), tick to choose. Bound to a Livewire array property
    ($model) of user ids, so it works in any of the recipient forms.
--}}
<div x-data="{ q: '' }" {{ $attributes->class('min-w-0') }}>
    @if($label)
        <p class="mb-1.5 text-xs font-bold uppercase tracking-wider {{ $tone }}">{{ $label }}</p>
    @endif
    <input type="search" x-model="q" placeholder="Search name or email"
           class="mb-1.5 w-full rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
    <div class="max-h-44 space-y-0.5 overflow-y-auto rounded-xl border border-zinc-100 p-1.5 dark:border-zinc-800" data-people-picker="{{ $model }}">
        @forelse($people as $person)
            <label wire:key="pick-{{ $model }}-{{ $person->id }}" data-search="{{ strtolower($person->name.' '.$person->email) }}"
                   x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())"
                   class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-xs hover:bg-zinc-50 dark:hover:bg-white/5">
                <input type="checkbox" wire:model="{{ $model }}" value="{{ $person->id }}" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400" />
                <span class="truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $person->name }}</span>
                <span class="truncate text-[10px] text-zinc-400">{{ $person->email }}</span>
            </label>
        @empty
            <p class="px-2 py-1 text-xs text-zinc-400">No people.</p>
        @endforelse
    </div>
</div>
