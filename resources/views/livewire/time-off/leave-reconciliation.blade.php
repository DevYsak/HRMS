<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">
    @php $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('time-off.leave-management') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:underline">← Leave Management</a>
            <h1 class="mt-1 text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Leave Reconciliation</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                {{ $this->year->label }} ({{ $this->year->starts_on->format('d M Y') }} – {{ $this->year->ends_on->format('d M Y') }}): every eligible employee against their policy.
                The scan is a dry run — nothing changes until you reconcile, and only SAFE AUTO FIX rows are ever fixed automatically.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="magnifying-glass" wire:click="scan">{{ $scanned ? 'Re-scan' : 'Run dry-run scan' }}</flux:button>
            @if($scanned)
                @can('export_leave')<flux:button icon="arrow-down-tray" wire:click="export">Export</flux:button>@endcan
                <flux:button wire:click="reconcileSelected" :disabled="count($selected) === 0">Reconcile selected ({{ count($selected) }})</flux:button>
                <flux:button variant="primary" wire:click="reconcileAllSafe"
                    wire:confirm="Apply every SAFE AUTO FIX ({{ $counts['SAFE_AUTO_FIX'] ?? 0 }} row(s))? Review and blocked rows are left untouched.">Reconcile all safe issues</flux:button>
            @endif
        </div>
    </div>

    @if(session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>@endif

    <div class="grid grid-cols-1 gap-3 rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm md:grid-cols-4 dark:border-white/10 dark:bg-zinc-900">
        <x-clean-select model="leaveYearId" label="Leave year" :options="$leaveYears->map(fn ($y) => ['value' => $y->id, 'label' => $y->label])->all()" />
        <x-clean-select model="leaveTypeId" label="Leave type" :options="array_merge([['value' => '', 'label' => 'All types']], $leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
        <x-clean-select model="departmentId" label="Department" :options="array_merge([['value' => '', 'label' => 'All departments']], $departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
        <x-clean-select model="classification" label="Show" :options="[
            ['value' => '', 'label' => 'All issues'], ['value' => 'SAFE_AUTO_FIX', 'label' => 'Safe auto fix'],
            ['value' => 'NEEDS_HR_REVIEW', 'label' => 'Needs HR review'], ['value' => 'BLOCKED', 'label' => 'Blocked'], ['value' => 'OK', 'label' => 'OK']]" />
    </div>

    @if($scanned)
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach(['SAFE_AUTO_FIX' => ['Safe auto fix', 'text-emerald-600'], 'NEEDS_HR_REVIEW' => ['Needs HR review', 'text-amber-600'], 'BLOCKED' => ['Blocked', 'text-rose-600'], 'OK' => ['OK', 'text-zinc-500']] as $key => [$label, $color])
                <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-widest {{ $color }}">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-extrabold text-[#101828] dark:text-white">{{ $counts[$key] ?? 0 }}</div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <table class="min-w-full text-xs">
                <thead class="bg-[#F9FAFB] text-left text-[10px] font-bold uppercase tracking-wider text-[#667085] dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2"></th><th class="px-3 py-2">Employee</th><th class="px-3 py-2">Policy</th><th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2 text-right">Expected</th><th class="px-3 py-2 text-right">Actual base</th><th class="px-3 py-2 text-right">CF</th>
                        <th class="px-3 py-2 text-right">Add-On</th><th class="px-3 py-2 text-right">Accrual</th><th class="px-3 py-2 text-right">Used</th>
                        <th class="px-3 py-2 text-right">Pending</th><th class="px-3 py-2 text-right">Expired</th><th class="px-3 py-2 text-right">Available</th>
                        <th class="px-3 py-2">Class</th><th class="px-3 py-2">Issues / action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#EAECF0] dark:divide-white/5">
                    @forelse($visible as $r)
                        <tr wire:key="rc-{{ $r['key'] }}">
                            <td class="px-3 py-2">
                                @if($r['classification'] === 'SAFE_AUTO_FIX')<input type="checkbox" wire:model.live="selected" value="{{ $r['key'] }}" class="rounded">@endif
                            </td>
                            <td class="px-3 py-2"><div class="font-semibold">{{ $r['employee'] }}</div><div class="text-[11px] text-[#98A2B3]">{{ $r['employee_code'] }}</div></td>
                            <td class="px-3 py-2">{{ $r['policy'] ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $r['leave_type'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['expected_base']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['actual_base']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['carry_forward']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['add_on']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['accrual']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['used']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['pending']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r['expired']) }}</td>
                            <td class="px-3 py-2 text-right font-bold">{{ $fmt($r['available']) }}</td>
                            <td class="px-3 py-2"><flux:badge size="sm" :color="['SAFE_AUTO_FIX' => 'green', 'NEEDS_HR_REVIEW' => 'amber', 'BLOCKED' => 'red', 'OK' => 'zinc'][$r['classification']]">{{ str_replace('_', ' ', $r['classification']) }}</flux:badge></td>
                            <td class="max-w-sm px-3 py-2">
                                <div class="text-[#101828] dark:text-zinc-200">{{ implode(' ', $r['issues']) ?: '—' }}</div>
                                <div class="text-[11px] text-[#667085]">→ {{ $r['recommended_action'] }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="15" class="px-3 py-10 text-center text-sm text-[#98A2B3]">No issues found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="rounded-2xl border border-dashed border-[#D0D5DD] bg-white p-10 text-center text-sm text-[#667085] dark:border-white/10 dark:bg-zinc-900">
            Run the dry-run scan to check {{ $this->year->label }}. It reads every eligible employee and writes nothing.
        </div>
    @endif
</flux:main>
