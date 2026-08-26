<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    @if(session('employee_created'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
             x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             class="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-green-800 shadow-sm">
            <svg class="mt-0.5 size-5 shrink-0 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                <p class="font-semibold text-sm">Employee Added Successfully</p>
                <p class="text-xs text-green-700 mt-0.5">{{ session('employee_created') }}</p>
            </div>
            <button @click="show = false" class="ml-auto text-green-400 hover:text-green-600">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @endif

    <div class="pulse-action-bar">
        <div>
            <h1 class="pulse-page-title">{{ $showDeleted ? 'Deleted Employees' : 'Employees' }}</h1>
            <p class="pulse-page-subtitle">
                {{ $showDeleted
                    ? 'Restore someone with their history, or remove them permanently.'
                    : "Manage your company's workforce" }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            {{-- Deleted employees were unreachable before this: soft-deleted,
                 still holding their email, with no way back. --}}
            <flux:button wire:click="$toggle('showDeleted')" variant="{{ $showDeleted ? 'primary' : 'ghost' }}"
                icon="{{ $showDeleted ? 'arrow-uturn-left' : 'archive-box' }}">
                {{ $showDeleted ? 'Back to active' : 'Deleted' }}
            </flux:button>
            @unless($showDeleted)
                <flux:button href="{{ route('employees.create') }}" wire:navigate variant="primary" icon="plus">
                    Add Employee
                </flux:button>
            @endunless
        </div>
    </div>

    <div class="pulse-card">
        <div class="pulse-toolbar">
            <div class="pulse-filters">
                {{-- Search --}}
                <div class="relative">
                    <svg class="pulse-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee" class="pulse-search" />
                </div>
                {{-- Filters --}}
                <x-clean-select model="office_id" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All Offices']], $offices->map(fn ($office) => ['value' => $office->id, 'label' => $office->name])->all())" />
                <x-clean-select model="department_id" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All Departments']], $departments->map(fn ($dept) => ['value' => $dept->id, 'label' => $dept->name])->all())" />
                <x-clean-select model="job_title_id" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All Job Titles']], $jobTitles->map(fn ($title) => ['value' => $title->id, 'label' => $title->name])->all())" />
                <x-clean-select model="status" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All Status']], collect(\App\Enums\EmployeeStatus::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all())" />
                {{-- After a bulk import this is the working list: who still cannot get in. --}}
                <x-clean-select model="invitation" :live="true"
                    :options="[
                        ['value' => '', 'label' => 'All Invitations'],
                        ['value' => 'not_invited', 'label' => 'Not Invited'],
                        ['value' => 'invited', 'label' => 'Invited'],
                        ['value' => 'accepted', 'label' => 'Accepted'],
                        ['value' => 'expired', 'label' => 'Expired'],
                        ['value' => 'active', 'label' => 'Active'],
                    ]" />
            </div>
        </div>

        <div class="pulse-table-wrap">
            <table class="pulse-table">
                <thead>
                    <tr>
                        <th class="pulse-th pl-6">Employee</th>
                        <th class="pulse-th pulse-col-sm">Emp ID</th>
                        <th class="pulse-th pulse-col-sm">Job Title</th>
                        <th class="pulse-th pulse-col-lg">Department</th>
                        <th class="pulse-th pulse-col-lg">Shift</th>
                        <th class="pulse-th">Status</th>
                        <th class="pulse-th">Login</th>
                        <th class="pulse-th pr-6 text-right!">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                        <tr class="group">
                            <td class="pulse-td pl-6">
                                <div class="flex items-center gap-3">
                                    {{-- Guarded like every other relation in this row. jobTitle, department
                                         and shift were already null-safe; user and status were not,
                                         purely because the active list never produces a row missing
                                         either. Defensive, not a diagnosis of the live 500. --}}
                                    @if($emp->photo)
                                        <img src="{{ asset('storage/'.$emp->photo) }}" class="size-8 rounded-full object-cover" />
                                    @else
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                                            {{ strtoupper(substr($emp->user?->name ?? '?', 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-semibold text-zinc-900 dark:text-white">
                                            {{ $emp->user?->name ?? 'No user account' }}
                                        </div>
                                        <div class="text-xs text-zinc-400">{{ $emp->user?->email ?? '—' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="pulse-td pulse-col-sm">
                                <span class="font-mono text-xs font-bold text-zinc-700 dark:text-zinc-300 bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded-md">
                                    {{ $emp->employee_id ?? '—' }}
                                </span>
                            </td>
                            <td class="pulse-td pulse-col-sm">{{ $emp->jobTitle?->name ?? '—' }}</td>
                            <td class="pulse-td pulse-col-lg">{{ $emp->department?->name ?? '—' }}</td>
                            <td class="pulse-td pulse-col-lg">
                                @if($emp->shift)
                                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $emp->shift->name }}</span>
                                @else
                                    <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                @endif
                            </td>
                            <td class="pulse-td">
                                @php
                                    $sc = match($emp->status?->value) {
                                        'active'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                        'probation' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                        'inactive'  => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                        default     => 'bg-zinc-100 text-zinc-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider {{ $sc }}">
                                    {{ $emp->status?->label() ?? '—' }}
                                </span>
                            </td>
                            {{-- Where this person stands on getting a login. Never shows the
                                 temporary password: it exists only in the email that was sent. --}}
                            <td class="pulse-td">
                                @php
                                    $inv = $emp->latestInvitation;
                                    $login = $emp->user?->last_login_at
                                        ? 'active'
                                        : ($inv?->status() ?? 'not_invited');
                                    [$loginLabel, $loginClass] = match($login) {
                                        'active'   => ['Active',      'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'],
                                        'accepted' => ['Accepted',    'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'],
                                        'invited'  => ['Invited',     'bg-sky-50 text-sky-700 dark:bg-sky-900/20 dark:text-sky-400'],
                                        'expired'  => ['Expired',     'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400'],
                                        default    => ['Not Invited', 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400'],
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider {{ $loginClass }}">
                                    {{ $loginLabel }}
                                </span>
                                @if($login === 'invited' && $inv)
                                    <div class="mt-0.5 text-[10px] text-zinc-400">Expires {{ $inv->expires_at->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td class="pulse-td pr-6 text-right!">
                                <div class="flex items-center justify-end gap-2">
                                    @if(auth()->user()->isSuperAdmin() && $emp->user_id)
                                        <flux:tooltip content="View as this employee">
                                            <flux:button
                                                href="{{ route('impersonate.start', $emp->user_id) }}"
                                                variant="ghost"
                                                size="sm"
                                                icon="eye"
                                                class="text-zinc-400 hover:text-amber-600"
                                            />
                                        </flux:tooltip>
                                    @endif

                                    {{-- Inviting is deliberately not part of import: an imported row
                                         may be half-finished or a duplicate, so somebody checks the
                                         record first. Resending revokes the previous link and
                                         password, which is why that branch asks. --}}
                                    @if(! $showDeleted && auth()->user()->can('invite', $emp) && $login !== 'active' && $login !== 'accepted')
                                        @if($login === 'invited')
                                            <flux:tooltip content="Invitation already active — resend">
                                                <flux:button
                                                    wire:click="inviteEmployee({{ $emp->id }})"
                                                    wire:confirm="This will invalidate the previous invitation and send a new temporary password. Continue?"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="envelope"
                                                    class="text-zinc-400 hover:bg-sky-50 hover:text-sky-600 dark:hover:bg-sky-500/10 dark:hover:text-sky-400"
                                                />
                                            </flux:tooltip>
                                        @else
                                            <flux:tooltip content="{{ $login === 'expired' ? 'Invitation expired — send a new one' : 'Send login invitation' }}">
                                                <flux:button
                                                    wire:click="inviteEmployee({{ $emp->id }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="envelope"
                                                    class="text-zinc-400 hover:bg-sky-50 hover:text-sky-600 dark:hover:bg-sky-500/10 dark:hover:text-sky-400"
                                                />
                                            </flux:tooltip>
                                        @endif
                                    @endif

                                    <flux:tooltip content="Open profile">
                                        <flux:button
                                            href="{{ route('employees.profile', $emp->id) }}"
                                            wire:navigate
                                            variant="ghost"
                                            size="sm"
                                            icon="identification"
                                            class="text-zinc-400 hover:text-orange-600 dark:hover:text-orange-400"
                                        />
                                    </flux:tooltip>

                                    <flux:tooltip content="Edit full record">
                                        <flux:button
                                            href="{{ route('employees.edit', $emp->id) }}"
                                            wire:navigate
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                        />
                                    </flux:tooltip>
                                    
                                    @if($showDeleted)
                                        <flux:tooltip content="Restore with leave, attendance and payroll history">
                                            <flux:button
                                                wire:click="restoreEmployee({{ $emp->id }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="arrow-uturn-left"
                                                class="text-zinc-400 hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-500/10 dark:hover:text-emerald-400"
                                            />
                                        </flux:tooltip>

                                        {{-- Irreversible, and it takes their history with it, so the
                                             confirmation spells out exactly what is lost. --}}
                                        <flux:tooltip content="Delete permanently — cannot be undone">
                                            <flux:button
                                                wire:click="forceDeleteEmployee({{ $emp->id }})"
                                                wire:confirm.prompt="Permanently delete this employee?

Their leave balances, attendance, payslips and audit history go with them. This cannot be undone.

Type DELETE FOREVER to confirm|DELETE FOREVER"
                                                variant="ghost"
                                                size="sm"
                                                icon="fire"
                                                class="text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                            />
                                        </flux:tooltip>
                                    @else
                                        <flux:button
                                            wire:click="deleteEmployee({{ $emp->id }})"
                                            wire:confirm.prompt="Are you sure you want to soft delete this employee?

Type DELETE to confirm|DELETE"
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                        />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="pulse-table__empty">{{ $showDeleted ? 'No deleted employees.' : 'No employees found.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pulse-pagination">
            {{ $employees->links() }}
        </div>
    </div>
</flux:main>
