{{-- Month-wise statement rows from LeaveStatementService::monthly(). Shared by HR and My Time Off. --}}
<div class="overflow-x-auto">
    <table class="min-w-full text-xs">
        <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
            <tr>
                <th class="px-3 py-2">Month</th>
                <th class="px-3 py-2 text-right">Opening</th>
                <th class="px-3 py-2 text-right">Credits</th>
                <th class="px-3 py-2 text-right">Used</th>
                <th class="px-3 py-2 text-right">Expired</th>
                <th class="px-3 py-2 text-right">Other</th>
                <th class="px-3 py-2 text-right">Closing</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
            @forelse($months as $m)
                <tr>
                    <td class="px-3 py-2 font-semibold text-[#101828] dark:text-white">{{ $m['label'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmt($m['opening']) }}</td>
                    <td class="px-3 py-2 text-right text-emerald-600">
                        {{ $m['credits'] > 0 ? '+'.$fmt($m['credits']) : '0' }}
                        @if(count($m['credit_lines']) > 0)
                            <div class="text-[10px] text-[#98A2B3]">
                                @foreach($m['credit_lines'] as $label => $d){{ $label }} {{ $d > 0 ? '+' : '' }}{{ $fmt($d) }}@if(! $loop->last), @endif @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right text-rose-600">{{ $m['used'] != 0 ? '-'.$fmt($m['used']) : '0' }}</td>
                    <td class="px-3 py-2 text-right text-amber-600">{{ $m['expired'] != 0 ? '-'.$fmt($m['expired']) : '0' }}</td>
                    <td class="px-3 py-2 text-right">{{ $m['other_debits'] != 0 ? '-'.$fmt($m['other_debits']) : '0' }}</td>
                    <td class="px-3 py-2 text-right font-bold text-[#101828] dark:text-white">{{ $fmt($m['closing']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-sm text-[#98A2B3]">No movements recorded for this leave year yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
