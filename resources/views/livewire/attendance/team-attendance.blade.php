<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Team Attendance</h1>
            <p class="pulse-page-subtitle">Monitoring real-time presence of your direct reports</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="period" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 py-1.5 pl-2.5 pr-7 text-xs font-bold text-zinc-600 dark:text-zinc-300 focus:ring-0">
                <option value="this_week">This Week</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="quarter">This Quarter</option>
            </select>
        </div>
    </div>

    {{-- ─── PERIOD ANALYTICS — recomputes from the filter (engine-scored) ─── --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" wire:loading.class="opacity-50" wire:target="period">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white"><flux:icon.chart-bar class="size-4 text-orange-500" /> Team Analytics</h3>
            <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500 dark:bg-orange-500/15">{{ $periodLabel }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @php
                $pcards = [
                    ['Present Days', $periodStats['present'], '#10b981'],
                    ['Late Marks', $periodStats['late'], '#f59e0b'],
                    ['Worked Hours', $periodStats['worked_hours'].'h', '#3b82f6'],
                    ['Overtime', $periodStats['overtime_hours'].'h', '#8b5cf6'],
                    ['Avg Score', $periodStats['avg_score'].'/100', '#F97316'],
                ];
            @endphp
            @foreach($pcards as [$label, $value, $color])
                <div class="rounded-xl border border-zinc-100 bg-zinc-50/60 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                    <div class="text-xl font-black tabular-nums text-zinc-900 dark:text-white" style="color: {{ $color }}">{{ $value }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $label }}</div>
                </div>
            @endforeach
        </div>
        @if(!empty($scoreRanking))
            <div class="mt-3 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance score leaders</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($scoreRanking as $i => $r)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-100 bg-white px-2.5 py-1 text-xs dark:border-zinc-800 dark:bg-zinc-900">
                            <span class="text-[9px] font-black text-zinc-400">#{{ $i + 1 }}</span>
                            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $r['name'] }}</span>
                            <span class="font-black tabular-nums {{ $r['score'] >= 85 ? 'text-emerald-600' : ($r['score'] >= 60 ? 'text-amber-600' : 'text-rose-500') }}">{{ $r['score'] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- ─── LIVE BOARD ─── --}}
    <div class="space-y-4">
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
            @php
                $cards = [
                    ['label' => 'Working', 'value' => $boardStats['working'], 'color' => '#10b981', 'icon' => 'bolt'],
                    ['label' => 'In Office', 'value' => $boardStats['office'], 'color' => '#3b82f6', 'icon' => 'building-office-2'],
                    ['label' => 'WFH / Hybrid', 'value' => $boardStats['wfh'], 'color' => '#8b5cf6', 'icon' => 'home'],
                    ['label' => 'Late', 'value' => $boardStats['late'], 'color' => '#f59e0b', 'icon' => 'exclamation-triangle'],
                    ['label' => 'On Leave', 'value' => $boardStats['on_leave'], 'color' => '#6366f1', 'icon' => 'calendar-days'],
                    ['label' => 'Absent', 'value' => $boardStats['absent'], 'color' => '#ef4444', 'icon' => 'user-minus'],
                ];
            @endphp
            @foreach($cards as $c)
                <div class="rounded-2xl border border-zinc-200 bg-white p-3.5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="inline-flex size-8 items-center justify-center rounded-lg" style="background: {{ $c['color'] }}1a; color: {{ $c['color'] }};">
                        <flux:icon :icon="$c['icon']" class="size-4" />
                    </span>
                    <div class="mt-2 text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $c['value'] }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $c['label'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Roster --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 px-5 py-3 dark:border-zinc-800">
                <h3 class="flex items-center gap-2 text-sm font-bold text-zinc-900 dark:text-white"><span class="size-2 animate-pulse rounded-full bg-emerald-500"></span> Live Board · {{ $board->count() }} team member{{ $board->count() === 1 ? '' : 's' }}</h3>
                <span class="text-[10px] uppercase tracking-widest text-zinc-400">{{ now()->format('D, d M · h:i A') }}</span>
            </div>
            @if($board->isNotEmpty())
                <div class="grid grid-cols-1 divide-y divide-zinc-50 sm:grid-cols-2 sm:divide-y-0 dark:divide-zinc-800/60">
                    @foreach($board as $row)
                        @php
                            [$dot, $text, $label] = match($row['status']) {
                                'working'   => ['bg-emerald-500 animate-pulse', 'text-emerald-600 dark:text-emerald-400', 'Working'],
                                'late'      => ['bg-amber-500 animate-pulse', 'text-amber-600 dark:text-amber-400', 'Late'],
                                'on_break'  => ['bg-amber-400', 'text-amber-600 dark:text-amber-400', 'On Break'],
                                'completed' => ['bg-zinc-400', 'text-zinc-500', 'Completed'],
                                'on_leave'  => ['bg-indigo-500', 'text-indigo-600 dark:text-indigo-400', 'On Leave'],
                                default     => ['bg-rose-400', 'text-rose-500', 'Absent'],
                            };
                            $rowMode = $row['mode'] ? \App\Enums\AttendanceMode::tryFromValue($row['mode']) : null;
                        @endphp
                        <div class="flex items-center gap-3 px-5 py-3 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                            <div class="relative shrink-0">
                                @if($row['photo'])
                                    <img src="{{ \Storage::url($row['photo']) }}" alt="{{ $row['name'] }}" class="size-9 rounded-full object-cover">
                                @else
                                    <div class="flex size-9 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{{ strtoupper(substr($row['name'], 0, 1)) }}</div>
                                @endif
                                <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white dark:border-zinc-900 {{ $dot }}"></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $row['name'] }}</div>
                                <div class="flex items-center gap-1.5 text-[11px]">
                                    <span class="font-bold uppercase tracking-wider {{ $text }}">{{ $label }}</span>
                                    @if($row['since'])<span class="text-zinc-400">· since {{ $row['since'] }}</span>@endif
                                </div>
                            </div>
                            @if($rowMode)
                                <span class="inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide {{ $rowMode->chipClass() }}">{{ $rowMode->shortLabel() }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center text-sm text-zinc-400">No direct reports to display.</div>
            @endif
        </div>
    </div>

    {{-- Currently In Section --}}
    <div class="space-y-4">
        <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider">Present Now ({{ $currentlyIn->count() }})</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            @forelse($currentlyIn as $item)
                <div class="pulse-card flex items-center justify-between group">
                    <div class="flex items-center gap-3">
                        <div class="size-10 rounded-full bg-brand-600 flex items-center justify-center font-bold text-white text-sm">
                            {{ strtoupper(substr($item->employee->user->name, 0, 1)) }}
                        </div>
                        <div>
                            <div class="font-bold text-zinc-900 dark:text-white">{{ $item->employee->user->name }}</div>
                            <div class="text-xs text-zinc-500">In at {{ $item->check_in->format('H:i') }}</div>
                        </div>
                    </div>
                    <div class="size-2 rounded-full bg-green-500 animate-pulse"></div>
                </div>
            @empty
                <div class="col-span-full pulse-card py-8 text-center text-zinc-400">
                    No team members are currently clocked in.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Pending Regularisation Requests --}}
    @if($pendingRegularisations->isNotEmpty())
        <div class="pulse-card border-brand-200 dark:border-brand-900/50 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-1 h-full bg-brand-500"></div>
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Pending Regularisation Requests</h3>
            <div class="pulse-table-wrap">
                <table class="pulse-table">
                    <thead>
                        <tr>
                            <th class="pulse-th pl-6">Employee</th>
                            <th class="pulse-th">Date</th>
                            <th class="pulse-th">Requested Time</th>
                            <th class="pulse-th">Reason</th>
                            <th class="pulse-th pr-6 text-right!">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingRegularisations as $req)
                            <tr>
                                <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">
                                    {{ $req->employee->user->name }}
                                </td>
                                <td class="pulse-td">
                                    {{ \Carbon\Carbon::parse($req->work_date)->format('M d, Y') }}
                                </td>
                                <td class="pulse-td">
                                    {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
                                </td>
                                <td class="pulse-td text-zinc-500 truncate max-w-xs" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </td>
                                <td class="pulse-td pr-6 text-right!">
                                    <flux:button wire:click="openReviewModal({{ $req->id }})" size="xs" variant="primary">Review</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Activity Feed --}}
    <div class="pulse-card">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Recent Activity</h3>
        
        <div class="pulse-table-wrap">
            <table class="pulse-table">
                <thead>
                    <tr>
                        <th class="pulse-th pl-6">Employee</th>
                        <th class="pulse-th">Date</th>
                        <th class="pulse-th">Check In</th>
                        <th class="pulse-th">Check Out</th>
                        <th class="pulse-th">Status</th>
                        <th class="pulse-th pr-6 text-right!">Total Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($recentLogs as $log)
                        <tr>
                            <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">
                                {{ $log->employee->user->name }}
                            </td>
                            <td class="pulse-td">
                                {{ $log->date->format('M d, Y') }}
                            </td>
                            <td class="pulse-td">
                                {{ $log->check_in->format('H:i') }}
                            </td>
                            <td class="pulse-td">
                                {{ $log->check_out ? $log->check_out->format('H:i') : '--:--' }}
                            </td>
                            <td class="pulse-td">
                                <span class="badge-{{ $log->status === 'on_time' ? $log->status : ($log->status === 'late' ? 'rejected' : 'manager') }}">
                                    {{ strtoupper($log->status) }}
                                </span>
                            </td>
                            <td class="pulse-td pr-6 text-right! font-bold text-zinc-900 dark:text-white">
                                {{ (float)$log->total_hours }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="pulse-table__empty">No activity logs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pulse-pagination">
            {{ $recentLogs->links() }}
        </div>
    </div>

    {{-- Review Modal --}}
    @if($showReviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showReviewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showReviewModal', false)"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showReviewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Review Regularisation Request</flux:heading>
                <flux:subheading>Evaluate the attendance correction</flux:subheading>
            </div>

            @if($activeRequest)
                <div class="bg-zinc-50 p-4 rounded-xl dark:bg-zinc-900 text-sm space-y-3">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Employee</span>
                        <span class="font-bold">{{ $activeRequest->employee->user->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Date</span>
                        <span class="font-bold">{{ \Carbon\Carbon::parse($activeRequest->work_date)->format('M d, Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Requested Time</span>
                        <span class="font-bold text-brand-600">{{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}</span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block mb-1">Reason provided</span>
                        <p class="text-zinc-700 dark:text-zinc-300 italic">"{{ $activeRequest->reason }}"</p>
                    </div>
                </div>

                <flux:textarea wire:model="reviewComment" label="Manager Comment (Required for Rejection)" rows="2" />

                <div class="flex gap-2 justify-end pt-4">
                    <button type="button" @click="$wire.set('showReviewModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                    <flux:button wire:click="rejectRegularisation" variant="ghost" class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30">Reject</flux:button>
                    <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                </div>
            @endif
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
