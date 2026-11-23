<div class="ui-surface mb-4 p-5" data-history-sync @if($running) wire:poll.3s @endif>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-500 dark:bg-orange-500/10"><flux:icon.clock class="size-5" /></span>
            <div>
                <h3 class="text-sm font-black text-zinc-900 dark:text-white">Attendance history sync</h3>
                <p class="mt-0.5 max-w-2xl text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                    Replays the device logs kept by the attendance engine through the same day sync the live schedule runs every 10 minutes.
                    Days in a settled payroll and days HR corrected are never changed, and running a day again gives the same result.
                    <span class="font-semibold">Check logs</span> only reads: it shows which days have punches and which device PINs have no employee yet.
                </p>
            </div>
        </div>

        <form wire:submit="check" class="flex flex-wrap items-end gap-2">
            <div>
                <label for="history-from" class="block text-[10px] font-bold uppercase tracking-wide text-zinc-400">From</label>
                <input id="history-from" type="date" wire:model="from" max="{{ now()->toDateString() }}" @disabled($running)
                       class="mt-1 rounded-xl border border-orange-100 bg-white px-3 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
            </div>
            <div>
                <label for="history-to" class="block text-[10px] font-bold uppercase tracking-wide text-zinc-400">To</label>
                <input id="history-to" type="date" wire:model="to" max="{{ now()->toDateString() }}" @disabled($running)
                       class="mt-1 rounded-xl border border-orange-100 bg-white px-3 py-1.5 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
            </div>
            @if($running)
                <button type="button" wire:click="cancel" data-history-cancel
                        class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-600 shadow-sm transition hover:bg-rose-50 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon.stop class="size-4" /> Stop
                </button>
            @else
                <button type="submit" data-history-check
                        class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                    <flux:icon.magnifying-glass class="size-4 text-orange-500" /> Check logs
                </button>
                <button type="button" wire:click="sync" data-history-sync-start
                        wire:confirm="Sync attendance from the device logs for {{ $from }} to {{ $to }}? Locked payroll days and HR-corrected days stay as they are."
                        class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-orange-600">
                    <flux:icon.arrow-path class="size-4" /> Sync history
                </button>
            @endif
        </form>
    </div>

    @error('from') <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-600 dark:bg-rose-500/10">{{ $message }}</p> @enderror
    @error('to') <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-600 dark:bg-rose-500/10">{{ $message }}</p> @enderror

    @if($queueIsSync)
        <p class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-[11px] text-amber-700 dark:bg-amber-500/10">
            The background worker is not set up on this server (QUEUE_CONNECTION=sync). Run the history from the server's terminal instead:
            <code class="font-mono">php artisan attendance:history-coverage --from=YYYY-MM-DD</code> then
            <code class="font-mono">php artisan attendance:sync-engine --from=YYYY-MM-DD --to=YYYY-MM-DD</code>.
        </p>
    @endif

    @if($status)
        @php
            $pct = $status['days_total'] > 0 ? (int) floor($status['days_done'] / $status['days_total'] * 100) : 0;
            [$label, $tone] = match (true) {
                $stale => ['Stopped — no progress for '.\App\Services\Biometric\AttendanceHistorySync::STALE_MINUTES.' minutes (worker stopped?)', 'bg-rose-100 text-rose-600'],
                $status['status'] === 'queued' => ['Waiting for the background worker', 'bg-amber-100 text-amber-700'],
                $status['status'] === 'running' => ['Running', 'bg-orange-100 text-orange-600'],
                $status['status'] === 'cancelling' => ['Stopping after the current day', 'bg-amber-100 text-amber-700'],
                $status['status'] === 'cancelled' => ['Stopped', 'bg-zinc-100 text-zinc-600'],
                default => ['Finished', 'bg-emerald-100 text-emerald-700'],
            };
            $unmatched = collect($status['unmatched'])->sortKeys();
        @endphp

        <div class="mt-4 rounded-2xl border border-orange-100/70 p-4 dark:border-zinc-700" data-history-status="{{ $status['status'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-black text-zinc-900 dark:text-white">{{ $status['mode'] === 'sync' ? 'History sync' : 'Log check (read only)' }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\CarbonImmutable::parse($status['from'])->userDate() }} → {{ \Carbon\CarbonImmutable::parse($status['to'])->userDate() }}</span>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $tone }}">{{ $label }}</span>
                </div>
                <span class="text-[11px] text-zinc-400">
                    Started by {{ $status['started_by'] ?? '—' }}
                    @if($status['finished_at']) · finished {{ \Illuminate\Support\Carbon::parse($status['finished_at'])->diffForHumans() }} @endif
                </span>
            </div>

            @if($waitingForWorker)
                <p class="mt-2 text-[11px] text-amber-700">Nothing has picked this up yet — check the Queue Worker card above is Online.</p>
            @endif

            <div class="mt-3 h-2 overflow-hidden rounded-full bg-orange-50 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-orange-500 transition-all duration-500" style="width: {{ $pct }}%"></div>
            </div>
            <div class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                Day {{ $status['days_done'] }} of {{ $status['days_total'] }}@if($status['current']) · {{ \Carbon\CarbonImmutable::parse($status['current'])->userDate() }}@endif
            </div>

            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    ['Days with punches', $status['days_with_punches']],
                    ['First punch', $status['first_punch_date'] ? \Carbon\CarbonImmutable::parse($status['first_punch_date'])->userDate() : '—'],
                    ['Last punch', $status['last_punch_date'] ? \Carbon\CarbonImmutable::parse($status['last_punch_date'])->userDate() : '—'],
                    ['Employees matched', count($status['matched'])],
                    [$status['mode'] === 'sync' ? 'Employee-days synced' : 'PINs with no employee', $status['mode'] === 'sync' ? $status['synced'] : $unmatched->count()],
                    ['Days the engine could not answer', count($status['failed'])],
                ] as [$k, $v])
                    <div class="rounded-xl bg-orange-50/50 px-3 py-2 dark:bg-zinc-800/60">
                        <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $k }}</div>
                        <div class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div>
                    </div>
                @endforeach
            </div>

            @if($unmatched->isNotEmpty())
                <div class="mt-4" data-history-unmatched>
                    <p class="text-[11px] font-semibold text-amber-700">
                        {{ $unmatched->count() }} device {{ \Illuminate\Support\Str::plural('PIN', $unmatched->count()) }} have punches but no HRMS employee with that Employee Code.
                        Their attendance cannot sync until the PIN is set as the Employee Code on the right person's profile (Employees → Edit). Then run the sync again.
                    </p>
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-[10px] uppercase tracking-wide text-zinc-400">
                                <tr><th class="py-1.5 pr-3">PIN</th><th class="py-1.5 pr-3">Name in the device</th><th class="py-1.5 pr-3">Days with punches</th><th class="py-1.5 pr-3">First seen</th><th class="py-1.5">Last seen</th></tr>
                            </thead>
                            <tbody class="divide-y divide-orange-50 dark:divide-zinc-800">
                                @foreach ($unmatched as $pin => $u)
                                    <tr class="text-zinc-700 dark:text-zinc-300">
                                        <td class="py-1.5 pr-3 font-mono font-bold">{{ $pin }}</td>
                                        <td class="py-1.5 pr-3">{{ $u['name'] ?? '—' }}</td>
                                        <td class="py-1.5 pr-3 tabular-nums">{{ $u['days'] }}</td>
                                        <td class="py-1.5 pr-3">{{ \Carbon\CarbonImmutable::parse($u['first'])->userDate() }}</td>
                                        <td class="py-1.5">{{ \Carbon\CarbonImmutable::parse($u['last'])->userDate() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if($status['failed'] !== [])
                <details class="mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">
                    <summary class="cursor-pointer font-semibold text-rose-600">{{ count($status['failed']) }} day(s) the engine could not answer</summary>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($status['failed'] as $day => $error)
                            <li><span class="font-mono">{{ $day }}</span> — {{ $error }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endif
</div>
