<flux:main class="space-y-6 p-4 md:p-6">

    @php
        $selectClass = 'w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
        $render = fn ($v) => is_scalar($v) ? (string) $v : ($v === null ? '—' : json_encode($v, JSON_UNESCAPED_SLASHES));
        $viewer = \App\Livewire\AuditLogViewer::class;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Activity Log</flux:heading>
            <flux:subheading>The Audit Log of every administrative and security action — who, their role, what changed (before → after), why, and from where. Read-only: entries can never be edited or deleted.</flux:subheading>
        </div>
        <div class="flex gap-2">
            <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">Clear filters</flux:button>
            <flux:button wire:click="export" icon="arrow-down-tray" variant="ghost" size="sm">Export CSV</flux:button>
        </div>
    </div>

    {{-- Filters: date, user, role, module, action, event, employee --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
        <div class="sm:col-span-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by user…" class="{{ $selectClass }}">
        </div>
        <input type="date" wire:model.live="from" class="{{ $selectClass }}" title="From" aria-label="From date">
        <input type="date" wire:model.live="to" class="{{ $selectClass }}" title="To" aria-label="To date">
        <x-clean-select model="role" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All roles']], collect($roles)->map(fn ($r) => ['value' => $r, 'label' => $r])->all())" />
        <x-clean-select model="module" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All modules']], collect($modules)->map(fn ($m) => ['value' => $m, 'label' => \Illuminate\Support\Str::headline($m)])->all())" />
        <x-clean-select model="event" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All events']], collect($events)->map(fn ($e) => ['value' => $e, 'label' => \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Str::lower(str_replace('_', ' ', $e)))])->all())" />
        <x-clean-select model="action" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All actions']], collect($actions)->map(fn ($a) => ['value' => $a, 'label' => ucfirst($a)])->all())" />
        <x-clean-select model="model" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All record types']], collect($modelTypes)->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])->all())" />
        <x-clean-select model="employeeId" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All employees']], $employees->map(fn ($e) => ['value' => $e->id, 'label' => $e->user?->name ?? $e->employee_id])->all())" />
        <x-clean-select model="performedBy" :live="true"
            :options="array_merge([['value' => '', 'label' => 'Performed by anyone']], $actors->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->all())" />
    </div>

    {{-- Leave filters. A generic action/model pair cannot answer "show me
         every carry forward", because every one of those actions is recorded
         as "created". --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <x-clean-select model="category" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All leave categories']], collect($categories)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all())" />
        <x-clean-select model="leaveTypeId" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All leave types']], $leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
    </div>

    {{-- Table: scrolls sideways on small screens instead of overflowing the page --}}
    <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <table class="w-full min-w-[960px] text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50/70 dark:border-white/5 dark:bg-zinc-800/40">
                <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                    <th class="px-5 py-3 text-left">When</th>
                    <th class="px-3 py-3 text-left">Actor</th>
                    <th class="px-3 py-3 text-left">Action</th>
                    <th class="px-3 py-3 text-left">Module</th>
                    <th class="px-3 py-3 text-left">Affected</th>
                    <th class="px-3 py-3 text-left">Reason</th>
                    <th class="px-3 py-3 text-left">IP / device</th>
                    <th class="px-5 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($logs as $log)
                    @php
                        $leaveLabel = $categoriser->labelFor($log);
                        $leaveSummary = $leaveLabel ? $categoriser->summarise($log) : null;
                    @endphp
                    <tr wire:key="audit-{{ $log->id }}" class="align-top transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                        <td class="whitespace-nowrap px-5 py-3 text-zinc-500">
                            {{ $log->created_at?->format('d M Y, H:i') }}
                            @if($log->request_id)
                                <div class="mt-0.5 font-mono text-[10px] text-zinc-400" title="Reference ID {{ $log->request_id }}">Ref {{ \Illuminate\Support\Str::limit($log->request_id, 8, '') }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $log->user?->name ?? 'System' }}</div>
                            @if($log->role)
                                <div class="text-[11px] text-zinc-400">{{ $log->role }}</div>
                            @endif
                            @if($log->impersonator)
                                <div class="text-[11px] font-semibold text-amber-600">via {{ $log->impersonator->name }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            @if($leaveLabel)
                                <span class="inline-flex rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">{{ $leaveLabel }}</span>
                                @if($leaveSummary)
                                    <div class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $leaveSummary }}</div>
                                @endif
                            @else
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $log->action === 'created',
                                    'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' => $log->action === 'updated',
                                    'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' => $log->action === 'deleted' || str_contains((string) $log->event, 'FAILED'),
                                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' => ! in_array($log->action, ['created', 'updated', 'deleted'], true),
                                ])>{{ $viewer::eventLabel($log) }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-xs text-zinc-500">{{ \Illuminate\Support\Str::headline((string) ($log->module ?? $log->category ?? '—')) }}</td>
                        <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">
                            @if($log->subjectEmployee)
                                <div class="font-medium">{{ $log->subjectEmployee->user?->name ?? $log->subjectEmployee->employee_id }}</div>
                            @endif
                            <div class="text-xs text-zinc-400">{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</div>
                        </td>
                        <td class="max-w-[220px] px-3 py-3 text-xs text-zinc-500" title="{{ $log->reason }}">{{ $log->reason ? \Illuminate\Support\Str::limit($log->reason, 80) : '—' }}</td>
                        <td class="px-3 py-3 text-xs text-zinc-400">
                            <div>{{ $log->ip_address ?? '—' }}</div>
                            @if($device = $viewer::device($log->user_agent))
                                <div class="text-[11px]">{{ $device }}</div>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            @if($log->old_values || $log->new_values)
                                <button wire:click="toggle({{ $log->id }})" class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                    {{ $expandedId === $log->id ? 'Hide' : 'Details' }}
                                </button>
                            @endif
                        </td>
                    </tr>
                    @if($expandedId === $log->id)
                        <tr wire:key="audit-detail-{{ $log->id }}" class="bg-zinc-50/50 dark:bg-zinc-800/20">
                            <td colspan="8" class="px-5 py-4">
                                @php $keys = $viewer::diffKeys($log); @endphp
                                @if(empty($keys))
                                    <p class="text-xs text-zinc-400">No field changed.</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                                                    <th class="py-1 pr-4 text-left">Field</th>
                                                    <th class="py-1 pr-4 text-left">Before</th>
                                                    <th class="py-1 text-left">After</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($keys as $key)
                                                    <tr class="border-t border-zinc-100 dark:border-white/5">
                                                        <td class="py-1 pr-4 font-semibold text-zinc-600 dark:text-zinc-300">{{ $key }}</td>
                                                        <td class="break-all py-1 pr-4 text-rose-500">{{ $render(($log->old_values ?? [])[$key] ?? null) }}</td>
                                                        <td class="break-all py-1 text-emerald-600 dark:text-emerald-400">{{ $render(($log->new_values ?? [])[$key] ?? null) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @if($log->user_agent)
                                    <p class="mt-2 break-all text-[11px] text-zinc-400">Device: {{ $log->user_agent }}</p>
                                @endif
                                @if($log->request_id)
                                    <p class="text-[11px] text-zinc-400">Reference ID: <span class="font-mono">{{ $log->request_id }}</span></p>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-zinc-400">No audit entries match your filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>

</flux:main>
