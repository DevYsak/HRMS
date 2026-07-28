<flux:main class="space-y-6 p-4 md:p-6">

    @php
        $selectClass = 'w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
        $mask = fn ($k, $v) => in_array($k, ['password', 'remember_token'], true) ? '••••••' : (is_scalar($v) ? (string) $v : json_encode($v));
        $actionTone = fn ($a) => match (true) {
            in_array($a, ['created', 'approved', 'unlocked'], true) => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
            in_array($a, ['updated', 'submitted_for_approval'], true) => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
            in_array($a, ['rejected', 'deleted'], true) => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
            $a === 'locked' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
            in_array($a, ['downloaded', 'emailed'], true) => 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400',
            default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
        };
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Payroll Audit Trail</flux:heading>
            <flux:subheading>Every payroll and payslip action — who, role, when, why, and what changed.</flux:subheading>
        </div>
        <flux:button wire:click="export" icon="arrow-down-tray" variant="ghost" size="sm">Export CSV</flux:button>
    </div>

    {{-- Filters --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by user or employee…" class="{{ $selectClass }}">
        </div>
        <x-clean-select model="action" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All actions']], collect($actions)->map(fn ($a) => ['value' => $a, 'label' => ucfirst(str_replace('_', ' ', $a))])->all())" />
        <input type="date" wire:model.live="from" class="{{ $selectClass }}" title="From">
        <input type="date" wire:model.live="to" class="{{ $selectClass }}" title="To">
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50/70 dark:border-white/5 dark:bg-zinc-800/40">
                <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                    <th class="px-5 py-3 text-left">When</th>
                    <th class="px-3 py-3 text-left">User</th>
                    <th class="px-3 py-3 text-left">Role</th>
                    <th class="px-3 py-3 text-left">Employee</th>
                    <th class="px-3 py-3 text-left">Action</th>
                    <th class="px-3 py-3 text-left">Record</th>
                    <th class="px-3 py-3 text-left">Reason</th>
                    <th class="px-5 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($logs as $log)
                    <tr class="transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                        <td class="px-5 py-3 whitespace-nowrap text-zinc-500">{{ $log->created_at?->format('d M Y, H:i') }}</td>
                        <td class="px-3 py-3 font-medium text-zinc-800 dark:text-zinc-200">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-3 py-3 text-xs text-zinc-500">{{ $log->role ?? '—' }}</td>
                        <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ $log->subjectEmployee?->user?->name ?? '—' }}</td>
                        <td class="px-3 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold {{ $actionTone($log->action) }}">
                                {{ ucfirst(str_replace('_', ' ', $log->action)) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ class_basename($log->auditable_type) }} <span class="text-zinc-400">#{{ $log->auditable_id }}</span></td>
                        <td class="px-3 py-3 max-w-56 truncate text-xs text-zinc-500" title="{{ $log->reason }}">{{ $log->reason ?? '—' }}</td>
                        <td class="px-5 py-3 text-right">
                            @if($log->old_values || $log->new_values)
                                <button wire:click="toggle({{ $log->id }})" class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                    {{ $expandedId === $log->id ? 'Hide' : 'Details' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if($expandedId === $log->id)
                        <tr class="bg-zinc-50/50 dark:bg-zinc-800/20">
                            <td colspan="8" class="px-5 py-4">
                                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                    <div>
                                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-400">Field changes</div>
                                        @php
                                            $keys = array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? [])));
                                            $keys = array_values(array_diff($keys, ['updated_at', 'created_at']));
                                        @endphp
                                        @if(empty($keys))
                                            <p class="text-xs text-zinc-400">No field-level detail recorded.</p>
                                        @else
                                            <div class="overflow-x-auto">
                                                <table class="w-full text-xs">
                                                    <thead>
                                                        <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                                                            <th class="py-1 pr-4 text-left">Field</th>
                                                            <th class="py-1 pr-4 text-left">Old</th>
                                                            <th class="py-1 text-left">New</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($keys as $key)
                                                            <tr class="border-t border-zinc-100 dark:border-white/5">
                                                                <td class="py-1 pr-4 font-semibold text-zinc-600 dark:text-zinc-300">{{ $key }}</td>
                                                                <td class="py-1 pr-4 text-rose-500">{{ $mask($key, ($log->old_values ?? [])[$key] ?? '—') }}</td>
                                                                <td class="py-1 text-emerald-600 dark:text-emerald-400">{{ $mask($key, ($log->new_values ?? [])[$key] ?? '—') }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="mb-2 text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                                            Full history — {{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}
                                        </div>
                                        <x-timeline :steps="$this->timelineStepsFor($log)" />
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-zinc-400">No payroll audit entries match your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>

</flux:main>
