{{-- How to read a punch: a quiet, static legend at the top of attendance
     screens. Three short facts fit on one line, so nothing scrolls or is cut
     off; on narrow screens the items wrap. --}}
@php
    $items = [
        ['icon' => 'face-smile', 'tone' => 'text-emerald-600 dark:text-emerald-400', 'text' => 'Face = Biometric IN'],
        ['icon' => 'identification', 'tone' => 'text-sky-600 dark:text-sky-400', 'text' => 'ID Card = Biometric OUT'],
        ['icon' => 'pencil-square', 'tone' => 'text-amber-600 dark:text-amber-400', 'text' => 'Regularised punches are manually corrected attendance entries'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 text-xs text-zinc-600 dark:border-white/10 dark:bg-white/[0.03] dark:text-zinc-400']) }}
     role="note" data-biometric-notice>
    <span class="inline-flex items-center gap-1.5 font-semibold text-zinc-800 dark:text-zinc-200">
        <flux:icon.information-circle class="size-4 text-zinc-400 dark:text-zinc-500" />
        Punch guide
    </span>

    @foreach ($items as $item)
        <span class="inline-flex items-center gap-1.5">
            <flux:icon :icon="$item['icon']" class="size-4 shrink-0 {{ $item['tone'] }}" />
            {{ $item['text'] }}
        </span>
    @endforeach
</div>
