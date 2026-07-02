<flux:main class="space-y-6 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Data Management</flux:heading>
        <flux:subheading>Permanently clear operational data or remove a single employee. Super Admin only.</flux:subheading>
    </div>

    {{-- Danger banner --}}
    <div class="flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-900/40 dark:bg-rose-950/20">
        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-rose-500" />
        <div class="text-sm text-rose-700 dark:text-rose-300">
            <b>These actions are permanent and cannot be undone.</b> Take a database backup first. Config (leave types, shifts, roles, salary structures, cycles) is never touched — only records.
        </div>
    </div>

    {{-- Bulk clear by domain --}}
    <div>
        <h3 class="mb-3 text-xs font-bold uppercase tracking-widest text-zinc-500">Bulk clear by type</h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($domains as $key => $d)
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $d['label'] }}</div>
                            <div class="mt-0.5 text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ number_format($d['count']) }}<span class="ml-1 text-xs font-medium text-zinc-400">rows</span></div>
                        </div>
                    </div>
                    <flux:button
                        wire:click="purge('{{ $key }}')"
                        wire:confirm.prompt="Permanently delete ALL {{ $d['label'] }} ({{ number_format($d['count']) }} rows)?\n\nThis cannot be undone. Type DELETE to confirm.|DELETE"
                        variant="danger" size="sm" icon="trash" class="mt-3 w-full"
                        :disabled="$d['count'] === 0">
                        Clear all
                    </flux:button>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Delete one employee --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <h3 class="mb-1 text-sm font-bold text-zinc-900 dark:text-white">Delete a single employee (everywhere)</h3>
        <p class="mb-3 text-xs text-zinc-400">Removes the employee, their user account, and all their attendance, leave, overtime, payroll, documents and notifications. Super Admins and your own account are protected.</p>

        <flux:input wire:model.live.debounce.300ms="employeeSearch" icon="magnifying-glass" placeholder="Search by name or email…" size="sm" class="max-w-md" />

        @if($employeeSearch !== '')
            <div class="mt-3 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                @forelse($employees as $emp)
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $emp->user?->name ?? 'Employee #'.$emp->id }}</div>
                            <div class="truncate text-xs text-zinc-400">{{ $emp->user?->email }} · {{ $emp->employee_id }}</div>
                        </div>
                        <flux:button
                            wire:click="deleteEmployee({{ $emp->id }})"
                            wire:confirm.prompt="Permanently delete {{ $emp->user?->name ?? 'this employee' }} and ALL their data?\n\nThis cannot be undone. Type DELETE to confirm.|DELETE"
                            variant="danger" size="xs" icon="trash">Delete</flux:button>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center text-sm text-zinc-400">No employees match.</div>
                @endforelse
            </div>
        @endif
    </div>

</flux:main>
