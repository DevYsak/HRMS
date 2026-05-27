<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950">

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    PAGE HEADER
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div class="pulse-hero">
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]">
        </div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl"
            style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-1.5 text-xs text-white/50 mb-1.5">
                    <span>Attendance</span>
                    <flux:icon.chevron-right class="size-3" />
                    <span class="text-white/80 font-semibold">Employees Attendance</span>
                </div>
                <h1 class="text-2xl font-black text-white tracking-tight">Employees Attendance</h1>
                <p class="text-sm text-white/55 mt-0.5">Today â€” {{ now()->format('l, d M Y') }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('reports.attendance-summary', ['month' => now()->month, 'year' => now()->year]) }}"
                    target="_blank"
                    class="flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 border border-white/10 text-white rounded-xl text-sm font-semibold transition">
                    <flux:icon.arrow-down-tray class="size-4" /> Export
                </a>
                <button wire:click="openMarkModal"
                    class="flex items-center gap-2 px-4 py-2 bg-white text-orange-700 hover:bg-orange-50 rounded-xl text-sm font-bold transition shadow-sm">
                    <flux:icon.pencil-square class="size-4" /> Mark Attendance
                </button>
            </div>
        </div>
    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    KPI STATS BAR
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <div
        class="space-y-4 m-[30px] rounded-[10px] grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-0 border-b border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900">
        @php
            $kpis = [
                ['label' => 'Total Employees', 'value' => $stats['total'], 'sub' => 'active workforce', 'color' => 'text-zinc-900 dark:text-white', 'icon' => 'users', 'bg' => 'bg-zinc-100 dark:bg-zinc-800'],
                ['label' => 'Present Today', 'value' => $stats['present'], 'sub' => $stats['present_pct'] . '% of total', 'color' => 'text-emerald-600', 'icon' => 'check-circle', 'bg' => 'bg-emerald-50 dark:bg-emerald-950/40'],
                ['label' => 'Absent', 'value' => $stats['absent'], 'sub' => $stats['absent_pct'] . '% of total', 'color' => 'text-rose-500', 'icon' => 'x-circle', 'bg' => 'bg-rose-50 dark:bg-rose-950/40'],
                ['label' => 'On Time', 'value' => $stats['on_time'], 'sub' => 'checked in on time', 'color' => 'text-blue-600', 'icon' => 'clock', 'bg' => 'bg-blue-50 dark:bg-blue-950/40'],
                ['label' => 'Late Arrivals', 'value' => $stats['late'], 'sub' => $stats['late_pct'] . '% of present', 'color' => 'text-amber-500', 'icon' => 'exclamation-circle', 'bg' => 'bg-amber-50 dark:bg-amber-950/40'],
            ];
        @endphp
        @foreach($kpis as $i => $kpi)
            <div
                class="flex items-center gap-3 px-5 py-5 {{ $i < 4 ? 'border-r border-zinc-100 dark:border-zinc-800' : '' }} {{ $i > 1 ? 'hidden sm:flex' : '' }} {{ $i > 2 ? 'hidden lg:flex' : '' }}">
                <div class="size-10 rounded-xl {{ $kpi['bg'] }} flex items-center justify-center shrink-0">
                    <flux:icon :name="$kpi['icon']" class="size-5 {{ $kpi['color'] }}" />
                </div>
                <div>
                    <div class="text-xl font-black {{ $kpi['color'] }} leading-none">{{ $kpi['value'] }}</div>
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider mt-1">{{ $kpi['label'] }}</div>
                    <div class="text-[10px] text-zinc-400 mt-0.5">{{ $kpi['sub'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="p-4 md:p-6 space-y-4">

        {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        PENDING REGULARISATIONS
        â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
        @if($pendingRegularisations->isNotEmpty())
            <div
                class="bg-white dark:bg-zinc-900 border border-amber-200 dark:border-amber-800/50 rounded-2xl overflow-hidden">
                <div
                    class="flex items-center gap-3 px-5 py-3.5 border-b border-amber-100 dark:border-amber-900/30 bg-amber-50/50 dark:bg-amber-950/20">
                    <div
                        class="size-7 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                        <flux:icon.clock class="size-4 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div class="flex-1">
                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">Pending Regularisation
                            Requests</span>
                    </div>
                    <span
                        class="px-2 py-0.5 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-[10px] font-black rounded-full">
                        {{ $pendingRegularisations->count() }} PENDING
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <th
                                    class="py-2.5 pl-5 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Employee</th>
                                <th
                                    class="py-2.5 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Date</th>
                                <th
                                    class="py-2.5 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Requested Time</th>
                                <th
                                    class="py-2.5 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Reason</th>
                                <th
                                    class="py-2.5 pr-5 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @foreach($pendingRegularisations as $req)
                                <tr class="hover:bg-amber-50/30 dark:hover:bg-amber-950/10 transition-colors">
                                    <td class="py-3 pl-5 pr-4">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="size-7 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center text-[10px] font-black text-amber-700 dark:text-amber-400 shrink-0">
                                                {{ collect(explode(' ', $req->employee->user->name))->map(fn($n) => $n[0] ?? '')->take(2)->join('') }}
                                            </div>
                                            <span
                                                class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ $req->employee->user->name }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-xs text-zinc-600 dark:text-zinc-400">
                                        {{ \Carbon\Carbon::parse($req->work_date)->format('d M Y') }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span
                                            class="font-mono text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/30 px-2 py-0.5 rounded">
                                            {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} â†’
                                            {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-xs text-zinc-500 max-w-[200px] truncate">{{ $req->reason }}</td>
                                    <td class="py-3 pr-5 text-right">
                                        <button wire:click="openReviewModal({{ $req->id }})"
                                            class="px-3 py-1 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-lg transition">
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        FILTER BAR
        â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl px-5 py-3.5">
            <div class="flex flex-wrap items-center gap-3">
                <flux:input wire:model.live.debounce.300ms="search" placeholder="Search employee name or ID..."
                    icon="magnifying-glass" size="sm" class="w-60" />

                <flux:input wire:model.live="date" type="date" size="sm" class="w-44" />

                <flux:select wire:model.live="status" placeholder="All Status" size="sm" class="w-40">
                    <option value="">All Status</option>
                    <option value="on_time">On Time</option>
                    <option value="late">Late</option>
                    <option value="remote">Remote</option>
                    <option value="absent">Absent</option>
                </flux:select>

                @if($search || $date || $status)
                    <button wire:click="$set('search','');$set('date','');$set('status','')"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-700 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 rounded-lg transition">
                        <flux:icon.x-mark class="size-3.5" /> Reset
                    </button>
                @endif

                <div class="ml-auto text-xs text-zinc-400 font-medium">
                    Showing {{ $attendances->firstItem() }}â€“{{ $attendances->lastItem() }} of
                    {{ $attendances->total() }}
                </div>
            </div>
        </div>

        {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
        ATTENDANCE TABLE
        â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-950/40">
                            <th
                                class="py-3 pl-5 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400 min-w-[180px]">
                                Employee</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Date</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Check In</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Check Out</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Work Hrs</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Status</th>
                            <th
                                class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Office</th>
                            <th
                                class="py-3 pr-5 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                Att. %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/40">
                        @forelse($attendances as $log)
                            @php
                                $initials = collect(explode(' ', $log->employee->user->name))
                                    ->map(fn($n) => $n[0] ?? '')->take(2)->join('');

                                [$statusBadge, $statusLabel, $avatarBg] = match ($log->status) {
                                    'on_time' => [
                                        'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/40',
                                        'On Time',
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    ],
                                    'late' => [
                                        'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/40',
                                        'Late',
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                    ],
                                    'absent' => [
                                        'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/30 dark:text-rose-400 dark:border-rose-800/40',
                                        'Absent',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
                                    ],
                                    'remote' => [
                                        'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-800/40',
                                        'Remote',
                                        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    ],
                                    default => [
                                        'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700',
                                        ucfirst($log->status ?? 'â€”'),
                                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                    ],
                                };

                                // Quick attendance % for this employee this month
                                $monthStart = now()->startOfMonth()->toDateString();
                                $monthEnd = now()->toDateString();
                                $workDays = max(1, now()->startOfMonth()->diffInDaysFiltered(fn($d) => !$d->isSunday(), now()));
                                $empPresent = \App\Models\Attendance::where('employee_id', $log->employee_id)
                                    ->whereBetween('date', [$monthStart, $monthEnd])
                                    ->whereNotNull('check_in')->count();
                                $empPct = min(100, round(($empPresent / $workDays) * 100));
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors group">

                                {{-- Employee --}}
                                <td class="py-3.5 pl-5 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="size-9 rounded-xl {{ $avatarBg }} flex items-center justify-center text-[11px] font-black shrink-0">
                                            {{ $initials }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-semibold text-sm text-zinc-900 dark:text-white truncate">
                                                {{ $log->employee->user->name }}
                                            </div>
                                            <div class="text-[10px] text-zinc-400">{{ $log->employee->employee_id ?? 'â€”' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Date --}}
                                <td class="py-3.5 pr-4">
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $log->date->format('d M Y') }}
                                    </div>
                                    <div class="text-[10px] text-zinc-400">{{ $log->date->format('l') }}</div>
                                </td>

                                {{-- Check In --}}
                                <td class="py-3.5 pr-4">
                                    <div class="font-mono text-sm font-bold text-zinc-900 dark:text-white tabular-nums">
                                        {{ $log->check_in?->format('H:i') ?? 'â€”' }}
                                    </div>
                                    @if($log->is_late)
                                        <div class="text-[10px] text-amber-600 font-bold">Late {{ $log->late_minutes ?? '' }}m
                                        </div>
                                    @elseif($log->check_in)
                                        <div class="text-[10px] text-emerald-600 font-bold">On Time</div>
                                    @endif
                                </td>

                                {{-- Check Out --}}
                                <td class="py-3.5 pr-4">
                                    @if($log->check_out)
                                        <div class="font-mono text-sm font-bold text-zinc-900 dark:text-white tabular-nums">
                                            {{ $log->check_out->format('H:i') }}
                                        </div>
                                    @elseif($log->check_in)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-black text-emerald-600">
                                            <span
                                                class="size-1.5 rounded-full bg-emerald-500 animate-pulse inline-block"></span>
                                            LIVE
                                        </span>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">â€”</span>
                                    @endif
                                </td>

                                {{-- Work Hours --}}
                                <td class="py-3.5 pr-4">
                                    @php $hrs = (float) $log->total_hours; @endphp
                                    <span
                                        class="text-sm font-black tabular-nums {{ $hrs >= 8 ? 'text-emerald-600' : ($hrs > 0 ? 'text-amber-600' : 'text-zinc-300 dark:text-zinc-600') }}">
                                        {{ $hrs > 0 ? number_format($hrs, 1) . 'h' : 'â€”' }}
                                    </span>
                                </td>

                                {{-- Status --}}
                                <td class="py-3.5 pr-4">
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-black {{ $statusBadge }}">
                                        {{ strtoupper($statusLabel) }}
                                    </span>
                                </td>

                                {{-- Office --}}
                                <td class="py-3.5 pr-4 text-xs text-zinc-500 dark:text-zinc-400 truncate max-w-[110px]">
                                    {{ $log->employee->office?->name ?? 'â€”' }}
                                </td>

                                {{-- Attendance % --}}
                                <td class="py-3.5 pr-5">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="flex-1 h-1.5 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden min-w-[40px]">
                                            <div class="h-full rounded-full transition-all {{ $empPct >= 80 ? 'bg-emerald-500' : ($empPct >= 60 ? 'bg-amber-500' : 'bg-rose-500') }}"
                                                style="width: {{ $empPct }}%"></div>
                                        </div>
                                        <span
                                            class="text-xs font-bold text-zinc-700 dark:text-zinc-300 tabular-nums w-8 shrink-0">{{ $empPct }}%</span>
                                    </div>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-16 text-center">
                                    <flux:icon.calendar-days
                                        class="size-10 mx-auto mb-3 text-zinc-300 dark:text-zinc-700" />
                                    <p class="text-sm font-medium text-zinc-400">No attendance records found.</p>
                                    <p class="text-xs text-zinc-300 dark:text-zinc-600 mt-1">Try adjusting your filters.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($attendances->hasPages())
                <div
                    class="px-5 py-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-950/30 flex items-center justify-between">
                    <div class="text-xs text-zinc-400">
                        Showing {{ $attendances->firstItem() }} to {{ $attendances->lastItem() }} of
                        {{ $attendances->total() }} entries
                    </div>
                    {{ $attendances->links() }}
                </div>
            @endif
        </div>

    </div>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    REVIEW REGULARISATION MODAL
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <flux:modal wire:model.self="showReviewModal" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Review Regularisation</flux:heading>
                <flux:subheading>Evaluate the attendance correction request</flux:subheading>
            </div>
            @if($activeRequest)
                @if($regularisationLocked)
                    <div
                        class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Already approved by Super Admin
                                ({{ $lockedByName }})</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">A mandatory comment is required to
                                override this approval.</p>
                        </div>
                    </div>
                @endif
                <div
                    class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900 p-4 text-sm space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Employee</span>
                        <span
                            class="font-bold text-zinc-900 dark:text-white">{{ $activeRequest->employee->user->name }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Date</span>
                        <span
                            class="font-bold text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeRequest->work_date ?? $activeRequest->date)->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Requested Time</span>
                        <span class="font-bold text-amber-600 font-mono">
                            {{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }}
                            â†’ {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}
                        </span>
                    </div>
                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                        <p class="text-[10px] text-zinc-400 uppercase tracking-wider mb-1">Reason</p>
                        <p class="text-zinc-700 dark:text-zinc-300 italic text-xs">"{{ $activeRequest->reason }}"</p>
                    </div>
                </div>
                <flux:textarea wire:model="reviewComment"
                    label="{{ $regularisationLocked ? 'Comment (Required for Override)' : 'HR Comment (Required for Rejection)' }}"
                    rows="2" placeholder="Add a comment..." />
                @error('reviewComment')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                @enderror
                <div class="flex gap-2 justify-end pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="rejectRegularisation" variant="ghost" class="!text-red-600 hover:!bg-red-50">
                        Reject</flux:button>
                    <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    HR MARK ATTENDANCE MODAL
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• --}}
    <flux:modal wire:model.self="showMarkModal" class="w-full max-w-lg">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-2.5">
                    <flux:icon.pencil-square class="size-5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div>
                    <flux:heading size="lg">Mark Attendance for Employee</flux:heading>
                    <flux:subheading>Creates a regularisation request pending manager approval.</flux:subheading>
                </div>
            </div>
            <div
                class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-800/30 dark:bg-amber-900/10">
                <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                <p class="text-xs text-amber-700 dark:text-amber-300 leading-relaxed">
                    This creates a <strong>Regularisation Request</strong> sent to the employee's <strong>Line
                        Manager</strong> for approval.
                </p>
            </div>
            <div class="space-y-4">
                <flux:select wire:model="markEmployeeId" label="Employee" placeholder="Select employee..." required>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->user->name }} ({{ $emp->employee_id ?? 'No ID' }})</option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="markDate" type="date" label="Date of Attendance"
                    max="{{ now()->format('Y-m-d') }}" required />
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="markCheckIn" type="time" label="Check-in Time" required />
                    <flux:input wire:model="markCheckOut" type="time" label="Check-out Time" required />
                </div>
                <div>
                    <flux:label>Work Mode</flux:label>
                    <div class="mt-1.5 grid grid-cols-3 gap-2">
                        @foreach(['office' => 'Office', 'wfh' => 'WFH', 'remote' => 'Remote'] as $val => $label)
                            <button type="button" wire:click="$set('markWorkMode', '{{ $val }}')"
                                class="rounded-xl border py-2 text-sm font-bold transition-all
                                            {{ $markWorkMode === $val ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 dark:border-zinc-700' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
                <flux:textarea wire:model="markReason" label="Reason for HR Entry"
                    placeholder="e.g. Employee's biometric failed, system issue..." rows="2" required />
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="submitMarkAttendance" variant="primary" icon="paper-airplane">Submit for
                    Approval</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>