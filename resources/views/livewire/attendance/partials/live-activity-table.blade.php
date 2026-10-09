{{-- Live Attendance activity rows ($events, newest first). Needs $eventBadge and $statusTone. --}}
@if($events === [])
    <p class="px-5 pb-8 pt-2 text-sm text-zinc-500 dark:text-zinc-400">No attendance activity yet today.</p>
@else
    <div class="relative overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm" data-live-activity>
            <thead>
                <tr class="border-y border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <th class="px-5 py-2 font-medium">Time</th>
                    <th class="px-3 py-2 font-medium">Employee</th>
                    <th class="px-3 py-2 font-medium">Department</th>
                    <th class="px-3 py-2 font-medium">Event</th>
                    <th class="px-3 py-2 font-medium">Source</th>
                    <th class="px-5 py-2 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($events as $e)
                    <tr class="text-zinc-700 dark:text-zinc-300" wire:key="live-{{ $e['key'] }}" data-live-event="{{ $e['key'] }}">
                        <td class="whitespace-nowrap px-5 py-2.5 font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $e['time'] }}</td>
                        <td class="px-3 py-2.5 font-medium text-zinc-900 dark:text-white">{{ $e['employee'] }}</td>
                        <td class="px-3 py-2.5">{{ $e['department'] }}</td>
                        <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-bold {{ $eventBadge[$e['event']] ?? $eventBadge['OUT'] }}">{{ $e['event'] }}</span></td>
                        <td class="px-3 py-2.5 text-zinc-500 dark:text-zinc-400">{{ $e['source'] }}</td>
                        <td class="px-5 py-2.5 font-semibold {{ $statusTone($e['status']) }}">{{ $e['status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
