{{--
    Day history — one person's date, opened from a row of the Attendance Log
    (AllAttendance::openDayHistory → AttendanceRegister::history): the day's
    figures, its timeline against the shift, the sessions and breaks, every
    punch as the system read it (missed punches where they were missed), what
    changed the day (regularisations with every approval step, HR
    corrections, the system's own events) and the raw device log.

    Needs $dayHistory plus the page's $hm, $short, $progressBar, $progressText,
    $chipTone, $clockAt, $hourAt, $ticksFor, $canCorrect and $canSeeLive.
--}}
@php
    $h = $dayHistory;
    $hl = $h['line'];
    $person = $h['employee'];
    $hDay = \Carbon\CarbonImmutable::parse($h['date']);
    $hTicks = $ticksFor($h['window']);
    $hPct = $hl['expected_minutes'] > 0 ? (int) floor($hl['worked_minutes'] * 100 / $hl['expected_minutes']) : 0;
    $trailState = [
        'counted' => ['Counted', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', 'bg-emerald-500'],
        'corrected' => ['Correction', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300', 'bg-violet-500'],
        'duplicate' => ['Read twice', 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400', 'bg-zinc-300 dark:bg-zinc-600'],
        'ignored' => ['Ignored', 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400', 'bg-zinc-300 dark:bg-zinc-600'],
        'superseded' => ['Replaced', 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-400', 'bg-zinc-300 dark:bg-zinc-600'],
        'missed' => ['Missed', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', 'bg-rose-500'],
        'missed_corrected' => ['Missed · corrected', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300', 'bg-violet-500'],
    ];
    $eventStyle = fn (string $kind): array => match ($kind) {
        'requested' => ['pencil-square', 'bg-violet-100 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300'],
        'approved', 'fast_tracked' => ['check-circle', 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300'],
        'rejected' => ['x-circle', 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300'],
        'deleted' => ['trash', 'bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300'],
        'edited', 'corrected' => ['pencil', 'bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-300'],
        default => ['cog-6-tooth', 'bg-zinc-100 text-zinc-500 dark:bg-white/10 dark:text-zinc-300'],
    };
    $requestTone = ['approved' => 'green', 'rejected' => 'red', 'pending' => 'amber', 'deleted' => 'zinc'];
    $lastOutText = $hl['last_out'] ?? ($hl['live'] ? 'In now' : ($hl['missing_checkout'] ? 'Missing' : '—'));
    $tiles = [
        ['First in', $hl['first_in'] ?? '—', $hl['late'] ? 'Late by '.$hl['late_minutes'].'m' : ($hl['shift_start'] ? 'Shift starts '.$hl['shift_start'] : ''), $hl['late'] ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400'],
        ['Last out', $lastOutText, $hl['auto_checkout'] ? 'Closed by the auto check-out' : ($hl['missing_checkout'] ? 'No check-out punch' : ''), $hl['missing_checkout'] || $hl['auto_checkout'] ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400'],
        ['Breaks', $hl['present'] ? $short($hl['break_minutes']) : '—', $hl['break_count'].' '.\Illuminate\Support\Str::plural('break', $hl['break_count']).($hl['excess_break'] ? ' · too long' : ''), $hl['excess_break'] ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400'],
        ['Overtime', $hl['ot_minutes'] > 0 ? $short($hl['ot_minutes']) : '—', $hl['ot_minutes'] > 0 ? 'approved' : 'none approved', 'text-zinc-400'],
        ['Punches', (string) $hl['punch_count'], trim(($hl['missed_count'] ? $hl['missed_count'].' missed' : '').($hl['missed_count'] && $hl['extra_taps'] ? ' · ' : '').($hl['extra_taps'] ? $hl['extra_taps'].' extra' : '')) ?: 'all counted', $hl['missed_count'] ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400'],
    ];
    $card = 'rounded-2xl border border-orange-100/80 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900';
    $cardTitle = 'mb-3 flex items-center justify-between gap-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400';
@endphp

<div class="fixed inset-0 z-40" x-data="{ show: false }" x-init="requestAnimationFrame(() => show = true)"
     x-on:keydown.escape.window="$wire.closeDayHistory()" data-day-history="{{ $person->id }}|{{ $h['date'] }}">
    <div class="absolute inset-0 bg-zinc-950/30 backdrop-blur-sm transition-opacity duration-300" :class="show ? 'opacity-100' : 'opacity-0'" @click="$wire.closeDayHistory()"></div>

    <aside class="absolute right-0 top-0 flex h-full w-[760px] max-w-full flex-col bg-[#FFF8F3] shadow-2xl transition-transform duration-300 ease-out dark:bg-zinc-950"
           :class="show ? 'translate-x-0' : 'translate-x-full'" role="dialog" aria-modal="true" aria-label="Day history — {{ $hl['name'] }}, {{ $hDay->userDate() }}">

        {{-- Header: who, which day, and the day's status --}}
        <div class="border-b border-orange-100 bg-white px-5 pb-3 pt-4 dark:border-white/10 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-sm font-black text-white shadow-md shadow-orange-200 dark:shadow-none">{{ $hl['initials'] }}</div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-base font-black text-zinc-900 dark:text-white">{{ $hl['name'] }}</span>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset {{ $chipTone[$hl['tone']] }}" data-day-history-status>{{ $hl['label'] }}</span>
                            @if($hl['regularised'])<span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-600 dark:bg-blue-500/10 dark:text-blue-300">Regularised</span>@endif
                        </div>
                        <div class="mt-0.5 truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $hl['code'] }} · {{ $hl['department'] }} · {{ $hl['shift'] }}</div>
                    </div>
                </div>
                <button type="button" @click="$wire.closeDayHistory()" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-orange-50 hover:text-zinc-700 dark:hover:bg-white/10 dark:hover:text-zinc-200" aria-label="Close"><flux:icon.x-mark class="size-5" /></button>
            </div>

            <div class="mt-3 flex items-center justify-between gap-2 rounded-xl bg-orange-50/70 p-1 dark:bg-white/5">
                <button type="button" wire:click="shiftHistoryDate(-1)" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold text-zinc-600 transition hover:bg-white hover:text-orange-600 dark:text-zinc-300 dark:hover:bg-zinc-900" data-history-prev>
                    <flux:icon.chevron-left class="size-4" /> Previous day
                </button>
                <div class="text-center">
                    <div class="text-sm font-black text-zinc-900 dark:text-white" data-day-history-date>{{ $hDay->format('l') }}, {{ $hDay->userDate() }}</div>
                    @if($hDay->isToday())<div class="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Today</div>@endif
                </div>
                <button type="button" wire:click="shiftHistoryDate(1)" @disabled($hDay->isToday()) class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold text-zinc-600 transition hover:bg-white hover:text-orange-600 disabled:pointer-events-none disabled:opacity-30 dark:text-zinc-300 dark:hover:bg-zinc-900" data-history-next>
                    Next day <flux:icon.chevron-right class="size-4" />
                </button>
            </div>
        </div>

        {{-- Body --}}
        <div class="flex-1 space-y-3 overflow-y-auto p-4" wire:loading.class="opacity-60" wire:target="shiftHistoryDate">

            {{-- Worked against the shift, and the day's figures --}}
            <div class="{{ $card }}">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Worked</div>
                        @if($hl['missing_checkout'])
                            <div class="mt-1 text-xl font-black text-rose-600 dark:text-rose-400">No check-out</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">The day is not counted until it is regularised.</div>
                        @elseif($hl['present'])
                            <div class="mt-1 flex items-baseline gap-2">
                                <span class="text-3xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $hm($hl['worked_minutes']) }}</span>
                                <span class="text-sm text-zinc-400">of {{ $hm($hl['expected_minutes']) }}{{ $hl['live'] ? ' · so far' : '' }}</span>
                            </div>
                        @else
                            <div class="mt-1 text-xl font-black text-zinc-400">{{ $hl['label'] }}</div>
                        @endif
                    </div>
                    @if($hl['present'] && ! $hl['missing_checkout'])
                        <div class="text-right">
                            <div class="text-2xl font-black tabular-nums {{ $progressText[$hl['progress']] }}">{{ $hPct }}%</div>
                            <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">of the shift</div>
                        </div>
                    @endif
                </div>
                @if($hl['present'] && ! $hl['missing_checkout'])
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                        <div class="h-full rounded-full {{ $progressBar[$hl['progress']] }}" style="width: {{ min(100, $hPct) }}%"></div>
                    </div>
                @endif
                <dl class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5" data-day-history-tiles>
                    @foreach($tiles as [$tileLabel, $tileValue, $tileSub, $tileSubTone])
                        <div class="rounded-xl bg-orange-50/50 px-3 py-2 dark:bg-white/5">
                            <dt class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ $tileLabel }}</dt>
                            <dd class="mt-0.5 font-mono text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $tileValue }}</dd>
                            @if($tileSub !== '')<dd class="text-[10px] font-semibold leading-snug {{ $tileSubTone }}">{{ $tileSub }}</dd>@endif
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- Timeline against the shift --}}
            <div class="{{ $card }}">
                <div class="{{ $cardTitle }}"><span>Day timeline</span><span class="normal-case tracking-normal">{{ $hl['shift'] }}</span></div>
                <div class="relative mb-1 h-3.5" aria-hidden="true">
                    @foreach($hTicks as $tick)
                        <span class="absolute -translate-x-1/2 whitespace-nowrap text-[9px] font-semibold tabular-nums text-zinc-400" style="left: {{ min(97, max(3, $tick['pct'])) }}%">{{ $hourAt($tick['at']) }}</span>
                    @endforeach
                </div>
                @include('livewire.attendance.partials.day-timeline-bar', ['line' => $hl, 'window' => $h['window'], 'ticks' => $hTicks, 'size' => 'lg'])
                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[10px] font-semibold text-zinc-500 dark:text-zinc-400">
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-emerald-500"></span> IN</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2 rounded-full bg-orange-500"></span> OUT</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-4 rounded bg-emerald-500"></span> Worked</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-4 rounded bg-orange-200 ring-1 ring-inset ring-orange-300"></span> Shift</span>
                    <span class="inline-flex items-center gap-1"><span class="size-2 rotate-45 rounded-[2px] bg-rose-500"></span> Missed punch</span>
                </div>

                @if($h['sessions'] !== [])
                    <ol class="mt-4 space-y-1.5 border-t border-zinc-100 pt-3 dark:border-white/10" data-day-history-sessions>
                        @foreach($h['sessions'] as $session)
                            <li class="flex items-center gap-3 rounded-xl px-2 py-1.5 {{ ! empty($session['missing']) ? 'bg-rose-50/70 dark:bg-rose-500/10' : 'bg-zinc-50/80 dark:bg-white/[0.03]' }}">
                                <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-lg text-[10px] font-black {{ ! empty($session['missing']) ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' }}">{{ $session['index'] }}</span>
                                <span class="flex-1 font-mono text-xs font-bold tabular-nums text-zinc-800 dark:text-zinc-100">
                                    {{ $session['in'] ?? '—' }} <span class="text-zinc-300">→</span> {{ $session['out'] ?? (! empty($session['live']) ? 'now' : 'missing') }}
                                </span>
                                <span class="text-xs font-black tabular-nums {{ ! empty($session['missing']) ? 'text-rose-600' : 'text-zinc-900 dark:text-white' }}">{{ $session['label'] }}{{ ! empty($session['live']) ? ' · live' : '' }}</span>
                            </li>
                            @if(($session['break_after'] ?? 0) > 0)
                                <li class="ml-5 flex items-center gap-2 border-l-2 border-dashed border-zinc-200 py-0.5 pl-5 text-[10px] font-semibold text-zinc-500 dark:border-white/10 dark:text-zinc-400">
                                    <flux:icon.pause-circle class="size-3.5 text-zinc-400" /> Out for {{ $session['break_after_label'] }}
                                </li>
                            @endif
                        @endforeach
                    </ol>
                @endif
            </div>

            {{-- Every punch, as the system read it --}}
            <div class="{{ $card }}">
                <div class="{{ $cardTitle }}"><span>Every punch · {{ count(array_filter($hl['trail'], fn ($n) => $n['time'] !== null)) }}</span>
                    @if($hl['missed_count'] > 0)<span class="normal-case tracking-normal text-rose-600 dark:text-rose-400">{{ $hl['missed_count'] }} missed</span>@endif
                </div>
                @if($hl['trail'] === [])
                    <p class="text-xs text-zinc-400">No punches on this day.</p>
                @else
                    <ol class="relative space-y-2 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-zinc-200 dark:before:bg-white/10" data-day-history-punches>
                        @foreach($hl['trail'] as $node)
                            @php [$stateLabel, $stateChip, $stateDot] = $trailState[$node['state']] ?? [ucfirst($node['state']), 'bg-zinc-100 text-zinc-500', 'bg-zinc-300']; @endphp
                            <li class="relative flex items-start gap-3 pl-6" data-history-punch="{{ $node['state'] }}">
                                <span class="absolute left-0 top-1 size-[11px] rounded-full ring-2 ring-white dark:ring-zinc-900 {{ $stateDot }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        @if($node['time'])<span class="font-mono text-xs font-black tabular-nums text-zinc-900 dark:text-white" title="{{ $node['time'] }} and {{ $node['seconds'] }} seconds">{{ $node['time'] }}</span>@endif
                                        <span class="text-xs font-semibold {{ in_array($node['state'], ['missed', 'missed_corrected'], true) ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-700 dark:text-zinc-200' }}">{{ $node['title'] }}</span>
                                        <span class="rounded-full px-1.5 py-px text-[9px] font-bold {{ $stateChip }}">{{ $stateLabel }}</span>
                                    </div>
                                    <div class="text-[10px] text-zinc-400">{{ $node['note'] }}@if($node['device']) · device {{ $node['device'] }}@endif</div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            {{-- What changed the day --}}
            <div class="{{ $card }}" data-day-history-changes>
                <div class="{{ $cardTitle }}"><span>What changed this day</span><span class="normal-case tracking-normal">{{ count($h['events']) }} {{ \Illuminate\Support\Str::plural('event', count($h['events'])) }}</span></div>

                @if($h['corrections'] !== [])
                    <div class="mb-3 space-y-1 rounded-xl bg-blue-50/70 px-3 py-2 text-xs dark:bg-blue-500/10">
                        @foreach($h['corrections'] as $field => $change)
                            <div class="flex flex-wrap items-center gap-2 text-blue-800 dark:text-blue-200">
                                <flux:icon.pencil-square class="size-3.5" /> <span class="font-semibold">{{ $field }}</span>
                                <span class="font-mono text-zinc-400 line-through">{{ $change['from'] }}</span> → <span class="font-mono font-bold">{{ $change['to'] }}</span>
                                <span class="text-[10px] text-blue-600/80 dark:text-blue-300/80">original punch kept</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($h['requests'] !== [])
                    <div class="mb-3 space-y-1.5">
                        @foreach($h['requests'] as $request)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-zinc-100 px-3 py-2 dark:border-white/10" data-history-request="{{ $request['status'] }}">
                                <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs">
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase ring-1 ring-inset {{ $chipTone[$requestTone[$request['status']] ?? 'zinc'] }}">{{ $request['status'] }}</span>
                                    <span class="font-mono font-bold text-zinc-700 dark:text-zinc-200">{{ $request['window'] }}</span>
                                    <span class="truncate italic text-zinc-400">“{{ \Illuminate\Support\Str::limit($request['reason'], 70) }}”</span>
                                </div>
                                @if($request['pending'] && auth()->user()->canApproveRegularisations())
                                    <button type="button" wire:click="openReviewModal({{ $request['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-orange-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-orange-600"><flux:icon.eye class="size-3" /> Review</button>
                                @elseif($request['pending'])
                                    <span class="text-[10px] font-bold text-amber-600">{{ $request['stage'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($h['events'] === [])
                    <p class="text-xs text-zinc-400">Nothing changed — the day stands as the device recorded it.</p>
                @else
                    <ol class="space-y-3" data-day-history-events>
                        @foreach($h['events'] as $event)
                            @php [$eventIcon, $eventChip] = $eventStyle($event['kind']); @endphp
                            <li class="flex items-start gap-3" data-history-event="{{ $event['kind'] }}">
                                <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg {{ $eventChip }}"><flux:icon :icon="$eventIcon" class="size-4" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-xs font-bold text-zinc-900 dark:text-white">{{ $event['title'] }}</span>
                                        @if($event['at'])<span class="text-[10px] tabular-nums text-zinc-400">{{ \Carbon\CarbonImmutable::parse($event['at'])->userDateTime() }}</span>@endif
                                    </div>
                                    @if($event['by'])<div class="text-[10px] text-zinc-500 dark:text-zinc-400">by {{ $event['by'] }}</div>@endif
                                    @if($event['detail'])<div class="mt-0.5 text-[11px] text-zinc-600 dark:text-zinc-300">{{ $event['detail'] }}</div>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            {{-- Raw device log (audit) --}}
            @if($h['raw'] !== [])
                <div class="{{ $card }}" x-data="{ open: false }">
                    <button type="button" @click="open = ! open" class="flex w-full items-center justify-between text-[10px] font-bold uppercase tracking-widest text-zinc-400 hover:text-orange-500">
                        <span>Raw device log · {{ count($h['raw']) }} {{ \Illuminate\Support\Str::plural('event', count($h['raw'])) }}</span>
                        <flux:icon.chevron-down class="size-4 transition" ::class="open ? 'rotate-180' : ''" />
                    </button>
                    <div x-show="open" x-collapse class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-[11px]" data-day-history-raw>
                            <thead class="text-[9px] font-bold uppercase tracking-wider text-zinc-400"><tr><th class="py-1 pr-3">Time</th><th class="py-1 pr-3">Read as</th><th class="py-1 pr-3">Method</th><th class="py-1 pr-3">Device</th><th class="py-1">Result</th></tr></thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach($h['raw'] as $raw)
                                    <tr @class(['opacity-60' => $raw['flag'] !== 'kept']) @if($raw['note']) title="{{ $raw['note'] }}" @endif>
                                        <td class="py-1.5 pr-3 font-mono font-bold tabular-nums text-zinc-800 dark:text-zinc-100">{{ $raw['time'] }}</td>
                                        <td class="py-1.5 pr-3 uppercase text-zinc-500">{{ $raw['direction'] ?? '—' }}@if(! empty($raw['direction_corrected'])) <span class="normal-case text-violet-600">(corrected)</span>@endif</td>
                                        <td class="py-1.5 pr-3 text-zinc-500">{{ $raw['method'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3 font-mono text-zinc-400">{{ $raw['device'] ?? '—' }}</td>
                                        <td class="py-1.5"><span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase {{ $raw['flag'] === 'kept' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' }}">{{ $raw['flag'] }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- Actions — kept clear of the site's floating assistant button (bottom right). --}}
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-orange-100 bg-white py-3 pl-4 pr-20 dark:border-white/10 dark:bg-zinc-900" data-day-history-actions>
            @if($canSeeLive)
                <a href="{{ route('attendance.live', ['tab' => 'employee', 'employee' => $person->id, 'from' => $hDay->subDays(29)->toDateString(), 'to' => $h['date']]) }}" class="mr-auto inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold text-zinc-500 transition hover:bg-orange-50 hover:text-orange-600 dark:text-zinc-400 dark:hover:bg-white/5"><flux:icon.signal class="size-4" /> Last 30 days</a>
            @endif
            <button type="button" @click="$wire.closeDayHistory(); $wire.openEmployeeDrawer({{ $person->id }})" class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-bold text-zinc-600 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5"><flux:icon.identification class="size-4" /> Employee 360°</button>
            <button type="button" @click="$wire.closeDayHistory(); $wire.set('filterEmployee', '{{ $person->id }}')" class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-bold text-zinc-600 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-white/5"><flux:icon.list-bullet class="size-4" /> Punch log</button>
            @if($canCorrect)
                <button type="button" wire:click="openMarkModal({{ $person->id }}, '{{ $h['date'] }}')" data-history-correct class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-orange-300/40 transition hover:shadow-lg dark:shadow-none"><flux:icon.pencil-square class="size-4" /> Correct this day</button>
            @endif
        </div>
    </aside>
</div>
