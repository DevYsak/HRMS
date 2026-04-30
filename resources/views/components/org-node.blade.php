@props(['node', 'grouped', 'visited' => []])

@php
    $visited    = $visited ?? [];
    $canRender  = isset($grouped[$node->user_id])
        && $grouped[$node->user_id]->count() > 0
        && ! in_array($node->user_id, $visited);

    // Department → color palette
    $palette = [
        'indigo'   => ['border' => '#6366f1', 'bg' => 'rgba(99,102,241,0.08)',  'badge' => 'bg-indigo-500/20 text-indigo-300',  'dot' => 'bg-indigo-400'],
        'violet'   => ['border' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.08)', 'badge' => 'bg-violet-500/20 text-violet-300',  'dot' => 'bg-violet-400'],
        'sky'      => ['border' => '#0ea5e9', 'bg' => 'rgba(14,165,233,0.08)', 'badge' => 'bg-sky-500/20 text-sky-300',        'dot' => 'bg-sky-400'],
        'emerald'  => ['border' => '#10b981', 'bg' => 'rgba(16,185,129,0.08)', 'badge' => 'bg-emerald-500/20 text-emerald-300','dot' => 'bg-emerald-400'],
        'amber'    => ['border' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.08)', 'badge' => 'bg-amber-500/20 text-amber-300',    'dot' => 'bg-amber-400'],
        'rose'     => ['border' => '#f43f5e', 'bg' => 'rgba(244,63,94,0.08)',  'badge' => 'bg-rose-500/20 text-rose-300',      'dot' => 'bg-rose-400'],
        'cyan'     => ['border' => '#06b6d4', 'bg' => 'rgba(6,182,212,0.08)',  'badge' => 'bg-cyan-500/20 text-cyan-300',      'dot' => 'bg-cyan-400'],
        'pink'     => ['border' => '#ec4899', 'bg' => 'rgba(236,72,153,0.08)', 'badge' => 'bg-pink-500/20 text-pink-300',      'dot' => 'bg-pink-400'],
    ];
    $paletteKeys = array_keys($palette);
@endphp

<div class="flex flex-col items-center">
    @if($canRender)
        @php
            $reports  = $grouped[$node->user_id];
            $count    = $reports->count();
            $visited[] = $node->user_id;
        @endphp

        {{-- Vertical connector from parent --}}
        <div class="h-8 w-px bg-gradient-to-b from-zinc-600 to-zinc-700"></div>

        {{-- Horizontal bar --}}
        <div class="relative flex w-full justify-center">
            @if($count > 1)
                <div class="absolute top-0 h-px bg-zinc-700"
                     style="width: calc(100% - {{ round(100 / $count) }}%); left: {{ round(50 / $count) }}%;"></div>
            @endif

            <div class="flex gap-10 justify-center">
                @foreach($reports as $report)
                    @php
                        $deptName   = $report->department?->name ?? 'default';
                        $colorKey   = $paletteKeys[abs(crc32($deptName)) % count($paletteKeys)];
                        $color      = $palette[$colorKey];
                        $subReports = isset($grouped[$report->user_id]) ? $grouped[$report->user_id]->count() : 0;
                    @endphp

                    <div class="group flex flex-col items-center">

                        {{-- Vertical connector to child --}}
                        <div class="h-6 w-px bg-gradient-to-b from-zinc-700 to-zinc-600"></div>

                        {{-- ── CHILD NODE CARD ── --}}
                        <div class="relative w-64 cursor-default transition-all duration-300 hover:-translate-y-1">

                            {{-- Glow on hover --}}
                            <div class="absolute -inset-px rounded-xl opacity-0 blur-md transition-opacity duration-500 group-hover:opacity-60"
                                 style="background: {{ $color['border'] }}30"></div>

                            <div class="relative overflow-hidden rounded-xl border bg-zinc-900 shadow-lg transition-all duration-300 group-hover:shadow-2xl"
                                 style="border-color: {{ $color['border'] }}40; background: {{ $color['bg'] }};">

                                {{-- Left accent bar --}}
                                <div class="absolute inset-y-0 left-0 w-0.5 rounded-l-xl"
                                     style="background: {{ $color['border'] }}"></div>

                                <div class="flex items-center gap-3 p-3.5 pl-4">
                                    {{-- Avatar --}}
                                    <div class="relative shrink-0">
                                        <div class="absolute -inset-0.5 rounded-full opacity-60"
                                             style="background: linear-gradient(135deg, {{ $color['border'] }}, transparent)"></div>
                                        <img src="{{ $report->user->avatarUrl() }}"
                                            class="relative size-11 rounded-full border border-zinc-800 object-cover" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <h4 class="truncate text-sm font-bold text-white">
                                            {{ $report->user->name }}
                                        </h4>
                                        <p class="truncate text-[10px] font-bold uppercase tracking-wider"
                                           style="color: {{ $color['border'] }}">
                                            {{ $report->jobTitle?->name ?? 'Employee' }}
                                        </p>
                                        @if($report->department)
                                            <span class="mt-0.5 inline-block rounded-full px-1.5 py-px text-[9px] font-bold {{ $color['badge'] }}">
                                                {{ $report->department->name }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Sub-reports badge --}}
                                    @if($subReports > 0)
                                        <div class="flex size-6 shrink-0 items-center justify-center rounded-full text-[9px] font-black text-white"
                                             style="background: {{ $color['border'] }}50; border: 1px solid {{ $color['border'] }}40">
                                            {{ $subReports }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Recurse --}}
                        <x-org-node :node="$report" :grouped="$grouped" :visited="$visited" />
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
