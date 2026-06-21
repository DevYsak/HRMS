<div>
    @php
        $filters = [
            'all' => 'All', 'leave' => 'Leave', 'ot' => 'OT',
            'attendance' => 'Attendance', 'payroll' => 'Payroll',
        ];
        $typeColors = [
            'leave' => 'blue', 'ot' => 'amber', 'regularisation' => 'indigo', 'encashment' => 'emerald',
        ];
    @endphp

    <x-pulse.card title="Approval Command Center" subtitle="Action requests without leaving the dashboard" icon="check-badge">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">
                {{ $counts['all'] }} pending
            </span>
        </x-slot:actions>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach($filters as $key => $label)
                <button type="button" wire:click="setFilter('{{ $key }}')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition',
                        'bg-brand-500 text-white shadow-sm shadow-brand-500/30' => $filter === $key,
                        'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' => $filter !== $key,
                    ])>
                    {{ $label }}
                    @php $c = $counts[$key] ?? 0; @endphp
                    @if($c > 0)
                        <span @class([
                            'rounded-full px-1.5 text-[10px]',
                            'bg-white/25' => $filter === $key,
                            'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $filter !== $key,
                        ])>{{ $c }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Queue --}}
        <div class="space-y-2.5" wire:loading.class="opacity-50" wire:target="approve,reject,setFilter">
            @forelse($rows as $row)
                <div wire:key="{{ $row['type'] }}-{{ $row['id'] }}"
                    class="flex flex-col gap-3 rounded-2xl border border-zinc-100 p-3.5 transition-all hover:border-zinc-200 hover:shadow-sm dark:border-white/5 dark:hover:border-white/10 sm:flex-row sm:items-center">
                    {{-- Employee --}}
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-[13px] font-bold text-brand-700 dark:bg-brand-500/15 dark:text-brand-400">
                            {{ \Illuminate\Support\Str::of($row['name'])->explode(' ')->take(2)->map(fn($p) => $p[0] ?? '')->implode('') }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $row['name'] }}</span>
                                <x-pulse.badge :color="$typeColors[$row['type']] ?? 'zinc'">{{ $row['type_label'] }}</x-pulse.badge>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] font-medium text-zinc-400">
                                <span>{{ $row['dept'] }}</span>
                                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                <span>{{ $row['date'] }}</span>
                                <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                <x-pulse.badge :color="$row['priority_color']">{{ $row['priority'] }}</x-pulse.badge>
                            </div>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex shrink-0 items-center gap-1.5">
                        <button type="button" wire:click="approve('{{ $row['type'] }}', {{ $row['id'] }})"
                            wire:confirm="Approve this {{ strtolower($row['type_label']) }} request?"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 transition hover:bg-emerald-600 hover:text-white dark:bg-emerald-500/10 dark:text-emerald-400 dark:hover:bg-emerald-600 dark:hover:text-white">
                            <flux:icon.check class="size-3.5" /> Approve
                        </button>
                        <button type="button" wire:click="reject('{{ $row['type'] }}', {{ $row['id'] }})"
                            wire:confirm="Reject this {{ strtolower($row['type_label']) }} request?"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-600 hover:text-white dark:bg-rose-500/10 dark:text-rose-400 dark:hover:bg-rose-600 dark:hover:text-white">
                            <flux:icon.x-mark class="size-3.5" /> Reject
                        </button>
                        <a href="{{ $row['view'] }}" wire:navigate
                            class="inline-flex size-8 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200" title="View details">
                            <flux:icon.arrow-up-right class="size-4" />
                        </a>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon.check-circle class="mb-2.5 size-9 text-emerald-300 dark:text-emerald-500/40" />
                    <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">All caught up — no {{ $filter === 'all' ? '' : $filters[$filter].' ' }}requests pending.</p>
                </div>
            @endforelse
        </div>

        @if($visibleTotal > 3)
            @php
                $viewAll = match($filter) {
                    'ot' => \Route::has('overtime.manage') ? route('overtime.manage') : '#',
                    'attendance' => \Route::has('attendance.employees') ? route('attendance.employees') : '#',
                    'payroll' => \Route::has('time-off.encashments') ? route('time-off.encashments') : '#',
                    default => \Route::has('time-off.employees') ? route('time-off.employees') : '#',
                };
            @endphp
            <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-white/5">
                <a href="{{ $viewAll }}" wire:navigate
                    class="flex items-center justify-center gap-1.5 rounded-xl py-2 text-xs font-bold text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">
                    View all {{ $visibleTotal }} requests <flux:icon.arrow-right class="size-3.5" />
                </a>
            </div>
        @endif
    </x-pulse.card>
</div>
