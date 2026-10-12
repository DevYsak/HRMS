{{--
    Today's punches drawer — the whole day in one place: every Today Activity
    event with its details, the punch table (each kept punch's meaning from
    the PunchClassifier journey; missing punches from the PunchTimeline
    engine), sessions, raw scans and the device. Opened from Today Activity
    ("View All Activity" / the ⋮ menu) and Today's Summary ("View raw punches").
--}}
@php
    $statusChip = match (true) {
        $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        $autoClosed => ['Auto Checkout', 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
        $missingOut => ['Missing checkout', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300'],
        (bool) $lastOut => ['Completed', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        default => [match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] }, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-slate-300'],
    };
    // The device's last sync, and the serial the engine stamped on today's summary first.
    $online = $biometricDevice?->last_synced_at;
    $isOnline = $online && \Carbon\Carbon::parse($online)->gt(now()->subMinutes(30));
    $deviceSerial = $todaySummary?->device_serial
        ?: collect($syncHistory)->pluck('serial')->filter()->first()
        ?: $biometricDevice?->serial_number;

    $nodes = $pj['nodes'] ?? [];
    $hasDrawer = $nodes !== [] || (int) ($pj['raw_count'] ?? 0) > 0 || $activity['events'] !== [];

    // Each kept punch's meaning from the classified journey, matched by time.
    $journeyByTime = collect($attendanceJourney)->groupBy('time')->map(fn ($g) => $g->pluck('type')->all())->all();
    $rows = [];
    $prevWasBreak = false;
    foreach ($nodes as $i => $node) {
        $isMissing = $node['type'] === 'missing';
        $kind = ! $isMissing && ! empty($journeyByTime[$node['time']]) ? array_shift($journeyByTime[$node['time']]) : null;
        // The classifier's rule — every OUT before the departure opens a break —
        // also holds while a session is still open: an OUT with a later IN is a
        // break, and the IN after it a return.
        $laterIn = collect(array_slice($nodes, $i + 1))->contains(fn ($n) => $n['type'] !== 'missing' && $n['dir'] === 'IN');
        if ($kind === 'out' && $laterIn) {
            $kind = 'break';
        } elseif ($kind === 'in' && $prevWasBreak) {
            $kind = 'resume';
        }
        $prevWasBreak = $kind === 'break';
        $isReg = ($node['source'] ?? '') === 'regularisation';
        [$statusLabel, $statusClass] = match (true) {
            $isMissing => ['Missing '.$node['dir'], 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400'],
            $isReg => ['Regularised', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
            $node['type'] === 'live' && $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
            $kind === 'break' => ['Break Out', 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400'],
            $kind === 'resume' => ['Break In', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
            default => ['Present', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        };
        $rows[] = [
            'time' => $node['time'],
            'type' => $isMissing ? '—' : $node['dir'],
            'source' => $isMissing ? 'System' : ($node['method_label'] ?? match ($node['source'] ?? '') { 'web' => 'Web', 'regularisation' => 'Regularisation', default => '—' }),
            'status' => $statusLabel,
            'status_class' => $statusClass,
            'dot' => match (true) { $isMissing => 'bg-red-500', $isReg => 'bg-violet-500', $node['dir'] === 'IN' => 'bg-emerald-500', default => 'bg-orange-500' },
            'regularised' => $isReg,
        ];
    }
    // The system closed the day at the shift end: shown as its own OUT row.
    if ($autoClosed && $lastOut) {
        $rows[] = [
            'time' => $lastOut, 'type' => 'OUT', 'source' => 'System', 'status' => 'Auto Checkout',
            'status_class' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300',
            'dot' => 'bg-orange-400', 'regularised' => false,
        ];
    }
    $section = 'mb-2 text-sm font-semibold text-zinc-900 dark:text-white';
@endphp

@if($hasDrawer || $deviceSerial || $biometricDevice)
<flux:modal name="today-punches" flyout class="w-full md:w-[36rem]" data-raw-punches>
    <div class="space-y-6">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg">Today's punches</flux:heading>
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $statusChip[1] }}">{{ $statusChip[0] }}</span>
            </div>
            <flux:subheading>{{ now()->format('l, d F Y') }} · {{ count($activity['events']) }} {{ \Illuminate\Support\Str::plural('event', count($activity['events'])) }} · {{ (int) ($pj['raw_count'] ?? 0) }} raw {{ \Illuminate\Support\Str::plural('scan', (int) ($pj['raw_count'] ?? 0)) }}</flux:subheading>
        </div>

        @if($activity['events'] !== [])
            <section data-activity-all>
                <h3 class="{{ $section }}">All activity</h3>
                <ol class="relative space-y-4 border-l border-zinc-200 pl-6 dark:border-white/10">
                    @foreach($activity['events'] as $event)
                        @php $dot = match ($event['kind']) { 'in' => 'bg-emerald-500', 'out' => 'bg-orange-500', 'break_start' => 'bg-amber-400', 'break_end' => 'bg-sky-500', 'auto' => 'border-2 border-dashed border-orange-400 bg-white dark:bg-[#111A2E]', 'regularised' => 'bg-violet-500', default => 'bg-zinc-400' }; @endphp
                        <li class="relative">
                            <span class="absolute -left-[31px] top-1 size-3.5 rounded-full ring-4 ring-white dark:ring-zinc-900 {{ $dot }}" aria-hidden="true"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $event['time'] }}</span>
                                <span class="text-sm text-zinc-700 dark:text-slate-200">{{ $event['title'] }}</span>
                                <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-zinc-600 dark:bg-white/[0.06] dark:text-slate-300">{{ $event['badge'] }}</span>
                            </div>
                            <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                @foreach($event['details'] as $term => $value)
                                    <dt class="text-zinc-500 dark:text-slate-400">{{ $term }}</dt>
                                    <dd class="text-zinc-700 dark:text-slate-200">{{ $value }}</dd>
                                @endforeach
                            </dl>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if($rows !== [])
            <section>
                <h3 class="{{ $section }}">Punches</h3>
                <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-white/[0.06]">
                    <table class="w-full text-sm" data-punch-table>
                        <thead class="bg-zinc-50 text-left text-xs text-zinc-500 dark:bg-white/[0.03] dark:text-slate-400">
                            <tr><th class="py-2 pl-8 font-medium">Time</th><th class="py-2 font-medium">Punch Type</th><th class="py-2 font-medium">Source</th><th class="py-2 pr-3 font-medium">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.06]">
                            @foreach($rows as $row)
                                <tr @if($row['regularised']) data-regularised @endif>
                                    <td class="py-2 pl-3 font-semibold tabular-nums text-zinc-900 dark:text-white"><span class="inline-flex items-center gap-3"><span class="size-2.5 shrink-0 rounded-full {{ $row['dot'] }}"></span>{{ $row['time'] }}</span></td>
                                    <td class="py-2 text-zinc-700 dark:text-slate-300">{{ $row['type'] }}</td>
                                    <td class="py-2 text-zinc-500 dark:text-slate-400">{{ $row['source'] }}</td>
                                    <td class="py-2 pr-3"><span class="whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $row['status_class'] }}">{{ $row['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if($nodes !== [])
            <section>
                <h3 class="{{ $section }}">Timeline</h3>
                @include('attendance.my.punch-list', ['nodes' => $nodes])
            </section>
        @endif

        @if(! empty($pj['sessions']))
            <section>
                <h3 class="{{ $section }}">Sessions:</h3>
                <ul class="space-y-1 text-sm tabular-nums text-zinc-700 dark:text-slate-300">
                    @foreach($pj['sessions'] as $session)
                        <li>{{ $session['in'] ?? '—' }} → {{ $session['out'] ?? ($session['live'] ? 'now' : '—') }} <span class="text-zinc-400">({{ $session['label'] }})</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(! empty($pj['raw_events']))
            <section>
                <h3 class="{{ $section }}">Raw punches</h3>
                <div class="max-h-[45vh] overflow-y-auto rounded-xl border border-zinc-100 dark:border-white/[0.06]">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-zinc-50 text-left text-[11px] text-zinc-500 dark:bg-[#18233A] dark:text-slate-400"><tr><th class="px-3 py-2 font-medium">Time</th><th class="px-2 font-medium">Direction</th><th class="px-2 font-medium">Method</th><th class="px-2 font-medium">Device</th><th class="px-2 font-medium">Used</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100 text-zinc-700 dark:divide-white/[0.06] dark:text-slate-300">
                            @foreach($pj['raw_events'] as $event)
                                <tr>
                                    <td class="px-3 py-1.5 tabular-nums">{{ $event['time'] }}</td>
                                    <td class="px-2">{{ strtoupper((string) ($event['direction'] ?? '—')) }}</td>
                                    <td class="px-2">{{ $event['method'] ?? '—' }}</td>
                                    <td class="px-2">{{ $event['device'] ?? '—' }}</td>
                                    <td class="px-2">{{ ($event['flag'] ?? 'kept') === 'kept' ? 'Yes' : ucfirst((string) $event['flag']).($event['note'] ? ' — '.$event['note'] : '') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if($biometricDevice || $deviceSerial)
            <section class="text-xs text-zinc-500 dark:text-slate-400">
                Device: <span class="font-semibold text-zinc-700 dark:text-slate-200">{{ $biometricDevice?->name ?? '—' }}</span>
                @if($deviceSerial) · Serial {{ $deviceSerial }} @endif
                · <span @class(['font-semibold', 'text-emerald-600' => $isOnline, 'text-amber-600' => ! $isOnline])>{{ $isOnline ? 'Online' : ($online ? 'Delayed' : 'Never synced') }}</span>
                @if($online) · last sync {{ \Carbon\Carbon::parse($online)->format('d M, h:i A') }} @endif
            </section>
        @endif
    </div>
</flux:modal>
@endif
