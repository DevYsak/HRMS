<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen" x-data="{
    currentTime: '',
    updateClock() {
        const now = new Date();
        this.currentTime = now.toLocaleTimeString('en-IN', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}" x-init="updateClock(); setInterval(() => updateClock(), 1000)">

    {{-- ── TOP HEADER BAR ── --}}
    <div class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 px-6 py-8 md:px-10">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(29,183,122,0.12),_transparent_60%)]"></div>
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    @if($todayAttendance && !$todayAttendance->check_out)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-brand-600/20 border border-brand-500/30 text-brand-400 rounded-full text-xs font-bold tracking-wider">
                            <span class="w-1.5 h-1.5 bg-brand-400 rounded-full animate-pulse"></span>
                            SHIFT ACTIVE
                        </span>
                    @elseif($todayAttendance)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-zinc-700/50 border border-zinc-600/30 text-zinc-400 rounded-full text-xs font-bold tracking-wider">
                            SHIFT COMPLETED
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-zinc-700/50 border border-zinc-600/30 text-zinc-400 rounded-full text-xs font-bold tracking-wider">
                            NOT CLOCKED IN
                        </span>
                    @endif
                </div>
                <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">Attendance Tracking</h1>
                <p class="text-zinc-400 text-sm mt-1">{{ now()->format('l, jS F Y') }} · {{ $shiftLabel }}</p>
            </div>
            <div class="flex items-center gap-3">
                <button @click="$flux.modal('regularisation-modal').show()"
                    class="flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white rounded-xl text-sm font-semibold transition-all">
                    <flux:icon.pencil-square class="size-4" /> Regularisation
                </button>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-6">

        {{-- ── MAIN CLOCK + STATS GRID ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- Clock Card --}}
            <div class="lg:col-span-1 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                {{-- Live clock header --}}
                <div class="bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-200 dark:border-zinc-800 px-6 py-4 text-center">
                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Current Time · IST</div>
                    <div class="text-4xl font-black text-zinc-900 dark:text-white tracking-tighter tabular-nums" x-text="currentTime"></div>
                </div>

                <div class="p-6 space-y-5">
                    {{-- Checked-in status --}}
                    @if($todayAttendance)
                        <div class="rounded-xl {{ $todayAttendance->is_late ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-200 dark:border-rose-900/50' : 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-200 dark:border-emerald-900/50' }} border p-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Clocked In</div>
                                    <div class="text-xl font-black text-zinc-900 dark:text-white mt-0.5">{{ $todayAttendance->check_in->format('h:i A') }}</div>
                                </div>
                                @if($todayAttendance->is_late)
                                    <span class="px-2 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-400 text-[10px] font-bold rounded-lg">LATE {{ $todayAttendance->late_minutes }}m</span>
                                @else
                                    <span class="px-2 py-1 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold rounded-lg">ON TIME</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 mt-2 text-xs text-zinc-500">
                                <flux:icon.map-pin class="size-3" />
                                {{ strtoupper($todayAttendance->work_mode ?? 'office') }}
                                @if($todayAttendance->check_in_ip)
                                    · {{ $todayAttendance->check_in_ip }}
                                @endif
                            </div>
                        </div>

                        {{-- Break status --}}
                        @if($activeBreak)
                            <div class="rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/50 p-3 flex items-center justify-between">
                                <div class="flex items-center gap-2 text-amber-700 dark:text-amber-400 text-sm font-bold">
                                    <span class="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></span>
                                    On break since {{ \Carbon\Carbon::parse($activeBreak['break_start'])->format('h:i A') }}
                                </div>
                            </div>
                        @endif

                        @if($todayAttendance->excess_break_flag)
                            <div class="rounded-xl bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900/40 p-3 flex items-center gap-2 text-rose-700 dark:text-rose-400 text-xs font-bold">
                                <flux:icon.exclamation-triangle class="size-4 shrink-0" />
                                Excess break flagged (>60 min)
                            </div>
                        @endif
                    @else
                        {{-- Work mode selector --}}
                        <div>
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Work Mode</div>
                            <div class="grid grid-cols-2 gap-2">
                                <button wire:click="$set('workMode', 'office')"
                                    class="py-2.5 rounded-xl border text-sm font-bold transition-all
                                        {{ $workMode === 'office'
                                            ? 'bg-brand-600 border-brand-600 text-white shadow-md shadow-brand-500/20'
                                            : 'bg-zinc-50 dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-300' }}">
                                    <flux:icon.building-office class="size-4 mx-auto mb-1" />
                                    Office
                                </button>
                                <button wire:click="$set('workMode', 'wfh')"
                                    class="py-2.5 rounded-xl border text-sm font-bold transition-all
                                        {{ $workMode === 'wfh'
                                            ? 'bg-brand-600 border-brand-600 text-white shadow-md shadow-brand-500/20'
                                            : 'bg-zinc-50 dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-300' }}">
                                    <flux:icon.home class="size-4 mx-auto mb-1" />
                                    WFH
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Action buttons --}}
                    <div class="space-y-2">
                        @if(! $todayAttendance)
                            <button wire:click="checkIn"
                                class="w-full py-4 bg-brand-600 hover:bg-brand-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-brand-500/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                                <flux:icon.finger-print class="size-5" />
                                Clock In · {{ strtoupper($workMode) }}
                            </button>
                        @elseif(! $todayAttendance->check_out)
                            <div class="grid grid-cols-2 gap-2">
                                @if(! $activeBreak)
                                    <button wire:click="startBreak"
                                        class="py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 rounded-xl font-bold text-sm hover:bg-amber-100 transition-all active:scale-95 flex items-center justify-center gap-1.5">
                                        <flux:icon.pause class="size-4" /> Break
                                    </button>
                                @else
                                    <button wire:click="endBreak"
                                        class="py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl font-bold text-sm transition-all active:scale-95 animate-pulse flex items-center justify-center gap-1.5">
                                        <flux:icon.play class="size-4" /> Resume
                                    </button>
                                @endif
                                <button wire:click="checkOut"
                                    class="py-3 bg-zinc-900 dark:bg-zinc-700 hover:bg-black text-white rounded-xl font-bold text-sm transition-all active:scale-95 flex items-center justify-center gap-1.5">
                                    <flux:icon.arrow-right-start-on-rectangle class="size-4" /> Clock Out
                                </button>
                            </div>
                        @else
                            <div class="py-4 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 text-center rounded-xl">
                                <flux:icon.check-badge class="size-8 text-emerald-600 mx-auto mb-1" />
                                <div class="font-bold text-emerald-800 dark:text-emerald-400 text-sm">Shift Completed</div>
                                @php
                                    $diff = $todayAttendance->check_out->diff($todayAttendance->check_in);
                                @endphp
                                <div class="text-xs text-emerald-600 mt-0.5">{{ $diff->h }}h {{ $diff->i }}m · {{ $todayAttendance->break_minutes }}m break</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Stats Cards --}}
            <div class="lg:col-span-2 flex flex-col gap-4 content-start">

                {{-- Period Filter Pills --}}
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach(['this_month' => 'This Month', 'last_month' => 'Last Month', '3_months' => '3 Months', 'year' => 'This Year'] as $val => $label)
                        <button wire:click="$set('statsPeriod', '{{ $val }}')"
                            class="px-3 py-1.5 rounded-xl text-xs font-bold border transition-all
                                {{ $statsPeriod === $val
                                    ? 'bg-brand-600 border-brand-600 text-white shadow-sm'
                                    : 'bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 text-zinc-500 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- 5-card grid --}}
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-2 xl:grid-cols-4 gap-3">
                    @php
                        $periodLabel = match($statsPeriod) {
                            'last_month' => 'last month',
                            '3_months'   => '3 months',
                            'year'       => 'this year',
                            default      => 'this month',
                        };
                    @endphp

                    {{-- Present --}}
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-3">Present</div>
                        <div class="text-4xl font-black text-emerald-600">{{ $stats['present'] }}</div>
                        <div class="text-xs text-zinc-400 mt-1">{{ $periodLabel }}</div>
                    </div>

                    {{-- Absent --}}
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border {{ $stats['absent'] > 0 ? 'border-rose-200 dark:border-rose-900/40' : 'border-zinc-200 dark:border-zinc-800' }} p-5 shadow-sm">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-3">Absent</div>
                        <div class="text-4xl font-black {{ $stats['absent'] > 0 ? 'text-rose-500' : 'text-zinc-300 dark:text-zinc-600' }}">{{ $stats['absent'] }}</div>
                        <div class="text-xs text-zinc-400 mt-1">missed days</div>
                    </div>

                    {{-- Late --}}
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-3">Late Arrivals</div>
                        <div class="text-4xl font-black text-amber-500">{{ $stats['late'] }}</div>
                        <div class="text-xs text-zinc-400 mt-1">{{ $periodLabel }}</div>
                    </div>

                    {{-- Hours --}}
                    <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm" @if($todayAttendance && !$todayAttendance->check_out) wire:poll.30s @endif>
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-3">Hours Logged</div>
                        <div class="text-4xl font-black text-brand-600 tabular-nums">{{ $stats['hours'] }}</div>
                        <div class="text-xs text-zinc-400 mt-1">{{ $periodLabel }}</div>
                    </div>

                    {{-- Leaves --}}
                    <div class="col-span-2 md:col-span-4 lg:col-span-2 xl:col-span-4 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm flex items-center justify-between">
                        <div>
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Leaves Taken</div>
                            <div class="text-4xl font-black text-indigo-500">{{ $stats['leaves'] }}</div>
                            <div class="text-xs text-zinc-400 mt-1">approved · {{ $periodLabel }}</div>
                        </div>
                        {{-- Shift Info inline --}}
                        <div class="hidden xl:block text-right">
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Shift Hours</div>
                            <div class="text-lg font-black text-zinc-900 dark:text-white">
                                {{ $shift ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A') : '10:30 AM' }}
                                <span class="text-zinc-400 font-normal text-sm">–</span>
                                {{ $shift ? \Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '7:30 PM' }}
                            </div>
                            <div class="text-[10px] text-zinc-400">Grace {{ $shift->grace_minutes ?? 5 }}m · Break {{ $shift->break_duration ?? 60 }}m</div>
                        </div>
                    </div>
                </div>

                {{-- Shift Info Card (always visible on smaller screens) --}}
                <div class="xl:hidden bg-zinc-900 dark:bg-zinc-950 rounded-2xl p-5 shadow-sm relative overflow-hidden">
                    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(29,183,122,0.1),_transparent_60%)]"></div>
                    <div class="relative grid grid-cols-3 gap-4">
                        <div>
                            <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest mb-1">Grace Period</div>
                            <div class="text-2xl font-black text-white">{{ $shift->grace_minutes ?? 5 }}<span class="text-sm text-zinc-400 ml-1">min</span></div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest mb-1">Standard Hours</div>
                            <div class="text-2xl font-black text-white">{{ $shift ? \Carbon\Carbon::parse($shift->start_time)->diffInHours(\Carbon\Carbon::parse($shift->end_time)) : 9 }}<span class="text-sm text-zinc-400 ml-1">hrs</span></div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest mb-1">Max Break</div>
                            <div class="text-2xl font-black text-white">{{ $shift->break_duration ?? 60 }}<span class="text-sm text-zinc-400 ml-1">min</span></div>
                        </div>
                    </div>
                    <div class="relative mt-3 pt-3 border-t border-zinc-800 text-xs text-zinc-500 flex items-center gap-1.5">
                        <flux:icon.clock class="size-3" />
                        {{ $shift ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A') : '10:30 AM' }} –
                        {{ $shift ? \Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '7:30 PM' }} IST
                    </div>
                </div>

            </div>
        </div>

        {{-- ── CALENDAR + SIDEBAR (Attendance Log · Holidays · Last Leave) ── --}}
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

            {{-- Left: Attendance Calendar --}}
            <div class="lg:col-span-7 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ $calendarMonth->format('F Y') }}</h3>
                    <div class="flex gap-1">
                        <button wire:click="previousMonth" class="p-1.5 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <flux:icon.chevron-left class="size-3.5 text-zinc-500" />
                        </button>
                        <button wire:click="nextMonth" class="p-1.5 border border-zinc-200 dark:border-zinc-700 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <flux:icon.chevron-right class="size-3.5 text-zinc-500" />
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-1 text-center mb-2">
                    @foreach(['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $d)
                        <div class="text-[10px] font-bold text-zinc-400 tracking-widest pb-1">{{ $d }}</div>
                    @endforeach
                </div>

                <div class="grid grid-cols-7 gap-1.5">
                    @foreach($calendarDays as $day)
                        @php
                            $dayDate = \Carbon\Carbon::parse($day['date']);
                            $bg = ''; $dot = '';
                            if ($day['in_month']) {
                                $bg = match($day['status']) {
                                    'present' => 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-100 dark:border-emerald-900/40',
                                    'late'    => 'bg-rose-50 dark:bg-rose-950/30 border-rose-100 dark:border-rose-900/40',
                                    'leave'   => 'bg-amber-50 dark:bg-amber-950/30 border-amber-100 dark:border-amber-900/40',
                                    'holiday' => 'bg-blue-50 dark:bg-blue-950/30 border-blue-100 dark:border-blue-900/40',
                                    'absent'  => $dayDate->isPast() ? 'bg-zinc-50 dark:bg-zinc-800/50 border-zinc-100 dark:border-zinc-800' : 'bg-white dark:bg-zinc-900 border-zinc-50 dark:border-zinc-800',
                                    default   => 'border-transparent',
                                };
                                $dot = match($day['status']) {
                                    'present' => 'bg-emerald-500',
                                    'late'    => 'bg-rose-500',
                                    'leave'   => 'bg-amber-500',
                                    'holiday' => 'bg-blue-500',
                                    'absent'  => $dayDate->isPast() ? 'bg-zinc-300 dark:bg-zinc-600' : '',
                                    default   => '',
                                };
                            }
                        @endphp
                        <div class="aspect-square rounded-lg border flex flex-col items-center justify-center transition-all
                            {{ !$day['in_month'] ? 'opacity-20 pointer-events-none border-transparent' : '' }}
                            {{ $day['is_today']
                                ? 'bg-brand-600 border-brand-600 text-white shadow-md shadow-brand-500/20 scale-105 z-10'
                                : ($bg ?: 'bg-white dark:bg-zinc-900 border-zinc-100 dark:border-zinc-800 hover:border-zinc-200 dark:hover:border-zinc-700') }}">
                            <span class="text-xs font-bold {{ $day['is_today'] ? 'text-white' : ($dayDate->isWeekend() && $day['status'] === 'absent' ? 'text-zinc-400' : 'text-zinc-700 dark:text-zinc-200') }}">
                                {{ $day['day'] }}
                            </span>
                            @if($dot && !$day['is_today'])
                                <div class="w-1 h-1 rounded-full {{ $dot }} mt-0.5"></div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="mt-5 pt-5 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap gap-4">
                    @foreach([['bg-emerald-500','Present'],['bg-rose-500','Late'],['bg-amber-500','On Leave'],['bg-blue-500','Holiday'],['bg-zinc-300 dark:bg-zinc-600','Absent']] as [$c, $l])
                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400 uppercase tracking-widest">
                            <div class="w-1.5 h-1.5 rounded-full {{ $c }}"></div> {{ $l }}
                        </div>
                    @endforeach
                </div>
            </div>{{-- end left calendar --}}

            {{-- Right Sidebar: Attendance Log + Last Leave + Holidays --}}
            <div class="lg:col-span-5 flex flex-col gap-5">

                {{-- Compact Attendance Log --}}
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Attendance Log</h3>
                        <span class="text-[10px] text-zinc-400 uppercase tracking-widest">{{ $calendarMonth->format('M Y') }}</span>
                    </div>
                    <div class="divide-y divide-zinc-50 dark:divide-zinc-800/60 max-h-64 overflow-y-auto">
                        @forelse($history as $item)
                            <div class="flex items-center justify-between px-5 py-2.5 hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="text-center min-w-[32px]">
                                        <div class="text-sm font-black text-zinc-900 dark:text-white leading-none">{{ $item->date->format('d') }}</div>
                                        <div class="text-[9px] font-bold text-zinc-400 uppercase">{{ $item->date->format('D') }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-mono text-xs text-zinc-700 dark:text-zinc-300 truncate">
                                            {{ $item->check_in->format('H:i') }}
                                            @if($item->check_out)
                                                — {{ $item->check_out->format('H:i') }}
                                            @elseif($item->date->isToday())
                                                — <span class="text-brand-600 animate-pulse">live</span>
                                            @else
                                                — <span class="text-rose-400">--:--</span>
                                            @endif
                                        </div>
                                        @php
                                            $sc = match($item->status) {
                                                'on_time' => 'text-emerald-600',
                                                'late'    => 'text-rose-500',
                                                default   => 'text-zinc-400',
                                            };
                                            $sl = match($item->status) { 'on_time' => 'ON TIME', 'late' => 'LATE', default => strtoupper($item->status) };
                                        @endphp
                                        <div class="text-[9px] font-bold uppercase tracking-wider {{ $sc }}">{{ $sl }}</div>
                                    </div>
                                </div>
                                <div class="text-right shrink-0 ml-2">
                                    @if($item->check_out)
                                        @php $d = $item->check_in->diff($item->check_out); @endphp
                                        <div class="text-xs font-bold {{ ($d->h + $d->d * 24) >= 9 ? 'text-emerald-600' : 'text-zinc-900 dark:text-white' }}">
                                            {{ $d->h + $d->d * 24 }}h {{ $d->i }}m
                                        </div>
                                    @elseif($item->date->isToday())
                                        @php $d = now()->diff($item->check_in); @endphp
                                        <div class="text-xs font-bold text-brand-600 animate-pulse">{{ $d->h }}h {{ $d->i }}m</div>
                                    @else
                                        <div class="text-xs text-zinc-300 dark:text-zinc-600">—</div>
                                    @endif
                                    @if((!$item->check_out && !$item->date->isToday()) || $item->missing_checkout)
                                        <button wire:click="openRegularisation('{{ $item->date->toDateString() }}')"
                                            class="text-[9px] font-bold text-brand-600 hover:text-brand-700 uppercase tracking-wide">
                                            Fix →
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="py-8 text-center text-zinc-400 text-sm">No records for {{ $calendarMonth->format('F Y') }}</div>
                        @endforelse
                    </div>
                </div>

                {{-- Last Leave + Holidays --}}
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-5 space-y-4">

                    {{-- Last Leave This Month --}}
                    @if($lastLeave)
                        <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-900/40">
                            <div class="text-[10px] font-bold text-amber-600 uppercase tracking-widest mb-1.5">Last Leave This Month</div>
                            <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $lastLeave->leave_type ?? 'Leave' }}</div>
                            <div class="text-xs text-zinc-500 mt-0.5">
                                {{ \Carbon\Carbon::parse($lastLeave->start_date)->format('d M') }}
                                @if($lastLeave->start_date->toDateString() !== $lastLeave->end_date->toDateString())
                                    – {{ \Carbon\Carbon::parse($lastLeave->end_date)->format('d M') }}
                                @endif
                                · {{ $lastLeave->start_date->diffInDays($lastLeave->end_date) + 1 }} day(s)
                            </div>
                        </div>
                    @else
                        <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-1">Last Leave This Month</div>
                            <div class="text-xs text-zinc-400">No leave taken in {{ $calendarMonth->format('F') }}</div>
                        </div>
                    @endif

                    {{-- Holidays --}}
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Holidays</h3>
                            <span class="text-[10px] text-zinc-400 uppercase tracking-widest">{{ $calendarMonth->format('M Y') }}</span>
                        </div>
                        <div class="space-y-2">
                            @forelse($monthHolidays as $holiday)
                                <div class="flex items-center gap-3 p-2.5 rounded-xl border border-zinc-100 dark:border-zinc-800 hover:border-zinc-200 dark:hover:border-zinc-700 transition-colors">
                                    <div class="text-center min-w-[30px] bg-blue-50 dark:bg-blue-950/40 rounded-lg p-1">
                                        <div class="text-sm font-black text-blue-700 dark:text-blue-400 leading-none">{{ \Carbon\Carbon::parse($holiday->date)->format('j') }}</div>
                                        <div class="text-[9px] font-bold text-blue-500 uppercase">{{ \Carbon\Carbon::parse($holiday->date)->format('M') }}</div>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ $holiday->name }}</div>
                                        <div class="text-[10px] text-zinc-400 uppercase tracking-wide">{{ \Carbon\Carbon::parse($holiday->date)->format('l') }}</div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4 text-zinc-400 text-xs">No holidays this month</div>
                            @endforelse
                        </div>
                    </div>

                </div>{{-- end last leave + holidays --}}

            </div>{{-- end right sidebar --}}
        </div>{{-- end calendar grid --}}

    </div>{{-- end main p-4 container --}}

    {{-- ── REGULARISATION MODAL ── --}}
    <flux:modal name="regularisation-modal" class="max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Regularisation Request</flux:heading>
                <flux:subheading>Request correction for past attendance</flux:subheading>
            </div>
            <div class="space-y-4">
                <flux:input wire:model="regDate" label="Date of Work" type="date" />
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="regCheckIn" label="Correct Check-in" type="time" />
                    <flux:input wire:model="regCheckOut" label="Correct Check-out" type="time" />
                </div>
                <flux:textarea wire:model="regReason" label="Reason" placeholder="e.g. Forgot to clock in, system glitch..." rows="3" />
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitRegularisation" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
