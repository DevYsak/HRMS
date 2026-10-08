{{--
    Today: the punch timeline (left) and the day's totals (right) in one card —
    replaces Attendance Journey, Session Summary, Working Hours Breakdown,
    Shift Progress and Biometric Status. Raw punches, sessions and the device
    sit in the "View raw punches" drawer.
--}}
@php
    $otMinutes = (int) ($todayCalc['approved_ot_minutes'] ?? 0);
    $statusChip = match (true) {
        $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        $missingOut => ['Missing checkout', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
        (bool) $lastOut => ['Completed', 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'],
        default => [match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] }, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'],
    };
    $nodeTone = fn (array $n): array => match (true) {
        $n['type'] === 'missing' => ['bg-amber-500', 'text-amber-700 dark:text-amber-400'],
        ($n['source'] ?? '') === 'regularisation' => ['bg-violet-500', 'text-violet-700 dark:text-violet-300'],
        $n['dir'] === 'IN' => ['bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
        default => ['bg-rose-400', 'text-zinc-600 dark:text-zinc-300'],
    };
    // Same reading as the former Biometric Status card: the device's last sync,
    // and the serial the engine stamped on today's summary first.
    $online = $biometricDevice?->last_synced_at;
    $isOnline = $online && \Carbon\Carbon::parse($online)->gt(now()->subMinutes(30));
    $deviceSerial = $todaySummary?->device_serial
        ?: collect($syncHistory)->pluck('serial')->filter()->first()
        ?: $biometricDevice?->serial_number;
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="today-title" data-today>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
        <div class="flex items-center gap-3">
            <h2 id="today-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Today</h2>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ now()->format('D, d M') }}</span>
            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusChip[1] }}">{{ $statusChip[0] }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if(! $todayAttendance)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600">
                    <flux:icon.arrow-right-end-on-rectangle class="size-4" /> Clock In</button>
            @elseif($isIn)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600">
                    <flux:icon.arrow-left-start-on-rectangle class="size-4" /> Clock Out</button>
                @if($activeBreak)
                    <button type="button" wire:click="endBreak" class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">End break</button>
                @else
                    <button type="button" wire:click="startBreak" class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">Start break</button>
                @endif
            @endif
            <button type="button" wire:click="openRegularisation('{{ today()->toDateString() }}')"
                class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">Regularize</button>
        </div>
    </div>

    <div class="grid gap-0 md:grid-cols-[minmax(0,1fr)_minmax(0,18rem)]">
        {{-- Punch timeline --}}
        <div class="px-5 py-4">
            @if(! empty($pj['nodes']))
                <ol class="relative space-y-3 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-zinc-200 dark:before:bg-zinc-700" data-punch-timeline>
                    @foreach($pj['nodes'] as $node)
                        @php [$dot, $text] = $nodeTone($node); $isMissing = $node['type'] === 'missing'; @endphp
                        <li class="relative flex items-baseline gap-4 pl-6" @if(($node['source'] ?? '') === 'regularisation') data-regularised @endif>
                            <span class="absolute left-0 top-1.5 size-[11px] rounded-full ring-4 ring-white dark:ring-zinc-900 {{ $dot }} @if($node['type'] === 'live') animate-pulse @endif"></span>
                            <span class="w-20 shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $isMissing ? '—' : $node['time'] }}</span>
                            <span class="text-sm {{ $text }}">
                                @if($isMissing)
                                    Missing {{ $node['dir'] }} — needs regularisation
                                @else
                                    {{ $node['method_label'] ?? (($node['source'] ?? '') === 'web' ? 'Web punch' : 'Punch') }} • {{ $node['dir'] }}
                                    @if(($node['source'] ?? '') === 'regularisation')<span class="ml-1 rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">Regularised</span>@endif
                                    @if($node['type'] === 'live')<span class="ml-1 text-[11px] font-semibold text-emerald-600">working now</span>@endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>
            @elseif($todayAttendance?->check_in)
                {{-- Web/mobile punch with no biometric events --}}
                <ol class="space-y-3 text-sm" data-punch-timeline>
                    <li class="flex gap-4"><span class="w-20 font-semibold tabular-nums">{{ $todayAttendance->check_in->format('h:i A') }}</span><span class="text-emerald-700 dark:text-emerald-400">{{ ucfirst((string) ($todayAttendance->check_in_method ?? 'web')) }} • IN</span></li>
                    @if($todayAttendance->check_out)
                        <li class="flex gap-4"><span class="w-20 font-semibold tabular-nums">{{ $todayAttendance->check_out->format('h:i A') }}</span><span class="text-zinc-600 dark:text-zinc-300">{{ ucfirst((string) ($todayAttendance->check_out_method ?? 'web')) }} • OUT</span></li>
                    @endif
                </ol>
            @else
                <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No punches recorded today.</p>
            @endif
        </div>

        {{-- Totals --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-zinc-100 px-5 py-4 text-sm md:grid-cols-1 md:border-l md:border-t-0 dark:border-zinc-800">
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Worked</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $hm($workedMin) }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Break</dt><dd @class(['font-semibold tabular-nums', 'text-amber-600' => (bool) ($todayCalc['excess_break'] ?? false), 'text-zinc-900 dark:text-white' => ! ($todayCalc['excess_break'] ?? false)])>{{ $hm($breakMin) }}</dd></div>
            <div class="col-span-2 flex items-baseline justify-between gap-2 md:col-span-1"><dt class="text-zinc-500 dark:text-zinc-400">Shift</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $shiftWindow ?? 'Shift not assigned' }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Late</dt><dd @class(['font-semibold', 'text-amber-600' => $isLate, 'text-zinc-900 dark:text-white' => ! $isLate])>{{ $firstIn ? ($isLate ? 'Yes'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'No') : '—' }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">OT</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $otMinutes > 0 ? $hm($otMinutes).' approved' : '0h' }}</dd></div>
            <p class="col-span-2 text-[11px] leading-snug text-zinc-400 md:col-span-1">Worked = last OUT − first IN. Breaks are shown for information and never deducted.</p>
        </dl>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
        @if($issues->isEmpty())
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"><flux:icon.check-circle class="size-4" /> No attendance issues today</span>
        @else
            <a href="#attention" class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle class="size-4" /> {{ $issues->count() }} {{ \Illuminate\Support\Str::plural('item', $issues->count()) }} need attention</a>
        @endif

        @if((int) ($pj['raw_count'] ?? 0) > 0 || $deviceSerial || $biometricDevice)
            <details class="group w-full sm:w-auto" data-raw-punches>
                <summary class="cursor-pointer list-none text-xs font-semibold text-zinc-600 hover:text-orange-600 dark:text-zinc-300">
                    <span class="inline-flex items-center gap-1">View raw punches <flux:icon.chevron-down class="size-3.5 transition group-open:rotate-180" /></span>
                </summary>
                <div class="mt-3 space-y-3 rounded-xl bg-zinc-50 p-3 text-xs dark:bg-white/5 sm:min-w-[28rem]">
                    @if(! empty($pj['raw_events']))
                        <table class="w-full">
                            <thead class="text-left text-[11px] text-zinc-400"><tr><th class="py-1 font-medium">Time</th><th class="font-medium">Direction</th><th class="font-medium">Method</th><th class="font-medium">Device</th><th class="font-medium">Used</th></tr></thead>
                            <tbody class="text-zinc-700 dark:text-zinc-300">
                                @foreach($pj['raw_events'] as $event)
                                    <tr class="border-t border-zinc-200/70 dark:border-zinc-700/60">
                                        <td class="py-1 tabular-nums">{{ $event['time'] }}</td>
                                        <td>{{ strtoupper((string) ($event['direction'] ?? '—')) }}</td>
                                        <td>{{ $event['method'] ?? '—' }}</td>
                                        <td>{{ $event['device'] ?? '—' }}</td>
                                        <td>{{ ($event['flag'] ?? 'kept') === 'kept' ? 'Yes' : ucfirst((string) $event['flag']).($event['note'] ? ' — '.$event['note'] : '') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    @if(! empty($pj['sessions']))
                        <div><span class="font-semibold text-zinc-500">Sessions:</span>
                            @foreach($pj['sessions'] as $session)
                                <span class="ml-2 tabular-nums">{{ $session['in'] ?? '—' }} → {{ $session['out'] ?? ($session['live'] ? 'now' : '—') }} ({{ $session['label'] }})</span>
                            @endforeach
                        </div>
                    @endif
                    @if($biometricDevice || $deviceSerial)
                    <div class="text-zinc-500">
                        Device: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $biometricDevice?->name ?? '—' }}</span>
                        @if($deviceSerial) · Serial {{ $deviceSerial }} @endif
                        · <span @class(['font-semibold', 'text-emerald-600' => $isOnline, 'text-amber-600' => ! $isOnline])>{{ $isOnline ? 'Online' : ($online ? 'Delayed' : 'Never synced') }}</span>
                        @if($online) · last sync {{ \Carbon\Carbon::parse($online)->format('d M, h:i A') }} @endif
                    </div>
                    @endif
                </div>
            </details>
        @endif
    </div>
</section>
