<flux:main class="space-y-5 p-4 md:p-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Attendance Exceptions</flux:heading>
            <flux:subheading>Absences, late arrivals, missing check-outs and pending regularisations among the {{ $monitoredCount }} {{ \Illuminate\Support\Str::plural('person', $monitoredCount) }} you monitor. Weekly offs, holidays and approved leave are never counted.</flux:subheading>
        </div>
        <flux:input type="date" wire:model.live="date" max="{{ now()->toDateString() }}" class="max-w-[11rem]" aria-label="Date" />
    </div>

    @if($monitoredCount === 0)
        <flux:callout icon="information-circle">
            <flux:callout.text>Nobody is assigned to you yet. HR assigns the employees and departments you monitor in Settings → Coordinators.</flux:callout.text>
        </flux:callout>
    @endif

    @php
        $labels = ['absent' => 'Absent', 'late' => 'Late', 'missing_checkout' => 'Missing check-out', 'regularisation' => 'Regularisation pending'];
    @endphp

    <div class="flex flex-wrap gap-2">
        @foreach($labels as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                'rounded-xl px-3 py-1.5 text-xs font-bold transition',
                'bg-orange-500 text-white' => $tab === $key,
                'border border-zinc-200 bg-white text-zinc-600 hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => $tab !== $key,
            ])>{{ $label }} <span class="ml-1 tabular-nums">{{ $exceptions[$key]->count() }}</span></button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <table class="w-full min-w-[560px] text-sm">
            <thead class="bg-zinc-50/70 text-left text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:bg-white/5">
                <tr><th class="px-4 py-2">Employee</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Detail</th><th class="px-4 py-2 text-right">Follow up</th></tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($exceptions[$tab] as $row)
                    <tr wire:key="ex-{{ $tab }}-{{ $row['employee_id'] }}-{{ $row['date'] }}">
                        <td class="px-4 py-2.5 font-medium text-zinc-800 dark:text-zinc-100">{{ $row['name'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums text-zinc-500">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('D d M') }}</td>
                        <td class="px-3 py-2.5 text-zinc-500">{{ $row['detail'] }}</td>
                        <td class="whitespace-nowrap px-4 py-2.5 text-right">
                            @if($canRemind)
                                <flux:button size="xs" wire:click="remind({{ $row['employee_id'] }}, '{{ $row['type'] }}', '{{ $row['date'] }}')">Remind</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="escalate({{ $row['employee_id'] }}, '{{ $row['type'] }}', '{{ $row['date'] }}', 'manager')">To manager</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="escalate({{ $row['employee_id'] }}, '{{ $row['type'] }}', '{{ $row['date'] }}', 'hr')">To HR</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-zinc-400">Nothing to follow up for {{ $day->format('D d M') }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</flux:main>
