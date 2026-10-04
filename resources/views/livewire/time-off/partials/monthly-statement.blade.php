{{-- Month-wise statement rows from LeaveStatementService::monthly(). Shared by HR and My Time Off.
     opening + credits + carry forward + add-ons − used − encashed − expired ± other = closing. --}}
@php
    $credit = fn ($v) => (float) $v == 0.0 ? '0' : (($v > 0 ? '+' : '').$fmt($v));
    $debit = fn ($v) => (float) $v == 0.0 ? '0' : (($v > 0 ? '-' : '+').$fmt(abs($v)));
@endphp
<div class="overflow-x-auto">
    <table class="min-w-full text-xs">
        <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
            <tr>
                <th class="px-3 py-2">Month</th>
                <th class="px-3 py-2 text-right">Opening</th>
                <th class="px-3 py-2 text-right">Current-year credits</th>
                <th class="px-3 py-2 text-right">Carry forward</th>
                <th class="px-3 py-2 text-right">Comp Off / add-ons</th>
                <th class="px-3 py-2 text-right">Used</th>
                <th class="px-3 py-2 text-right">Encashed</th>
                <th class="px-3 py-2 text-right">Expired</th>
                <th class="px-3 py-2 text-right" title="HR adjustments, migrated opening balances, reversals, and usage the HR register proves only as a total">Other / reconciliation</th>
                <th class="px-3 py-2 text-right">Closing</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
            @forelse($months as $m)
                <tr>
                    <td class="px-3 py-2 font-semibold text-[#101828] dark:text-white">{{ $m['label'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($m['opening']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ $credit($m['current_credits']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ $credit($m['carry_forward']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-emerald-600">{{ $credit($m['add_ons']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ $debit($m['used']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-rose-600">{{ $debit($m['encashed']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-amber-600">{{ $debit($m['expired']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $credit($m['other']) }}</td>
                    <td @class(['px-3 py-2 text-right font-bold tabular-nums', 'text-rose-600' => $m['closing'] < 0, 'text-[#101828] dark:text-white' => $m['closing'] >= 0])>{{ $fmt($m['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No movements recorded for this leave year yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
