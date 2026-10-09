{{-- "Live Attendance" — opens the live panel; shown only to holders of view_live_attendance. --}}
@if(auth()->user()?->hasPermission(\App\Services\Attendance\LiveAttendanceService::PERMISSION))
    <a href="{{ route('attendance.live') }}" wire:navigate data-live-attendance-button
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-xl border border-orange-200 bg-white px-3.5 py-2 text-sm font-semibold text-orange-700 shadow-sm transition hover:border-orange-300 hover:bg-orange-50 dark:border-orange-500/30 dark:bg-zinc-900 dark:text-orange-300 dark:hover:bg-orange-500/10']) }}>
        <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
        Live Attendance
    </a>
@endif
