@props([
    'columns' => [],        // array<int, string|array{label: string, class?: string}>
    'min' => 'min-w-[560px]',
])

{{-- Dashboard table: 11px uppercase headers, 13px rows, scrolls inside its card
     on narrow screens instead of widening the page. --}}
<div {{ $attributes->class('overflow-x-auto') }}>
    <table class="w-full {{ $min }} text-left text-[13px]">
        <thead>
            <tr class="border-y border-zinc-100 bg-zinc-50/70 dark:border-white/5 dark:bg-white/[0.03]">
                @foreach($columns as $column)
                    @php
                        $label = is_array($column) ? $column['label'] : $column;
                        $class = is_array($column) ? ($column['class'] ?? '') : '';
                    @endphp
                    <th scope="col" class="px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 first:pl-5 last:pr-5 dark:text-zinc-400 {{ $class }}">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
            {{ $slot }}
        </tbody>
    </table>
</div>
