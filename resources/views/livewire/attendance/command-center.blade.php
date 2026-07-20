<flux:main class="min-h-screen space-y-5 bg-[#FFF8F3] p-4 dark:bg-zinc-950 md:p-6">

    {{-- ═══════════════ HEADER ═══════════════ --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Attendance Command Center</h1>
            <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
                <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Command Center</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" />
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee…"
                    class="w-52 rounded-xl border border-orange-100 bg-white py-2 pl-8 pr-2.5 text-xs font-semibold text-zinc-600 shadow-sm placeholder:text-zinc-400 focus:border-orange-400 focus:ring-0 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
            </div>
            <button wire:click="exportPending" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export</button>
            <a href="{{ route('attendance.employees') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.users class="size-4 text-orange-500" /> All Attendance</a>
        </div>
    </div>

    {{-- ═══════════════ ATTENDANCE HEALTH (engine-scored context) ═══════════════ --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @php
            $mtd = $health['mtd_score'];
            $scoreTone = $mtd === null ? 'text-zinc-400' : ($mtd >= 85 ? 'text-emerald-600' : ($mtd >= 60 ? 'text-amber-600' : 'text-rose-500'));
            $healthCards = [
                ['Present Today', $health['present_today'].'/'.$health['active'], 'check-circle', '#10b981', 'clocked in'],
                ['Late Today', $health['late_today'], 'exclamation-triangle', '#f59e0b', 'past grace'],
                ['Absent Today', $health['absent_today'], 'x-circle', '#ef4444', 'no punch yet'],
                ['MTD Score', ($mtd ?? '—').($mtd !== null ? '/100' : ''), 'shield-check', '#F97316', 'engine average'],
                ['At Risk', $health['at_risk'], 'shield-exclamation', '#f43f5e', 'score below 60'],
                ['Excellent', $health['bands']['excellent'], 'trophy', '#8b5cf6', 'score 90+'],
            ];
        @endphp
        @foreach($healthCards as [$label, $value, $icon, $color, $sub])
            <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-3.5 shadow-sm">
                <span class="inline-flex size-8 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
                <div class="mt-2 text-xl font-black tabular-nums {{ $label === 'MTD Score' ? $scoreTone : 'text-zinc-900 dark:text-white' }}">{{ $value }}</div>
                <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                <div class="text-[9px] text-zinc-400">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- ═══════════════ OPERATIONS OVERVIEW ═══════════════ --}}
    @php
        $totalPending = array_sum($counts);
        $needAttention = collect($counts)->filter(fn ($c) => $c > 0)->count();
        $totalDecided = ($decided['approved'] ?? 0) + ($decided['rejected'] ?? 0);
        $approvalRate = $totalDecided > 0 ? round(($decided['approved'] / $totalDecided) * 100) : 0;
    @endphp
    <div class="relative overflow-hidden rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 via-amber-50/60 to-white p-6 shadow-sm dark:border-zinc-800 dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-900">
        <div class="pointer-events-none absolute -right-16 -top-16 size-56 rounded-full bg-orange-200/40 blur-3xl dark:bg-orange-900/10"></div>
        <div class="relative flex flex-col gap-6">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-black text-zinc-900 dark:text-white">Operations Overview</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Every attendance request — regularization, leave, WFH, overtime and holiday work — in one place.</p>
                </div>
                @if($totalPending > 0)
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-orange-500/10 px-3 py-1 text-xs font-bold text-orange-600 dark:text-orange-400">
                        <span class="size-1.5 animate-pulse rounded-full bg-orange-500"></span>
                        {{ $totalPending }} awaiting action
                    </span>
                @else
                    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                        <flux:icon.check-circle class="size-3.5" /> All caught up
                    </span>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @php
                    $overviewKpis = [
                        ['label' => 'Total Pending', 'value' => $totalPending, 'sub' => $needAttention.' of '.count($counts).' categories', 'icon' => 'inbox-stack', 'color' => '#f97316'],
                        ['label' => 'Approved', 'value' => $decided['approved'] ?? 0, 'sub' => 'All-time decisions', 'icon' => 'check-circle', 'color' => '#10b981'],
                        ['label' => 'Rejected', 'value' => $decided['rejected'] ?? 0, 'sub' => 'All-time decisions', 'icon' => 'x-circle', 'color' => '#ef4444'],
                        ['label' => 'Approval Rate', 'value' => $approvalRate.'%', 'sub' => $totalDecided.' decided', 'icon' => 'chart-pie', 'color' => '#8b5cf6'],
                    ];
                @endphp
                @foreach($overviewKpis as $kpi)
                    <div class="flex items-center gap-3 rounded-xl border border-white/70 bg-white/70 px-4 py-3 shadow-sm backdrop-blur dark:border-zinc-700/60 dark:bg-zinc-800/60">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-lg" style="background: {{ $kpi['color'] }}1a">
                            <flux:icon :icon="$kpi['icon']" class="size-5" style="color: {{ $kpi['color'] }}" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $kpi['value'] }}</p>
                            <p class="truncate text-[10px] font-bold uppercase tracking-wide text-zinc-400">{{ $kpi['label'] }}</p>
                            <p class="truncate text-[10px] text-zinc-400">{{ $kpi['sub'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ═══════════════ REQUEST CATEGORIES ═══════════════ --}}
    @php
        $tabs = [
            'regularisation' => ['Regularization', 'pencil-square', '#F97316'],
            'leave' => ['Leave', 'calendar-days', '#8b5cf6'],
            'wfh' => ['Work From Home', 'home', '#3b82f6'],
            'overtime' => ['Overtime', 'bolt', '#f59e0b'],
            'holiday' => ['Holiday Work', 'briefcase', '#14b8a6'],
        ];
    @endphp
    <div>
        <p class="mb-3 text-[11px] font-black uppercase tracking-widest text-zinc-400">Request Categories</p>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($tabs as $key => [$label, $icon, $color])
                @php $active = $tab === $key; @endphp
                <button wire:click="$set('tab', '{{ $key }}')"
                    class="group relative flex flex-col gap-2 rounded-2xl border p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md {{ $active ? 'border-orange-400 bg-white ring-1 ring-orange-200 dark:border-orange-500 dark:bg-zinc-900 dark:ring-orange-900/40' : 'border-orange-100/70 bg-white dark:border-zinc-800 dark:bg-zinc-900' }}">
                    @if($active)<span class="absolute right-3 top-3 size-2 rounded-full bg-orange-500"></span>@endif
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-5" /></span>
                    <div>
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $counts[$key] }}</span>
                            <span class="text-[10px] font-bold uppercase text-zinc-400">pending</span>
                        </div>
                        <div class="text-xs font-bold text-zinc-600 dark:text-zinc-300">{{ $label }}</div>
                    </div>
                    <span class="text-[10px] font-bold {{ $active ? 'text-orange-500' : 'text-zinc-400 group-hover:text-orange-500' }}">
                        {{ $active ? 'Reviewing ↓' : 'Review →' }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- ═══════════════ QUEUE + ACTIVITY FEED ═══════════════ --}}
    <div id="queue" class="grid grid-cols-1 items-start gap-4 scroll-mt-6 lg:grid-cols-12">

        {{-- ── Pending / history list for the active category ── --}}
        <div class="overflow-hidden rounded-2xl border border-orange-100/70 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-8">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3 dark:border-zinc-800">
                <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white">
                    <span class="inline-flex size-7 items-center justify-center rounded-lg" style="background: {{ $tabs[$tab][2] }}1a; color: {{ $tabs[$tab][2] }};"><flux:icon :icon="$tabs[$tab][1]" class="size-4" /></span>
                    {{ $tabs[$tab][0] }}
                    <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ count($items) }} shown</span>
                </h3>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Status / history filter --}}
                    <div class="flex items-center rounded-xl border border-orange-100 bg-white p-0.5 text-[11px] font-bold shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $val => $label)
                            <button wire:click="$set('statusFilter', '{{ $val }}')"
                                class="rounded-lg px-2.5 py-1 transition {{ $statusFilter === $val ? 'bg-orange-500 text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <button wire:click="selectAll" class="text-xs font-bold text-orange-500 hover:underline">Select all</button>
                    @if(count($selected))<button wire:click="clearSelection" class="text-xs font-bold text-zinc-400 hover:underline">Clear ({{ count($selected) }})</button>@endif
                </div>
            </div>

            {{-- Bulk action bar --}}
            @if(count($selected))
                <div class="flex flex-wrap items-center gap-2 border-b border-orange-100/70 bg-orange-50/60 px-5 py-2.5 dark:border-zinc-800 dark:bg-orange-900/10">
                    <span class="text-xs font-black text-zinc-800 dark:text-zinc-100">{{ count($selected) }} selected</span>
                    <button wire:click="bulkApprove" wire:loading.attr="disabled" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-emerald-600 disabled:opacity-50"><flux:icon.check class="size-3.5" /> Bulk Approve</button>
                    <input type="text" wire:model="rejectComment" placeholder="Rejection comment (required for reject)…"
                        class="min-w-0 flex-1 rounded-lg border border-orange-200 bg-white px-2.5 py-1.5 text-xs focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900">
                    <button wire:click="bulkReject" wire:loading.attr="disabled" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-3 py-1.5 text-[11px] font-bold text-white transition hover:bg-rose-600 disabled:opacity-50"><flux:icon.x-mark class="size-3.5" /> Bulk Reject</button>
                </div>
            @endif

            @if(count($items) > 0)
                <div class="divide-y divide-orange-50 dark:divide-zinc-800/60" wire:loading.class="opacity-50" wire:target="tab,search,statusFilter,bulkApprove,bulkReject">
                    @foreach($items as $item)
                        @php $isPending = ($item['status'] ?? 'pending') === 'pending'; @endphp
                        <div class="flex flex-wrap items-center gap-3 px-5 py-3 transition hover:bg-orange-50/40 dark:hover:bg-zinc-800/30">
                            @if($isPending)
                                <input type="checkbox" wire:model.live="selected" value="{{ $item['id'] }}"
                                    class="size-4 rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                            @else
                                <span class="size-4"></span>
                            @endif
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-amber-500 text-[10px] font-black uppercase text-white shadow-sm">
                                {{ \Illuminate\Support\Str::of($item['employee'])->explode(' ')->map(fn ($n) => $n[0] ?? '')->take(2)->join('') }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                    <span class="text-xs font-black text-zinc-900 dark:text-white">{{ $item['employee'] }}</span>
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $item['when'] }}</span>
                                    <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[9px] font-bold text-orange-600 dark:bg-orange-900/20 dark:text-orange-400">{{ $item['detail'] }}</span>
                                </div>
                                @if($item['reason'])<p class="mt-0.5 truncate text-[10px] italic text-zinc-400">“{{ $item['reason'] }}”</p>@endif
                            </div>
                            <div class="flex shrink-0 gap-1.5">
                                @if($isPending)
                                    <button wire:click="approveOne('{{ $tab }}', {{ $item['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-emerald-600"><flux:icon.check class="size-3" /> Approve</button>
                                    <button wire:click="rejectOne('{{ $tab }}', {{ $item['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-rose-600"><flux:icon.x-mark class="size-3" /> Reject</button>
                                @else
                                    @php $sc = ($item['status'] ?? '') === 'approved' ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400' : 'bg-rose-50 text-rose-500 dark:bg-rose-900/20 dark:text-rose-400'; @endphp
                                    <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-[10px] font-bold uppercase {{ $sc }}">{{ str_replace('_', ' ', $item['status'] ?? '—') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if(! count($selected))
                    <div class="border-t border-orange-100/70 bg-orange-50/30 px-5 py-2 text-[10px] text-zinc-400 dark:border-zinc-800 dark:bg-zinc-800/20">Tip: rejections require a comment — tick items to open the bulk bar, or type a comment there first for single rejects.</div>
                @endif
            @else
                <div class="py-14 text-center text-sm text-zinc-400"><flux:icon.check-badge class="mx-auto mb-2 size-9 text-emerald-300" /> {{ $statusFilter === 'pending' ? 'Nothing pending in '.$tabs[$tab][0].' — all caught up.' : 'No '.$statusFilter.' requests found'.($search ? ' for “'.$search.'”' : '').'.' }}</div>
            @endif
        </div>

        {{-- ── Activity feed ── --}}
        <div class="rounded-2xl border border-orange-100/70 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-4">
            <div class="mb-3 flex items-center gap-2">
                <span class="inline-flex size-8 items-center justify-center rounded-xl bg-orange-50 text-orange-500 dark:bg-orange-900/20"><flux:icon.clock class="size-4" /></span>
                <div class="text-sm font-black text-zinc-900 dark:text-white">Recent Activity</div>
            </div>
            @if(count($feed) > 0)
                <div class="relative space-y-3">
                    @foreach($feed as $f)
                        @php $ok = $f['status'] === 'approved'; @endphp
                        <div class="relative flex items-start gap-3">
                            @unless($loop->last)<span class="absolute left-[9px] top-6 h-full w-px bg-orange-100 dark:bg-zinc-800"></span>@endunless
                            <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-rose-500' }} text-white"><flux:icon :icon="$ok ? 'check' : 'x-mark'" class="size-3" /></span>
                            <div class="min-w-0 flex-1 text-xs">
                                <span class="font-black text-zinc-900 dark:text-white">{{ $f['employee'] }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400">— {{ $f['type'] }} {{ $f['status'] }}</span>
                                <div class="text-[10px] text-zinc-400">
                                    @if($f['reviewer'])by {{ $f['reviewer'] }} · @endif{{ $f['at'] ? \Carbon\Carbon::parse($f['at'])->diffForHumans() : '' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="py-6 text-center text-xs text-zinc-400">No decisions yet.</p>
            @endif
        </div>
    </div>

    {{-- ═══════════════ RECENT NEW REQUESTS (latest incoming, all categories) ═══════════════ --}}
    @php
        $typeStyles = [
            'Regularization' => ['pencil-square', '#F97316'],
            'Leave' => ['calendar-days', '#8b5cf6'],
            'WFH' => ['home', '#3b82f6'],
            'Overtime' => ['bolt', '#f59e0b'],
            'Holiday Work' => ['briefcase', '#14b8a6'],
        ];
    @endphp
    <div class="rounded-2xl border border-orange-100/70 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3 dark:border-zinc-800">
            <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white">
                <span class="inline-flex size-7 items-center justify-center rounded-lg bg-orange-50 text-orange-500 dark:bg-orange-900/20"><flux:icon.sparkles class="size-4" /></span>
                Recent New Requests
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· latest incoming</span>
            </h3>
            <span class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">{{ count($incoming) }} new</span>
        </div>
        @if(count($incoming) > 0)
            <div class="grid grid-cols-1 gap-px bg-orange-50 dark:bg-zinc-800/60 sm:grid-cols-2">
                @foreach($incoming as $row)
                    @php [$rIcon, $rColor] = $typeStyles[$row['type']] ?? ['inbox', '#71717a']; @endphp
                    <button type="button" wire:click="$set('tab', '{{ $row['tab'] }}')"
                        class="group flex items-center gap-3 bg-white px-5 py-3 text-left transition hover:bg-orange-50/50 dark:bg-zinc-900 dark:hover:bg-zinc-800/40">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $rColor }}1a; color: {{ $rColor }};"><flux:icon :icon="$rIcon" class="size-4.5" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="text-xs font-black text-zinc-900 dark:text-white">{{ $row['employee'] }}</span>
                                <span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold" style="background: {{ $rColor }}1a; color: {{ $rColor }};">{{ $row['type'] }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $row['detail'] }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-[10px] font-semibold text-zinc-400">{{ $row['at'] ? \Carbon\Carbon::parse($row['at'])->diffForHumans(short: true) : '' }}</p>
                            <span class="text-[10px] font-bold text-zinc-300 transition group-hover:text-orange-500">Review →</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @else
            <div class="py-10 text-center text-sm text-zinc-400"><flux:icon.inbox class="mx-auto mb-2 size-8 text-zinc-200 dark:text-zinc-700" /> No new requests waiting.</div>
        @endif
    </div>

</flux:main>
