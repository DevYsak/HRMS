{{-- Super Admin additions to the HR overview: payroll this month, people by
     department, and security / audit activity. Company-wide by definition. --}}
@php $money = fn ($n) => '₹'.number_format((float) $n, 0); @endphp
<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
    <x-pulse.card :title="'Payroll — '.now()->format('F Y')" icon="banknotes" flush>
        @if(! $payrollEnabled)
            <p class="px-5 pb-5 text-sm text-zinc-500">The payroll module is switched off.</p>
        @elseif($payrollRuns->isEmpty())
            <p class="px-5 pb-5 text-sm text-zinc-500">No payroll run yet this month.</p>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($payrollRuns as $run)
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span>{{ $run->cycle === 'cycle_b' ? 'Cycle B' : 'Cycle A' }} · <span class="text-zinc-500">{{ ucfirst(str_replace('_', ' ', $run->status)) }}</span></span>
                        <span class="font-semibold tabular-nums">{{ $money($run->total_payout) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-pulse.card>

    <x-pulse.card title="People by department" icon="building-office" flush>
        <ul class="max-h-64 divide-y divide-zinc-100 overflow-y-auto dark:divide-white/5">
            @forelse($departments as $department)
                <li class="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                    <span class="truncate">{{ $department->name }}</span>
                    <span class="tabular-nums text-zinc-500">{{ $department->working_count }}</span>
                </li>
            @empty
                <li class="px-5 py-3 text-sm text-zinc-500">No departments yet.</li>
            @endforelse
        </ul>
    </x-pulse.card>

    <x-pulse.card title="Security — last 7 days" icon="shield-exclamation" flush>
        @include('dashboard.partials.count-list', ['rows' => [
            ['label' => 'Refused approvals / out-of-scope attempts', 'count' => $security['denied'], 'href' => $auditUrl, 'tone' => 'rose'],
            ['label' => 'Role & permission changes', 'count' => $security['permission_changes'], 'href' => $auditUrl],
            ['label' => 'Sign-ins as another user', 'count' => $security['impersonations'], 'href' => $auditUrl],
        ], 'empty' => 'No security events this week.'])
    </x-pulse.card>
</div>
