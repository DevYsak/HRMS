@props(['payroll'])

{{-- PayrollSummary — rendered only for an account with view_payslips (the
     service passes null otherwise and the dashboard leaves this card out).
     Net pay is masked until asked for: dashboards get screen-shared. --}}
<x-employee.dashboard.card title="Payroll" icon="banknotes" :href="route('payroll.payslips')" cta="All payslips" {{ $attributes }}>
    @if(! $payroll['payslip'])
        <x-employee.dashboard.empty-state icon="banknotes" title="No payslip generated yet."
            text="Your payslip appears here once payroll for the month has been processed." />
    @else
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Latest payslip</p>
                <p class="mt-0.5 text-base font-semibold text-zinc-900 dark:text-white">{{ $payroll['period'] }}</p>
            </div>
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300' => $payroll['is_paid'],
                'bg-amber-50 text-amber-700 ring-amber-600/15 dark:bg-amber-500/10 dark:text-amber-300' => ! $payroll['is_paid'],
            ])>
                <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $payroll['is_paid'], 'bg-amber-500' => ! $payroll['is_paid']]) aria-hidden="true"></span>
                {{ $payroll['status_label'] }}
            </span>
        </div>

        <div class="mt-4 rounded-lg bg-zinc-50 p-3 dark:bg-white/[0.03]" x-data="{ shown: false }">
            <div class="flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Net pay</p>
                <button type="button" x-on:click="shown = ! shown"
                        class="inline-flex items-center gap-1 rounded text-xs font-medium text-zinc-500 transition hover:text-zinc-800 focus-visible:outline-2 focus-visible:outline-orange-500 dark:text-zinc-400 dark:hover:text-white"
                        x-bind:aria-pressed="shown.toString()">
                    <flux:icon.eye x-show="! shown" class="size-3.5" />
                    <flux:icon.eye-slash x-show="shown" x-cloak class="size-3.5" />
                    <span x-text="shown ? 'Hide' : 'Show'">Show</span>
                </button>
            </div>
            <p class="mt-1 text-2xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-white">
                <span x-show="shown" x-cloak>₹{{ number_format($payroll['net'], 2) }}</span>
                <span x-show="! shown" aria-label="Hidden">₹ • • • • • •</span>
            </p>
            @unless($payroll['is_paid'])
                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Provisional until payroll is approved.</p>
            @endunless
        </div>

        @if($payroll['download_url'])
            <div class="pt-4">
                <a href="{{ $payroll['download_url'] }}" target="_blank" rel="noopener"
                   class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10">
                    <flux:icon.arrow-down-tray class="size-4" /> Download payslip
                </a>
            </div>
        @endif
    @endif
</x-employee.dashboard.card>
