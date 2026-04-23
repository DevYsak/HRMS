<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Employees</h1>
            <p class="pulse-page-subtitle">Manage your company's workforce</p>
        </div>
        <flux:button href="{{ route('employees.create') }}" wire:navigate variant="primary" icon="plus">
            Add Employee
        </flux:button>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
            <div class="flex items-center gap-2">
                {{-- Search --}}
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee" class="h-8 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm text-zinc-800 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
                </div>
                {{-- Filters --}}
                <select wire:model.live="office_id" class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <option value="">All Offices</option>
                    @foreach($offices as $office)
                        <option value="{{ $office->id }}">{{ $office->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="job_title_id" class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <option value="">All Job Titles</option>
                    @foreach($jobTitles as $title)
                        <option value="{{ $title->id }}">{{ $title->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status" class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <option value="">All Status</option>
                    @foreach(\App\Enums\EmployeeStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee Name</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Job Title</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Line Manager</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Department</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($employees as $emp)
                        <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="py-3.5 pl-6 pr-4">
                                <div class="flex items-center gap-3">
                                    @if($emp->user->avatar)
                                        <img src="{{ $emp->user->avatarUrl() }}" class="size-8 rounded-full" />
                                    @else
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                                            {{ strtoupper(substr($emp->user->name, 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $emp->user->name }}</div>
                                        <div class="text-xs text-zinc-400">{{ $emp->user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-300">{{ $emp->jobTitle?->name ?? 'N/A' }}</td>
                            <td class="py-3.5 pr-4 font-medium text-brand-700 dark:text-brand-400">{{ $emp->manager?->name ?? 'None' }}</td>
                            <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-300">{{ $emp->department?->name ?? 'N/A' }}</td>
                            <td class="py-3.5 pr-4">
                                <span class="badge-{{ $emp->status->value }}">{{ strtoupper($emp->status->label()) }}</span>
                            </td>
                            <td class="py-3.5 pr-6 text-right">
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
                            <td colspan="6" class="py-8 text-center text-zinc-500">No employees found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
            {{ $employees->links() }}
        </div>
    </div>
</flux:main>
