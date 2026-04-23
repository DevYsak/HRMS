<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Detail Employee</flux:breadcrumbs.item>
            </flux:breadcrumbs>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {{-- Left Pane: Profile Card --}}
        <div class="lg:col-span-4 xl:col-span-3 space-y-4">
            <div class="pulse-card flex flex-col items-center p-8 text-center gap-2">
                @if($employee->user->avatar)
                    <img src="{{ $employee->user->avatarUrl() }}" class="size-24 rounded-full shadow-sm mb-2" />
                @else
                    <div class="flex size-24 shrink-0 items-center justify-center rounded-full bg-brand-100 text-3xl font-bold text-brand-600 mb-2 dark:bg-brand-900/40 dark:text-brand-400">
                        {{ strtoupper(substr($employee->user->name, 0, 1)) }}
                    </div>
                @endif
                
                <div>
                    <h2 class="text-xl font-bold text-zinc-900 dark:text-white">{{ $employee->user->name }}</h2>
                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $employee->jobTitle?->name ?? 'Employee' }}</p>
                </div>
                
                <div class="mt-2 text-xs font-bold px-3 py-1 bg-brand-50 text-brand-700 rounded-full dark:bg-brand-500/10 dark:text-brand-400 flex items-center justify-between min-w-[100px]">
                    <span class="mx-auto uppercase">{{ $employee->status->label() }}</span>
                    <flux:icon.chevron-down class="size-3" />
                </div>
                
                <hr class="w-full border-zinc-100 dark:border-zinc-800 my-4">
                
                <div class="w-full flex flex-col gap-3 text-sm text-left">
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-300">
                        <flux:icon.envelope class="size-4 text-zinc-400" />
                        <span class="truncate">{{ $employee->user->email }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-300">
                        <flux:icon.phone class="size-4 text-zinc-400" />
                        <span class="truncate">+000 000 000000</span>
                    </div>
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-300">
                        <flux:icon.globe-alt class="size-4 text-zinc-400" />
                        <span class="truncate">GMT +05:30</span>
                    </div>
                </div>

                <hr class="w-full border-zinc-100 dark:border-zinc-800 my-4">

                <div class="w-full flex flex-col gap-4 text-sm text-left">
                    <div>
                        <div class="text-xs text-zinc-400 mb-1">Department</div>
                        <div class="font-medium text-zinc-900 dark:text-white flex justify-between items-center">
                            {{ $employee->department?->name ?? 'None' }}
                            <flux:icon.chevron-right class="size-3 text-zinc-400" />
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400 mb-1">Office</div>
                        <div class="font-medium text-zinc-900 dark:text-white flex justify-between items-center">
                            {{ $employee->office?->name ?? 'None' }}
                            <flux:icon.chevron-right class="size-3 text-zinc-400" />
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400 mb-1">Line Manager</div>
                        <div class="font-medium text-zinc-900 dark:text-white flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                @if($employee->manager)
                                    <div class="flex size-5 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-[10px] font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ strtoupper(substr($employee->manager->name, 0, 1)) }}
                                    </div>
                                    {{ $employee->manager->name }}
                                @else
                                    None
                                @endif
                            </div>
                            <flux:icon.chevron-right class="size-3 text-zinc-400" />
                        </div>
                    </div>
                </div>

                <div class="w-full mt-6">
                    <flux:dropdown align="center" class="w-full">
                        <flux:button class="w-full bg-[#151B27] hover:bg-[#1f2838] text-white border-0 py-2 dark:bg-zinc-800 dark:hover:bg-zinc-700" icon-trailing="chevron-down">Action</flux:button>
                        <flux:menu class="w-full">
                            <flux:menu.item wire:click="setTab('General')">Edit Details</flux:menu.item>
                            <flux:menu.item variant="danger">Deactivate Employee</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </div>

        {{-- Right Pane: Tabs & Content --}}
        <div class="lg:col-span-8 xl:col-span-9 pulse-card p-0 overflow-hidden shadow-sm">
            {{-- Tabs --}}
            <div class="flex items-center gap-8 px-8 pt-4 border-b border-zinc-100 dark:border-zinc-800 text-sm font-semibold overflow-x-auto whitespace-nowrap">
                @foreach(['General', 'Job', 'Payroll', 'Documents', 'Setting'] as $tab)
                    <button 
                        type="button" 
                        wire:click="setTab('{{ $tab }}')" 
                        class="pb-3 border-b-2 transition-colors {{ $activeTab === $tab ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}"
                    >
                        {{ $tab }}
                    </button>
                @endforeach
            </div>

            {{-- Content Area --}}
            <div class="p-8">
                <form wire:submit="save">
                    @if($activeTab === 'General')
                        <div class="space-y-6">
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">General Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <flux:input wire:model="name" label="Full Name" required />
                                <flux:input wire:model="email" type="email" label="Email Address" required />
                                <flux:select wire:model="role" label="System Role">
                                    @foreach($roles as $roleCase)
                                        <option value="{{ $roleCase->value }}">{{ ucfirst($roleCase->value) }}</option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="flex justify-end pt-4">
                                <flux:button type="submit" variant="primary">Save General Info</flux:button>
                            </div>
                        </div>

                    @elseif($activeTab === 'Job')
                        <div class="space-y-6">
                            <h3 class="text-base font-bold text-zinc-900 dark:text-white">Job Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <flux:input wire:model="employee_id" label="Employee ID" required />
                                <flux:input wire:model="joining_date" type="date" label="Joining Date" required />
                                
                                <flux:select wire:model="status" label="Current Status">
                                    @foreach($statuses as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="employment_type" label="Employment Type">
                                    @foreach($employmentTypes as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="office_id" label="Office">
                                    <option value="">Select Office...</option>
                                    @foreach($offices as $office)
                                        <option value="{{ $office->id }}">{{ $office->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="department_id" label="Department">
                                    <option value="">Select Department...</option>
                                    @foreach($departments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="job_title_id" label="Job Title">
                                    <option value="">Select Job Title...</option>
                                    @foreach($jobTitles as $title)
                                        <option value="{{ $title->id }}">{{ $title->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="manager_id" label="Line Manager">
                                    <option value="">Select Manager...</option>
                                    @foreach($managers as $mgr)
                                        <option value="{{ $mgr->id }}">{{ $mgr->name }} ({{ ucfirst($mgr->role->value) }})</option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <div class="flex justify-end pt-4">
                                <flux:button type="submit" variant="primary">Save Job Info</flux:button>
                            </div>
                        </div>

                    @elseif($activeTab === 'Payroll')
                        {{-- Dynamic implementation for Payroll --}}
                        @php
                            $grossSalary = $employee->salaries->where('component.type', 'earning')->sum('amount');
                            $totalDeductions = $employee->salaries->where('component.type', 'deduction')->sum('amount');
                            $netSalary = $grossSalary - $totalDeductions;
                        @endphp
                        <div class="space-y-8 animate-fade-in">
                            <!-- Basic HR Info Row Grid -->
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-y-6 gap-x-8 text-sm">
                                <div>
                                    <div class="text-zinc-400 mb-1">Employee Status</div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $employee->status->label() }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-400 mb-1">Job Title</div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $employee->jobTitle?->name ?? 'N/A' }}</div>
                                </div>
                                <div class="col-start-1">
                                    <div class="text-zinc-400 mb-1">Employment Type</div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ $employee->employment_type->label() }}</div>
                                </div>
                                <div>
                                    <div class="text-zinc-400 mb-1">Job Date</div>
                                    <div class="font-medium text-zinc-900 dark:text-white">{{ optional($employee->joining_date)->format('d M Y') ?? 'N/A' }}</div>
                                </div>
                            </div>

                            <!-- Total Compensation Box -->
                            <div class="bg-zinc-50 rounded-2xl p-6 flex flex-col md:flex-row items-center justify-between border border-zinc-100 gap-4 dark:bg-zinc-900 dark:border-zinc-800">
                                <div>
                                    <span class="text-base font-semibold text-zinc-900 block dark:text-white">Total Net Compensation</span>
                                    <span class="text-xs text-zinc-500">Based on assigned salary components</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-2xl font-black text-brand-600">${{ number_format($netSalary, 2) }}</span>
                                    <div class="flex gap-4 mt-1 text-xs">
                                        <span class="text-zinc-500">Gross: ${{ number_format($grossSalary, 2) }}</span>
                                        <span class="text-red-500">Ded: -${{ number_format($totalDeductions, 2) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Details breakdown list -->
                            <div class="space-y-6">
                                <div>
                                    <h4 class="text-sm font-bold text-zinc-400 uppercase tracking-widest mb-3">Earnings</h4>
                                    <div class="space-y-3">
                                        @forelse($employee->salaries->where('component.type', 'earning') as $salary)
                                            <div class="bg-white rounded-xl border border-zinc-100 p-5 flex items-center justify-between dark:bg-zinc-900 dark:border-zinc-800">
                                                <span class="font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                                <span class="font-bold text-zinc-900 dark:text-white">${{ number_format($salary->amount, 2) }}</span>
                                            </div>
                                        @empty
                                            <div class="text-zinc-500 text-sm">No earning components assigned.</div>
                                        @endforelse
                                    </div>
                                </div>

                                <div>
                                    <h4 class="text-sm font-bold text-zinc-400 uppercase tracking-widest mb-3">Deductions</h4>
                                    <div class="space-y-3">
                                        @forelse($employee->salaries->where('component.type', 'deduction') as $salary)
                                            <div class="bg-white rounded-xl border border-zinc-100 p-5 flex items-center justify-between dark:bg-zinc-900 dark:border-zinc-800">
                                                <span class="font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                                <span class="font-bold text-red-600">-${{ number_format($salary->amount, 2) }}</span>
                                            </div>
                                        @empty
                                            <div class="text-zinc-500 text-sm">No deduction components assigned.</div>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                                    <flux:button href="{{ route('payroll.components') }}" wire:navigate variant="ghost" icon="cog-6-tooth">Manage Salary Profile</flux:button>
                                </div>
                            </div>
                        </div>

                    @else
                        <div class="flex flex-col items-center justify-center py-20 text-center">
                            <div class="p-4 rounded-full bg-zinc-50 mb-3 dark:bg-zinc-800/50">
                                <flux:icon.clock class="size-8 text-zinc-400" />
                            </div>
                            <h3 class="text-base font-medium text-zinc-900 dark:text-white">Coming Soon</h3>
                            <p class="text-sm border-0 border-transparent text-zinc-500 mt-1 max-w-sm">
                                The {{ $activeTab }} module is scheduled for a future development phase.
                            </p>
                        </div>
                    @endif
                </form>
            </div>
        </div>
    </div>
</flux:main>
