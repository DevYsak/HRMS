@props(['holidays'])

{{-- EmployeeUpcomingHolidays — the next holidays on the employee's own
     calendar (HolidayResolver), with a link to the full Holiday Calendar. --}}
<x-employee.dashboard.card title="Upcoming holidays" icon="sun" :href="Route::has('holidays.calendar') ? route('holidays.calendar') : null" cta="Calendar" {{ $attributes }}>
    @if($holidays->isEmpty())
        <x-employee.dashboard.empty-state icon="sun" title="No upcoming holidays." text="Holidays on your calendar will appear here." />
    @else
        <ul class="space-y-2">
            @foreach($holidays->take(4) as $holiday)
                @php $date = \Illuminate\Support\Carbon::parse($holiday->date); @endphp
                <li class="flex items-center gap-3">
                    <span class="flex w-10 shrink-0 flex-col items-center rounded-lg bg-orange-50 py-1 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">
                        <span class="text-[9px] font-bold uppercase leading-none">{{ $date->format('M') }}</span>
                        <span class="text-sm font-bold leading-tight tabular-nums">{{ $date->format('j') }}</span>
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm text-zinc-800 dark:text-zinc-100">{{ $holiday->name }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $date->format('l') }}@if($holiday->is_optional) · optional @endif</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-employee.dashboard.card>
