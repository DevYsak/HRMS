@props(['node', 'grouped', 'visited' => []])

@php
    $visited = $visited ?? [];
    $canRender = isset($grouped[$node->user_id])
        && $grouped[$node->user_id]->count() > 0
        && !in_array($node->user_id, $visited);

    // Department → color palette
    $palette = [
        'indigo' => ['accent' => '#6366f1', 'ring' => 'ring-indigo-200', 'badge' => 'bg-indigo-50 text-indigo-600 ring-indigo-100 dark:bg-indigo-500/15 dark:text-indigo-300 dark:ring-indigo-500/20', 'border' => 'border-indigo-200 dark:border-indigo-500/30', 'dot' => 'bg-indigo-500', 'bar' => '#6366f1'],
        'violet' => ['accent' => '#7c3aed', 'ring' => 'ring-violet-200', 'badge' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/20', 'border' => 'border-violet-200 dark:border-violet-500/30', 'dot' => 'bg-brand-600', 'bar' => '#7c3aed'],
        'sky' => ['accent' => '#0284c7', 'ring' => 'ring-sky-200', 'badge' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/20', 'border' => 'border-sky-200 dark:border-sky-500/30', 'dot' => 'bg-sky-600', 'bar' => '#0284c7'],
        'emerald' => ['accent' => '#059669', 'ring' => 'ring-emerald-200', 'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/20', 'border' => 'border-emerald-200 dark:border-emerald-500/30', 'dot' => 'bg-emerald-600', 'bar' => '#059669'],
        'amber' => ['accent' => '#d97706', 'ring' => 'ring-amber-200', 'badge' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/20', 'border' => 'border-amber-200 dark:border-amber-500/30', 'dot' => 'bg-amber-500', 'bar' => '#d97706'],
        'rose' => ['accent' => '#e11d48', 'ring' => 'ring-rose-200', 'badge' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/20', 'border' => 'border-rose-200 dark:border-rose-500/30', 'dot' => 'bg-rose-600', 'bar' => '#e11d48'],
        'cyan' => ['accent' => '#0891b2', 'ring' => 'ring-cyan-200', 'badge' => 'bg-cyan-50 text-cyan-700 ring-cyan-100 dark:bg-cyan-500/15 dark:text-cyan-300 dark:ring-cyan-500/20', 'border' => 'border-cyan-200 dark:border-cyan-500/30', 'dot' => 'bg-cyan-600', 'bar' => '#0891b2'],
        'pink' => ['accent' => '#db2777', 'ring' => 'ring-pink-200', 'badge' => 'bg-pink-50 text-pink-700 ring-pink-100 dark:bg-pink-500/15 dark:text-pink-300 dark:ring-pink-500/20', 'border' => 'border-pink-200 dark:border-pink-500/30', 'dot' => 'bg-pink-600', 'bar' => '#db2777'],
    ];
    $paletteKeys = array_keys($palette);
@endphp

<div class="flex flex-col items-center">
    @if($canRender)
        @php
            $reports = $grouped[$node->user_id];
            $count = $reports->count();
            $visited[] = $node->user_id;
        @endphp

        {{-- Vertical stub down from parent node --}}
        <div class="h-8 w-px bg-slate-300 dark:bg-zinc-700"></div>

        {{-- The children row --}}
        <div class="relative flex items-start gap-8">

            @foreach($reports as $i => $report)
                @php
                    $deptName = $report->department?->name ?? 'default';
                    $colorKey = $paletteKeys[abs(crc32($deptName)) % count($paletteKeys)];
                    $color = $palette[$colorKey];
                    $subCount = isset($grouped[$report->user_id]) ? $grouped[$report->user_id]->count() : 0;
                @endphp

                <div class="group flex flex-col items-center">

                    {{-- Top connector: vertical stub coming down to this child --}}
                    @if($count === 1)
                        {{-- Single child: no horizontal bar, just a direct vertical line --}}
                        {{-- (parent already drew 32px, we just add a tiny bit more) --}}
                    @else
                        {{-- Multi-child: horizontal crossbar is built from absolute overlay later,
                        here we just draw a 24px vertical drop to the card --}}
                        <div class="h-6 w-px bg-slate-300 dark:bg-zinc-700"></div>
                    @endif

                    {{-- ── CHILD CARD ── --}}
                    <div class="relative w-60 cursor-default">
                        {{-- Hover glow --}}
                        <div class="absolute -inset-1 rounded-2xl opacity-0 blur-lg transition-opacity duration-500 group-hover:opacity-40"
                            style="background: {{ $color['accent'] }}40;"></div>

                        <div
                            class="relative overflow-hidden rounded-xl border-2 bg-white dark:bg-zinc-900 shadow-md shadow-slate-200/80 dark:shadow-black/40 transition-all duration-300 group-hover:-translate-y-0.5 group-hover:shadow-xl {{ $color['border'] }}">
                            {{-- Top color accent bar --}}
                            <div class="h-1 w-full" style="background: {{ $color['accent'] }};"></div>

                            <div class="flex items-center gap-3 p-3.5">
                                {{-- Avatar --}}
                                <div class="relative shrink-0">
                                    <img src="{{ $report->user->avatarUrl() }}"
                                        class="size-11 rounded-full border-2 border-white dark:border-zinc-900 object-cover shadow-sm ring-2 {{ $color['ring'] }}" />
                                    @if($subCount > 0)
                                        <div
                                            class="absolute -bottom-0.5 -right-0.5 flex size-[18px] items-center justify-center rounded-full border-2 border-white dark:border-zinc-900 text-[9px] font-black text-white {{ $color['dot'] }}">
                                            {{ $subCount }}
                                        </div>
                                    @endif
                                </div>

                                {{-- Info --}}
                                <div class="min-w-0 flex-1">
                                    <h4 class="truncate text-sm font-bold text-slate-800 dark:text-white">{{ $report->user->name }}</h4>
                                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider"
                                        style="color: {{ $color['accent'] }}">
                                        {{ $report->jobTitle?->name ?? 'Employee' }}
                                    </p>
                                    @if($report->department)
                                        <span
                                            class="mt-0.5 inline-block rounded-full px-1.5 py-px text-[9px] font-semibold ring-1 {{ $color['badge'] }}">
                                            {{ $report->department->name }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Recurse into grandchildren --}}
                    <x-org-node :node="$report" :grouped="$grouped" :visited="$visited" />
                </div>
            @endforeach

            {{-- Horizontal crossbar connecting all siblings (if >1 child) --}}
            @if($count > 1)
                {{-- Overlay the horizontal line at the very top of the children row.
                It stretches from the center of the first child to the center of the last,
                using absolute positioning inside the relative parent div. --}}
                <div class="pointer-events-none absolute inset-x-0 top-0 flex justify-center" style="height: 1px;">
                    <div class="h-px w-[calc(100%-240px)] bg-slate-300 dark:bg-zinc-700" style="margin: 0 120px;"></div>
                </div>
            @endif
        </div>
    @endif
</div>