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
            <h1 class="pulse-page-title">Employees</h1>
            <p class="pulse-page-subtitle">Manage your company's workforce</p>
        </div>
        <flux:button href="{{ route('employees.create') }}" wire:navigate variant="primary" icon="plus">
            Add Employee
        </flux:button>
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
                <select wire:model.live="office_id" class="pulse-select">
                    <option value="">All Offices</option>
                    @foreach($offices as $office)
                        <option value="{{ $office->id }}">{{ $office->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="department_id" class="pulse-select">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="job_title_id" class="pulse-select">
                    <option value="">All Job Titles</option>
                    @foreach($jobTitles as $title)
                        <option value="{{ $title->id }}">{{ $title->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status" class="pulse-select">
                    <option value="">All Status</option>
                    @foreach(\App\Enums\EmployeeStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="pulse-table-wrap">
            <table class="pulse-table">
                <thead>
                    <tr>
                        <th class="pulse-th pl-6">Employee</th>
                        <th class="pulse-th">Emp ID</th>
                        <th class="pulse-th">Job Title</th>
                        <th class="pulse-th">Department</th>
                        <th class="pulse-th">Shift</th>
                        <th class="pulse-th">Status</th>
                        <th class="pulse-th pr-6 text-right!">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $emp)
                        <tr class="group">
                            <td class="pulse-td pl-6">
                                <div class="flex items-center gap-3">
                                    @if($emp->photo)
                                        <img src="{{ asset('storage/'.$emp->photo) }}" class="size-8 rounded-full object-cover" />
                                    @else
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                                            {{ strtoupper(substr($emp->user->name, 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-semibold text-zinc-900 dark:text-white">{{ $emp->user->name }}</div>
                                        <div class="text-xs text-zinc-400">{{ $emp->user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="pulse-td">
                                <span class="font-mono text-xs font-bold text-zinc-700 dark:text-zinc-300 bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded-md">
                                    {{ $emp->employee_id ?? '—' }}
                                </span>
                            </td>
                            <td class="pulse-td">{{ $emp->jobTitle?->name ?? '—' }}</td>
                            <td class="pulse-td">{{ $emp->department?->name ?? '—' }}</td>
                            <td class="pulse-td">
                                @if($emp->shift)
                                    <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $emp->shift->name }}</span>
                                @else
                                    <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                @endif
                            </td>
                            <td class="pulse-td">
                                @php
                                    $sc = match($emp->status->value) {
                                        'active'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                        'probation' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                        'inactive'  => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                        default     => 'bg-zinc-100 text-zinc-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider {{ $sc }}">
                                    {{ $emp->status->label() }}
                                </span>
                            </td>
                            <td class="pulse-td pr-6 text-right!">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button 
                                        href="{{ route('employees.edit', $emp->id) }}" 
                                        wire:navigate 
                                        variant="ghost" 
                                        size="sm" 
                                        icon="pencil-square" 
                                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                                    />
                                    
                                    <flux:button 
                                        wire:click="deleteEmployee({{ $emp->id }})" 
                                        wire:confirm.prompt="Are you sure you want to soft delete this employee?\n\nType DELETE to confirm|DELETE"
                                        variant="ghost" 
                                        size="sm" 
                                        icon="trash" 
                                        class="text-zinc-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                    />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="pulse-table__empty">No employees found.</td>
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
