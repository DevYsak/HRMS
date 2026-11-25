<div wire:poll.120s data-missed-punch-popup>
    @if($state)
        @php
            $over = $state['status'] === \App\Services\Attendance\MissedPunchTracker::STATUS_EXCEEDED;
            $atWarning = $state['status'] !== \App\Services\Attendance\MissedPunchTracker::STATUS_OK;
            $first = $state['missed'][0] ?? null;
        @endphp
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-zinc-900/40 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="missed-punch-title" data-missed-punch-dialog>
            <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-red-200 dark:bg-zinc-900 dark:ring-red-500/30">
                <div class="flex items-start gap-3 bg-red-50 px-5 py-4 dark:bg-red-500/10">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300"><flux:icon.exclamation-triangle class="size-5" /></span>
                    <div class="min-w-0">
                        <h2 id="missed-punch-title" class="text-base font-bold text-red-800 dark:text-red-200">
                            @if($over && $state['warn'])
                                Missed-punch allowance used up
                            @elseif($state['missed'] !== [])
                                You missed {{ count($state['missed']) === 1 ? 'a punch' : count($state['missed']).' punches' }}
                            @else
                                Missed punches this month
                            @endif
                        </h2>
                        <p class="mt-0.5 text-xs text-red-700/80 dark:text-red-200/80">Punch Face IN when you arrive or come back, and ID Card OUT every time you leave.</p>
                    </div>
                </div>

                <div class="max-h-72 overflow-y-auto px-5 py-4">
                    @if($state['missed'] !== [])
                        <ul class="space-y-2.5" data-missed-punch-list>
                            @foreach($state['missed'] as $missed)
                                <li class="rounded-xl border border-red-100 px-3 py-2 dark:border-red-500/20" wire:key="missed-{{ $missed['key'] }}">
                                    <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ \Carbon\CarbonImmutable::parse($missed['date'])->userDate() }}</div>
                                    <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $missed['label'] }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <p @class(['text-sm font-semibold', 'mt-3' => $state['missed'] !== [],
                        'text-red-700 dark:text-red-300' => $atWarning,
                        'text-zinc-600 dark:text-slate-300' => ! $atWarning]) data-missed-punch-month="{{ $state['month_count'] }}">
                        {{ $state['month_count'] }} of {{ $state['allowance'] }} missed punches allowed this month ({{ $state['month_label'] }}, counted Mon–Fri).
                        @if($over)
                            You are over the allowance and flagged for HR review — please contact HR about your attendance.
                        @elseif($atWarning)
                            One more than {{ $state['allowance'] }} and HR reviews your attendance.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-white/10">
                    <button type="button" wire:click="dismiss" data-missed-punch-dismiss
                            class="inline-flex items-center rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        Got it
                    </button>
                    @if($first)
                        <button type="button" wire:click="regularise('{{ $first['date'] }}')" data-missed-punch-regularise
                                class="inline-flex items-center rounded-xl bg-orange-500 px-3.5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-600">
                            Request regularisation
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
