<div class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    {{-- Premium Header --}}
    <div class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 px-6 md:px-10 py-8">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(29,183,122,0.12),_transparent_60%)]"></div>
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <p class="text-zinc-400 text-sm mb-1">{{ now()->format('l, jS F Y') }}</p>
                <h1 class="text-2xl font-bold text-white tracking-tight">
                    {{ $department ? $department->name . ' Department' : 'Department Dashboard' }}
                </h1>
                <p class="text-zinc-400 text-sm mt-1">Team attendance, leave & OT overview</p>
            </div>
            @if($department)
                <div class="flex items-center gap-2">
                    <a href="{{ route('attendance.team') }}" wire:navigate
                       class="flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white rounded-xl text-sm font-semibold transition-all">
                        <flux:icon.clock class="size-4" /> Attendance
                    </a>
                    <a href="{{ route('time-off.team') }}" wire:navigate
                       class="flex items-center gap-2 px-4 py-2.5 bg-brand-600 hover:bg-brand-700 border border-brand-500 text-white rounded-xl text-sm font-semibold transition-all shadow-lg shadow-brand-900/20">
                        <flux:icon.calendar-days class="size-4" /> Leave Approvals
                    </a>
                </div>
            @endif
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        @if(!$department)
            <div class="bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-900/40 rounded-2xl p-5 flex items-start gap-3">
                <flux:icon.exclamation-triangle class="size-5 text-amber-500 shrink-0 mt-0.5" />
                <div>
                    <div class="font-bold text-amber-800 dark:text-amber-400">No Department Assigned</div>
                    <p class="text-sm text-amber-700 dark:text-amber-500 mt-0.5">You are not currently designated as the head of any department. Contact HR Admin.</p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Total Staff</span>
                        <div class="size-8 rounded-xl bg-blue-50 dark:bg-blue-950/30 flex items-center justify-center">
                            <flux:icon.users class="size-4 text-blue-600" />
                        </div>
                    </div>
                    <div class="text-4xl font-black text-zinc-900 dark:text-white">{{ $totalEmployees }}</div>
                    <div class="text-[10px] text-zinc-400 mt-1">in your department</div>
                </div>

                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present</span>
                        <div class="size-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 flex items-center justify-center">
                            <flux:icon.check-circle class="size-4 text-emerald-600" />
                        </div>
                    </div>
                    <div class="text-4xl font-black text-emerald-600">{{ $presentToday }}</div>
                    <div class="text-[10px] text-zinc-400 mt-1">clocked in today</div>
                </div>

                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Late</span>
                        <div class="size-8 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center">
                            <flux:icon.clock class="size-4 text-amber-500" />
                        </div>
                    </div>
                    <div class="text-4xl font-black text-amber-500">{{ $lateToday }}</div>
                    <div class="text-[10px] text-zinc-400 mt-1">late arrivals today</div>
                </div>

                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Absent</span>
                        <div class="size-8 rounded-xl bg-rose-50 dark:bg-rose-950/30 flex items-center justify-center">
                            <flux:icon.x-circle class="size-4 text-rose-500" />
                        </div>
                    </div>
                    <div class="text-4xl font-black text-rose-500">{{ $absentToday }}</div>
                    <div class="text-[10px] text-zinc-400 mt-1">no check-in recorded</div>
                </div>
            </div>

            {{-- Quick links --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="{{ route('attendance.team') }}" wire:navigate
                   class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm hover:border-brand-300 dark:hover:border-brand-700 transition-colors flex items-center gap-4">
                    <div class="size-10 rounded-xl bg-brand-50 dark:bg-brand-950/30 flex items-center justify-center shrink-0">
                        <flux:icon.clock class="size-5 text-brand-600" />
                    </div>
                    <div>
                        <div class="font-bold text-zinc-900 dark:text-white text-sm">Team Attendance</div>
                        <div class="text-xs text-zinc-400">View & regularise records</div>
                    </div>
                    <flux:icon.chevron-right class="size-4 text-zinc-300 ml-auto" />
                </a>
                <a href="{{ route('time-off.team') }}" wire:navigate
                   class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm hover:border-amber-300 dark:hover:border-amber-700 transition-colors flex items-center gap-4">
                    <div class="size-10 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center shrink-0">
                        <flux:icon.calendar-days class="size-5 text-amber-500" />
                    </div>
                    <div>
                        <div class="font-bold text-zinc-900 dark:text-white text-sm">Leave Approvals</div>
                        <div class="text-xs text-zinc-400">Review pending leave requests</div>
                    </div>
                    <flux:icon.chevron-right class="size-4 text-zinc-300 ml-auto" />
                </a>
                <a href="{{ route('overtime.manage') }}" wire:navigate
                   class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm hover:border-purple-300 dark:hover:border-purple-700 transition-colors flex items-center gap-4">
                    <div class="size-10 rounded-xl bg-purple-50 dark:bg-purple-950/30 flex items-center justify-center shrink-0">
                        <flux:icon.bolt class="size-5 text-purple-500" />
                    </div>
                    <div>
                        <div class="font-bold text-zinc-900 dark:text-white text-sm">OT Approvals</div>
                        <div class="text-xs text-zinc-400">Approve overtime requests</div>
                    </div>
                    <flux:icon.chevron-right class="size-4 text-zinc-300 ml-auto" />
                </a>
            </div>
        @endif

    </div>
</div>
