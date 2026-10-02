<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">
    @php $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('time-off.leave-management') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline">← Leave Management</a>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Year Rollover</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                {{ $this->fromYear->label }} → {{ $this->toYear->label }}. Closing balance, carry forward (capped), expiry of the rest, and the new year's base entitlement — kept separate.
                Runs automatically on 1 July; safe to re-run. Ambiguous rows are never guessed.
            </p>
        </div>
        <div class="flex flex-wrap items-end gap-2">
            <div class="w-40">
                <x-clean-select model="fromYearId" label="Closing year" :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
            </div>
            @can('export_leave')<flux:button icon="arrow-down-tray" wire:click="export">Export</flux:button>@endcan
            <flux:button variant="primary" icon="play" wire:click="processSafe"
                wire:confirm="Process {{ $counts['SAFE'] }} SAFE row(s) for {{ $this->fromYear->label }} → {{ $this->toYear->label }}?

Carry forward is posted to {{ $this->toYear->label }}, the rest of {{ $this->fromYear->label }} expires, and every eligible employee gets the new base entitlement. Rows needing review are left for HR."
                :disabled="$counts['SAFE'] === 0 && $counts['PROCESSED'] > 0">Confirm — process safe rows</flux:button>
        </div>
    </div>

    @if(session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>@endif

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach(['SAFE' => ['Ready (safe)', 'text-emerald-600'], 'NEEDS_HR_REVIEW' => ['Needs HR Review', 'text-amber-600'], 'BLOCKED' => ['Failed / blocked', 'text-rose-600'], 'PROCESSED' => ['Processed', 'text-zinc-500']] as $key => [$label, $color])
            <button type="button" wire:click="$set('statusFilter', '{{ $statusFilter === $key ? '' : $key }}')"
                class="rounded-2xl border bg-white p-4 text-left shadow-sm dark:bg-zinc-900 {{ $statusFilter === $key ? 'border-orange-400 ring-2 ring-orange-200' : 'border-[#EAECF0] dark:border-white/10' }}">
                <div class="text-[10px] font-bold uppercase tracking-widest {{ $color }}">{{ $label }}</div>
                <div class="mt-1 text-2xl font-extrabold text-[#101828] dark:text-white">{{ $counts[$key] }}</div>
            </button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <table class="min-w-full text-xs">
            <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2">Employee</th><th class="px-3 py-2">Type</th>
                    <th class="px-3 py-2 text-right">Closing</th><th class="px-3 py-2 text-right">Carry</th><th class="px-3 py-2 text-right">Expire</th>
                    <th class="px-3 py-2 text-right">New Base</th><th class="px-3 py-2 text-right">New Opening</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Why</th><th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                @forelse($visible as $r)
                    <tr wire:key="ro-{{ $r['balance_id'] }}">
                        <td class="px-3 py-2"><div class="font-semibold">{{ $r['employee'] }}</div><div class="text-[11px] text-[#98A2B3]">{{ $r['employee_code'] }} · {{ $r['department'] }}</div></td>
                        <td class="px-3 py-2">{{ $r['leave_type'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($r['closing']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($r['carry']) }}@if($r['expires_on'])<div class="text-[10px] text-amber-600">exp. {{ \Illuminate\Support\Carbon::parse($r['expires_on'])->format('d M Y') }}</div>@endif</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($r['expire']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $fmt($r['new_base']) }}</td>
                        <td class="px-3 py-2 text-right font-bold">{{ $fmt($r['new_opening']) }}</td>
                        <td class="px-3 py-2">
                            <flux:badge size="sm" :color="['SAFE' => 'green', 'NEEDS_HR_REVIEW' => 'amber', 'BLOCKED' => 'red', 'PROCESSED' => 'zinc'][$r['status']] ?? 'zinc'">{{ str_replace('_', ' ', $r['status']) }}</flux:badge>
                        </td>
                        <td class="max-w-xs px-3 py-2 text-[#667085]">{{ $r['reason'] }}</td>
                        <td class="px-3 py-2 text-right">
                            @if($r['status'] === 'NEEDS_HR_REVIEW')
                                <flux:button size="xs" wire:click="startReview({{ $r['balance_id'] }}, {{ (float) $r['carry'] }})">Review</flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-10 text-center text-sm text-[#98A2B3]">No balances in {{ $this->fromYear->label }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($runs->isNotEmpty())
        <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 text-xs shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-2 font-bold text-[#101828] dark:text-white">Rollover runs (HR report)</div>
            @foreach($runs as $run)
                <div class="flex flex-wrap gap-3 border-t border-[#EAECF0] py-1.5 dark:border-white/5">
                    <span class="font-semibold">#{{ $run->id }}</span>
                    <span>{{ $run->created_at->format('d M Y H:i') }}</span>
                    <span>{{ $run->creator?->name ?? 'Scheduler' }}</span>
                    <span>{{ str_replace('_', ' ', $run->status) }}</span>
                    <span class="text-[#667085]">{{ collect($run->summary ?? [])->except('errors')->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.$v)->implode(' · ') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @if($reviewBalanceId)
        <x-leave.overlay max="max-w-md">
            <form wire:submit="resolveReview" class="space-y-4">
                <flux:heading size="lg">Resolve rollover row</flux:heading>
                <flux:text>State the days to carry into {{ $this->toYear->label }}. The rest of the closing balance expires; the new base entitlement is provisioned separately.</flux:text>
                <flux:input type="number" step="0.5" min="0" wire:model="reviewCarry" label="Carry forward days" />
                @error('reviewCarry')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <flux:textarea wire:model="reviewReason" rows="2" label="Reason (required)" />
                @error('reviewReason')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('reviewBalanceId', null)">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Carry forward and roll over</flux:button>
                </div>
            </form>
        </x-leave.overlay>
    @endif
</flux:main>
