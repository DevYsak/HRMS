<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    {{-- ===== WELCOME BANNER ===== --}}
    <div class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 px-8 py-7">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(29,183,122,0.18),_transparent_60%)]"></div>
        <div class="absolute bottom-0 left-0 w-48 h-48 bg-brand-600/5 rounded-full blur-3xl"></div>

        <div class="relative flex items-center justify-between">
            <div>
                <p class="text-zinc-400 text-sm mb-1">{{ now()->format('l, d F Y') }}</p>
                <h1 class="text-2xl font-bold text-white tracking-tight">
                    Good {{ now()->format('H') < 12 ? 'Morning' : (now()->format('H') < 17 ? 'Afternoon' : 'Evening') }}, {{ Str::of(auth()->user()->name)->explode(' ')->first() }}
                </h1>
                <p class="text-zinc-400 text-sm mt-1">Here's your personal workspace for today.</p>
            </div>

            {{-- Quick Action Chips --}}
            <div class="hidden md:flex items-center gap-2">
                <a href="{{ route('attendance.my') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 border border-white/10 transition rounded-xl text-sm text-white font-semibold">
                    <flux:icon.clock class="size-4" /> Attendance
                </a>
                <a href="{{ route('time-off.my') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 border border-white/10 transition rounded-xl text-sm text-white font-semibold">
                    <flux:icon.calendar-days class="size-4" /> Apply Leave
                </a>
                <a href="{{ route('overtime.my') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2 bg-white/10 hover:bg-white/20 border border-white/10 transition rounded-xl text-sm text-white font-semibold">
                    <flux:icon.plus-circle class="size-4" /> Log OT
                </a>
                <a href="{{ route('payroll.payslips') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2 bg-brand-600 hover:bg-brand-700 border border-brand-500 transition rounded-xl text-sm text-white font-semibold shadow-lg shadow-brand-900/20">
                    <flux:icon.banknotes class="size-4" /> My Pay
                </a>
            </div>
        </div>
    </div>

    {{-- ===== MAIN 2-COLUMN LAYOUT ===== --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-0 min-h-[calc(100vh-7rem)]">

        {{-- ========================================================= --}}
        {{-- LEFT COLUMN: Quick-access widgets (like Keka left panel)  --}}
        {{-- ========================================================= --}}
        <div class="lg:col-span-1 border-r border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 space-y-5">

            {{-- ---- TIME TODAY (Keka "Time Today" widget) ---- --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950 p-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">Time Today</span>
                    <a href="{{ route('attendance.my') }}" wire:navigate
                       class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium">View All</a>
                </div>

                {{-- Live Clock --}}
                <div class="text-4xl font-black text-zinc-900 dark:text-white tabular-nums" id="live-clock">
                    {{ now()->format('h:i') }}<span class="text-lg font-medium text-zinc-400 ml-1">{{ now()->format('A') }}</span>
                </div>
                <p class="text-xs text-zinc-400 mt-1">{{ now()->format('D, d M Y') }}</p>

                {{-- Weekly Strip --}}
                <div class="mt-5 mb-4">
                    <div class="flex items-center justify-between gap-1">
                        @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $idx => $dayStr)
                            @php
                                $dDate = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDays($idx);
                                $isToday = $dDate->isToday();
                                
                                // Reset iterator logic
                                $record = null;
                                foreach($currentWeekAttendance as $att) {
                                    if (\Carbon\Carbon::parse($att->date)->isSameDay($dDate)) {
                                        $record = $att;
                                        break;
                                    }
                                }
                                
                                $statusColor = 'bg-zinc-100 dark:bg-zinc-800'; // default/future
                                if ($record) {
                                    if ($record->is_late) $statusColor = 'bg-amber-400';
                                    elseif ($record->status === 'absent') $statusColor = 'bg-rose-500';
                                    elseif ($record->status === 'remote' || $record->status === 'on_time' || $record->check_in) $statusColor = 'bg-emerald-500';
                                } elseif ($dDate->isPast() && !$dDate->isWeekend()) {
                                    $statusColor = 'bg-rose-500'; // Past and no record means absent
                                } elseif ($dDate->isWeekend()) {
                                    $statusColor = 'bg-zinc-300 dark:bg-zinc-700'; // Weekend
                                }
                            @endphp
                            <div class="flex flex-col items-center gap-1.5 flex-1">
                                <span class="text-[10px] uppercase font-semibold text-zinc-400">{{ $dayStr }}</span>
                                <div class="h-1.5 w-full rounded-full {{ $statusColor }} {{ $isToday ? 'ring-2 ring-offset-2 ring-indigo-500 dark:ring-offset-zinc-950 animate-pulse' : '' }}"></div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    @if($todayAttendance)
                        <div class="rounded-xl bg-green-50 dark:bg-green-900/20 px-3 py-2.5">
                            <div class="text-[10px] text-green-600 font-bold uppercase tracking-wide">Check In</div>
                            <div class="text-base font-bold text-green-700 dark:text-green-400 tabular-nums">
                                {{ $todayAttendance->check_in?->format('H:i') ?? '—' }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-zinc-100 dark:bg-zinc-800 px-3 py-2.5">
                            <div class="text-[10px] text-zinc-500 font-bold uppercase tracking-wide">Check Out</div>
                            <div class="text-base font-bold text-zinc-700 dark:text-zinc-200 tabular-nums">
                                {{ $todayAttendance->check_out?->format('H:i') ?? '—' }}
                            </div>
                        </div>
                    @else
                        <div class="col-span-2 flex flex-col items-center py-4 text-zinc-400 bg-zinc-100 dark:bg-zinc-800 rounded-xl">
                            <flux:icon.clock class="size-6 mb-1 opacity-40" />
                            <p class="text-[10px] uppercase font-bold tracking-widest">Not Clocked In</p>
                        </div>
                    @endif
                </div>

                <a href="{{ route('attendance.my') }}" wire:navigate>
                    <flux:button variant="ghost" size="sm" class="w-full mt-3 justify-center">Open Attendance</flux:button>
                </a>
            </div>

            {{-- ---- LEAVE BALANCES ---- --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950 p-5">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">Leave Balances</span>
                    <a href="{{ route('time-off.my') }}" wire:navigate
                       class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium">Request Leave</a>
                </div>

                @if($leaveBalances->isEmpty())
                    <p class="text-xs text-zinc-400 text-center py-3">No balances configured. Contact HR.</p>
                @else
                    <div class="space-y-4 mt-2">
                        @foreach($leaveBalances as $balance)
                            @php
                                $typeCode = $balance->leaveType?->code ?? 'OTHER';
                                $total     = (float)($balance->allocated_days ?? 0);
                                $used      = (float)($balance->used_days ?? 0);
                                $remaining = max(0, $total - $used);
                                $pct       = $total > 0 ? min(100, ($used / $total) * 100) : 0;
                                
                                $color = match($typeCode) {
                                    'CSL' => 'bg-indigo-500',
                                    'CO'  => 'bg-emerald-500',
                                    'MDL' => 'bg-amber-500',
                                    default => 'bg-zinc-500'
                                };
                            @endphp
                            <div>
                                <div class="flex justify-between items-end mb-1.5">
                                    <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $balance->leaveType?->name ?? 'Leave' }}
                                    </span>
                                    <span class="text-xs text-zinc-500">
                                        <span class="font-bold text-zinc-900 dark:text-white">{{ $remaining }}</span> remaining
                                    </span>
                                </div>
                                <div class="h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full overflow-hidden">
                                    <div class="h-full {{ $color }} rounded-full transition-all duration-500"
                                         style="width: {{ 100 - $pct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ---- UPCOMING & APPROVALS ---- --}}
            <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-950 p-5">
                <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest block mb-3">Upcoming</span>

                <div class="space-y-3">
                    @if($nextPublicHoliday)
                        <div class="flex items-start gap-3 p-3 rounded-xl bg-indigo-50 dark:bg-indigo-900/10 border border-indigo-100 dark:border-indigo-800/50">
                            <flux:icon.sparkles class="size-4 text-indigo-500 mt-0.5 shrink-0" />
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200 truncate">{{ $nextPublicHoliday->name }}</p>
                                <p class="text-[10px] text-zinc-500">{{ \Carbon\Carbon::parse($nextPublicHoliday->date)->format('l, d F Y') }}</p>
                            </div>
                            <span class="shrink-0 ml-auto text-[9px] font-bold px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400">HOLIDAY</span>
                        </div>
                    @endif

                    @foreach($pendingLeaveRequests->take(2) as $leave)
                        <div class="flex items-start gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/50">
                            <flux:icon.calendar class="size-4 text-amber-500 mt-0.5 shrink-0" />
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200 truncate">Leave: {{ $leave->leaveType?->name }}</p>
                                <p class="text-[10px] text-zinc-500">{{ \Carbon\Carbon::parse($leave->start_date)->format('d M') }} - {{ \Carbon\Carbon::parse($leave->end_date)->format('d M') }}</p>
                            </div>
                            <span class="shrink-0 ml-auto text-[9px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">PENDING</span>
                        </div>
                    @endforeach

                    @foreach($myOtRequests->where('status','pending')->take(2) as $ot)
                        <div class="flex items-start gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-800/50">
                            <flux:icon.clock class="size-4 text-amber-500 mt-0.5 shrink-0" />
                            <div class="min-w-0">
                                <p class="text-xs font-medium text-zinc-800 dark:text-zinc-200 truncate">OT Request</p>
                                <p class="text-[10px] text-zinc-500">{{ \Carbon\Carbon::parse($ot->work_date)->format('d M') }} · {{ $ot->requested_hours }}h</p>
                            </div>
                            <span class="shrink-0 ml-auto text-[9px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">PENDING</span>
                        </div>
                    @endforeach

                    @if(!$nextPublicHoliday && $pendingLeaveRequests->isEmpty() && $myOtRequests->where('status','pending')->isEmpty())
                        <div class="flex flex-col items-center py-4 text-zinc-400">
                            <flux:icon.check-circle class="size-8 mb-2 opacity-40" />
                            <p class="text-xs">Nothing upcoming.</p>
                        </div>
                    @endif
                </div>
            </div>

        </div>

        {{-- ========================================================= --}}
        {{-- RIGHT COLUMN: Main activity area                          --}}
        {{-- ========================================================= --}}
        <div class="lg:col-span-2 p-6 space-y-6 bg-zinc-50 dark:bg-zinc-950">

            {{-- ---- STAT CARDS ROW ---- --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">

                @php
                    $totalLeave    = $leaveBalances->sum('allocated_days');
                    $usedLeave     = $leaveBalances->sum('used_days');
                    $remainLeave   = max(0, $totalLeave - $usedLeave);
                    $pendingOtCnt  = $myOtRequests->where('status','pending')->count();
                    $latestPayslip = $myPayslips->first();
                @endphp

                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Leave Left</span>
                        <div class="size-7 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <flux:icon.calendar-days class="size-4 text-amber-600" />
                        </div>
                    </div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white">{{ $remainLeave }}</div>
                    <div class="text-xs text-zinc-400">{{ $usedLeave }} used this year</div>
                </div>

                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">OT Pending</span>
                        <div class="size-7 rounded-full bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                            <flux:icon.clock class="size-4 text-purple-600" />
                        </div>
                    </div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white">{{ $pendingOtCnt }}</div>
                    <div class="text-xs text-zinc-400">requests awaiting</div>
                </div>

                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Net Salary</span>
                        <div class="size-7 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center">
                            <flux:icon.banknotes class="size-4 text-emerald-600" />
                        </div>
                    </div>
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">
                        {{ $latestPayslip ? '₹'.number_format($latestPayslip->net_salary/1000, 1).'k' : '—' }}
                    </div>
                    <div class="text-xs text-zinc-400">{{ $latestPayslip?->payroll?->month ?? 'Last payslip' }}</div>
                </div>

                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-4 flex flex-col gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Payslips</span>
                        <div class="size-7 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
                            <flux:icon.document-text class="size-4 text-indigo-600" />
                        </div>
                    </div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white">{{ $myPayslips->count() }}</div>
                    <div class="text-xs text-zinc-400">available to download</div>
                </div>

            </div>

            {{-- ---- BOTTOM 2-PANEL ROW ---- --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                {{-- Inbox / Actions Task List --}}
                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.inbox class="size-4 text-rose-500" /> Action Required
                        </h3>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-400">
                            {{ $pendingActions->count() }} TASKS
                        </span>
                    </div>

                    @if($pendingActions->isEmpty())
                        <div class="flex flex-col items-center justify-center py-8 text-zinc-400">
                            <flux:icon.check-badge class="size-10 mb-3 text-emerald-400 opacity-60" />
                            <p class="text-xs font-medium text-zinc-500">You're all caught up!</p>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($pendingActions as $action)
                                <a href="{{ $action['url'] }}" wire:navigate class="flex items-start gap-3 p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 hover:bg-zinc-100 dark:hover:bg-zinc-800 border border-transparent hover:border-zinc-200 dark:hover:border-zinc-700 transition cursor-pointer">
                                    <div class="mt-0.5">
                                        <div class="size-4 rounded border-2 border-zinc-300 dark:border-zinc-600"></div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $action['title'] }}
                                        </div>
                                        <div class="text-xs text-zinc-400">{{ \Carbon\Carbon::parse($action['date'])->diffForHumans() }}</div>
                                    </div>
                                    <flux:icon.chevron-right class="size-4 text-zinc-400 self-center" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Recent Payslips --}}
                <div class="rounded-2xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.document-text class="size-4 text-emerald-500" /> Recent Payslips
                        </h3>
                        <a href="{{ route('payroll.payslips') }}" wire:navigate
                           class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium">View All →</a>
                    </div>

                    @if($myPayslips->isEmpty())
                        <div class="flex flex-col items-center py-8 text-zinc-400">
                            <flux:icon.document-text class="size-8 mb-2 opacity-30" />
                            <p class="text-xs">No payslips generated yet.</p>
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($myPayslips as $slip)
                                <div class="flex items-center justify-between p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ $slip->payroll?->month }} {{ $slip->payroll?->year }}
                                        </div>
                                        <div class="text-xs text-zinc-400">Net: ₹{{ number_format($slip->net_salary, 0) }}</div>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0 ml-2">
                                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-full
                                            {{ $slip->status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
                                            {{ strtoupper($slip->status) }}
                                        </span>
                                        <a href="{{ route('payroll.payslips') }}" wire:navigate
                                           class="size-7 flex items-center justify-center rounded-lg bg-zinc-200 dark:bg-zinc-700 hover:bg-brand-100 dark:hover:bg-brand-900/30 transition"
                                           title="View Payslips">
                                            <flux:icon.arrow-down-tray class="size-3.5 text-zinc-600 dark:text-zinc-300" />
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

        </div>

    </div>

</flux:main>

{{-- Live Clock Script --}}
<script>
    (function () {
        function tick() {
            const el = document.getElementById('live-clock');
            if (!el) return;
            const now = new Date();
            let h = now.getHours();
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            const m = String(now.getMinutes()).padStart(2, '0');
            el.innerHTML = `${h}:${m}<span class="text-lg font-medium text-zinc-400 ml-1">${ampm}</span>`;
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>
