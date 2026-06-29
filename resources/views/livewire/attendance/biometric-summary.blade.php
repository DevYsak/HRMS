<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">
    <div class="space-y-6">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Biometric Attendance Summary</flux:heading>
            <flux:subheading>
                Daily attendance calculated by the biometric engine and synced into HRMS.
                @if($lastSynced)
                    <span class="ml-1 text-xs text-zinc-400">· Last synced {{ \Illuminate\Support\Carbon::parse($lastSynced)->format('d M, g:i A') }}</span>
                @endif
            </flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button wire:click="syncNow" icon="bolt" variant="primary" size="sm">Quick Scan</flux:button>
            <flux:button wire:click="previousDay" icon="chevron-left" variant="ghost" size="sm">Prev</flux:button>
            <flux:button wire:click="today" variant="ghost" size="sm">Today</flux:button>
            <flux:button wire:click="nextDay" icon="chevron-right" variant="ghost" size="sm">Next</flux:button>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="pulse-card p-4">
            <p class="pulse-stat__label uppercase tracking-wide">Synced</p>
            <p class="pulse-stat__value mt-1">{{ $stats['total'] }}</p>
        </div>
        <div class="pulse-card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Present</p>
            <p class="mt-1 text-3xl font-bold text-emerald-700 dark:text-emerald-400">{{ $stats['present'] }}</p>
        </div>
        <div class="pulse-card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-amber-600">Late</p>
            <p class="mt-1 text-3xl font-bold text-amber-700 dark:text-amber-400">{{ $stats['late'] }}</p>
        </div>
        <div class="pulse-card p-4">
            <p class="pulse-stat__label uppercase tracking-wide">Absent</p>
            <p class="mt-1 text-3xl font-bold text-zinc-400 dark:text-zinc-500">{{ $stats['absent'] }}</p>
        </div>
        <div class="pulse-card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-blue-600">Leave / Off</p>
            <p class="mt-1 text-3xl font-bold text-blue-700 dark:text-blue-400">{{ $stats['leave'] }}</p>
        </div>
        <div class="pulse-card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-violet-600">OT Hours</p>
            <p class="mt-1 text-3xl font-bold text-violet-700 dark:text-violet-400">{{ $stats['ot_hours'] }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <flux:label for="date-picker">Date</flux:label>
            <flux:input id="date-picker" type="date" wire:model.live="date" />
        </div>
        <div>
            <flux:label for="dept-filter">Department</flux:label>
            <flux:select id="dept-filter" wire:model.live="department">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept }}">{{ $dept }}</option>
                @endforeach
            </flux:select>
        </div>
        <div>
            <flux:label for="status-filter">Status</flux:label>
            <flux:select id="status-filter" wire:model.live="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st }}">{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                @endforeach
            </flux:select>
        </div>
        <div class="flex-1 min-w-48">
            <flux:label for="search-box">Search</flux:label>
            <flux:input id="search-box" wire:model.live.debounce.300ms="search" placeholder="Name or PIN…"
                icon="magnifying-glass" />
        </div>
    </div>

    {{-- Table --}}
    <div class="pulse-card p-0 overflow-hidden">
        <div class="pulse-table-wrap">
            <table class="pulse-table">
                <thead>
                    <tr>
                        <th class="pulse-th pl-4">Employee</th>
                        <th class="pulse-th">Department</th>
                        <th class="pulse-th">Shift</th>
                        <th class="pulse-th">Manager</th>
                        <th class="pulse-th">First In</th>
                        <th class="pulse-th">Last Out</th>
                        <th class="pulse-th">Break</th>
                        <th class="pulse-th">Work Hrs</th>
                        <th class="pulse-th">Late</th>
                        <th class="pulse-th">OT</th>
                        <th class="pulse-th">Status</th>
                        <th class="pulse-th">Punches</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summaries as $row)
                        <tr>
                            <td class="pulse-td pl-4">
                                <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row->employee?->user?->name ?? '—' }}</div>
                                <div class="text-xs text-zinc-400">PIN {{ $row->employee_code }}</div>
                            </td>
                            <td class="pulse-td">{{ $row->employee?->department?->name ?? '—' }}</td>
                            <td class="pulse-td">{{ $row->employee?->shift?->name ?? '—' }}</td>
                            <td class="pulse-td">{{ $row->employee?->manager?->name ?? '—' }}</td>
                            <td class="pulse-td font-mono">{{ $row->first_punch?->format('H:i') ?? '—' }}</td>
                            <td class="pulse-td font-mono">{{ $row->last_punch?->format('H:i') ?? '—' }}</td>
                            <td class="pulse-td font-mono">{{ $row->break_minutes }}m</td>
                            <td class="pulse-td font-mono">{{ $row->working_hours }}</td>
                            <td class="pulse-td font-mono">
                                @if ($row->late_minutes > 0)
                                    <span class="text-amber-600 dark:text-amber-400">{{ $row->late_minutes }}m</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pulse-td font-mono">
                                @if ($row->overtime_minutes > 0)
                                    <span class="text-violet-600 dark:text-violet-400">{{ $row->overtime_minutes }}m</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="pulse-td">
                                @php
                                    $badge = match ($row->status) {
                                        'present' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                        'late' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                        'half_day' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
                                        'leave', 'holiday', 'weekly_off' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                        default => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">
                                    {{ ucfirst(str_replace('_', ' ', $row->status)) }}
                                </span>
                            </td>
                            <td class="pulse-td font-mono">{{ $row->raw_punch_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="pulse-table__empty">
                                No synced attendance for this day. The Python engine pushes summaries via the sync API.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($summaries->hasPages())
        <div>{{ $summaries->links() }}</div>
    @endif

    <p class="text-xs text-zinc-400">
        These figures are calculated by the biometric attendance engine and stored read-only in HRMS
        (attendance_daily_summaries). To correct a record, fix it in the engine and re-sync.
    </p>

    </div>
</flux:main>
