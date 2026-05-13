<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- Breadcrumb --}}
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $employee->user->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">

        {{-- ─── LEFT: Profile Card ─── --}}
        <div class="space-y-4 lg:col-span-4 xl:col-span-3">
            <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                {{-- Avatar + name --}}
                <div class="bg-gradient-to-br from-brand-500 to-brand-700 px-6 pt-8 pb-12 text-center">
                    @if($employee->photo)
                        <img src="{{ asset('storage/'.$employee->photo) }}" class="mx-auto size-20 rounded-full border-4 border-white/30 shadow-lg object-cover" />
                    @else
                        <div class="mx-auto flex size-20 items-center justify-center rounded-full border-4 border-white/30 bg-white/20 text-2xl font-black text-white shadow-lg">
                            {{ strtoupper(substr($employee->user->name, 0, 1)) }}
                        </div>
                    @endif
                    <h2 class="mt-3 text-lg font-black text-white">{{ $employee->user->name }}</h2>
                    <p class="text-sm font-medium text-brand-100">{{ $employee->jobTitle?->name ?? 'Employee' }}</p>
                </div>

                {{-- Stats / quick info --}}
                <div class="-mt-6 mx-4 rounded-xl border border-zinc-100 bg-white px-4 py-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-800">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-black text-zinc-500 uppercase tracking-widest">Emp ID</span>
                        <span class="font-black text-zinc-900 dark:text-white font-mono">{{ $employee->employee_id ?? '—' }}</span>
                    </div>
                </div>

                <div class="space-y-3 px-5 py-4">
                    <div class="flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon.envelope class="size-4 shrink-0 text-zinc-400" />
                        <span class="truncate">{{ $employee->user->email }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon.phone class="size-4 shrink-0 text-zinc-400" />
                        <span>{{ $employee->phone ?: '—' }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon.clock class="size-4 shrink-0 text-zinc-400" />
                        <span>{{ $employee->shift?->name ?? 'No Shift Assigned' }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon.calendar-days class="size-4 shrink-0 text-zinc-400" />
                        <span>Joined {{ $employee->joining_date->format('d M Y') }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-zinc-600 dark:text-zinc-300">
                        <flux:icon.building-office class="size-4 shrink-0 text-zinc-400" />
                        <span>{{ $employee->department?->name ?? '—' }}, {{ $employee->office?->name ?? '—' }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-sm">
                        <flux:icon.user-circle class="size-4 shrink-0 text-zinc-400" />
                        @php
                            $badge = match($employee->status->value) {
                                'active'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                'probation' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                'inactive'  => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                default     => 'bg-zinc-100 text-zinc-600',
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-black uppercase tracking-wider {{ $badge }}">
                            {{ $employee->status->label() }}
                        </span>
                    </div>
                </div>

                <div class="border-t border-zinc-50 px-5 py-4 dark:border-zinc-800">
                    <flux:dropdown align="center" class="w-full">
                        <flux:button class="w-full" icon-trailing="chevron-down">Quick Actions</flux:button>
                        <flux:menu class="w-full">
                            <flux:menu.item wire:click="setTab('General')">Edit Account Info</flux:menu.item>
                            <flux:menu.item wire:click="setTab('Personal')">Edit Personal Info</flux:menu.item>
                            <flux:menu.item wire:click="setTab('Job')">Edit Job Info</flux:menu.item>
                            @if($employee->status->value === 'probation')
                                <flux:menu.separator />
                                <flux:menu.item wire:click="setTab('Probation')" class="text-amber-600">Manage Probation</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </div>

        {{-- ─── RIGHT: Tabbed Editor ─── --}}
        <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-8 xl:col-span-9">

            {{-- Tab bar --}}
            <div class="flex items-center gap-1 overflow-x-auto border-b border-zinc-100 px-6 pt-4 pb-0 dark:border-zinc-800">
                @foreach(['General', 'Personal', 'Job', 'Probation', 'Payroll', 'Nexflow'] as $tab)
                    <button type="button" wire:click="setTab('{{ $tab }}')"
                        class="whitespace-nowrap border-b-2 pb-3 px-3 text-sm font-bold transition-colors
                            {{ $activeTab === $tab
                                ? 'border-brand-500 text-brand-600 dark:text-brand-400'
                                : 'border-transparent text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                        @if($tab === 'Nexflow')
                            <span class="flex items-center gap-1.5">
                                <span class="flex size-1.5 rounded-full bg-indigo-400"></span>
                                Nexflow
                            </span>
                        @else
                            {{ $tab }}
                        @endif
                        @if($tab === 'Probation' && $employee->status->value === 'probation')
                            <span class="ml-1 inline-flex size-1.5 rounded-full bg-amber-500"></span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Tab content --}}
            <div class="p-8">
                <form wire:submit="save">

                    {{-- ── General Tab ── --}}
                    @if($activeTab === 'General')
                        <div class="space-y-5">
                            <h3 class="text-base font-black text-zinc-900 dark:text-white">Account Information</h3>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="name" label="Full Name" required />
                                <flux:input wire:model="email" type="email" label="Email Address" required />
                                <flux:select wire:model="role" label="System Role">
                                    @foreach($roles as $roleCase)
                                        <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model="employee_id" label="Employee ID" placeholder="CNX-0001" required />
                            </div>
                            <div class="flex justify-end pt-2">
                                <flux:button type="submit" variant="primary" icon="check">Save Account Info</flux:button>
                            </div>
                        </div>

                    {{-- ── Personal Tab ── --}}
                    @elseif($activeTab === 'Personal')
                        <div class="space-y-5">
                            <h3 class="text-base font-black text-zinc-900 dark:text-white">Personal Information</h3>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="phone" label="Phone Number" placeholder="+91 98765 43210" />
                                <flux:input wire:model="date_of_birth" type="date" label="Date of Birth" />
                                <flux:select wire:model="gender" label="Gender">
                                    <option value="">Select…</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                    <option value="prefer_not_to_say">Prefer not to say</option>
                                </flux:select>
                                <flux:input wire:model="emergency_contact" label="Emergency Contact" placeholder="Name & phone" />
                            </div>
                            <flux:textarea wire:model="address" label="Residential Address" rows="2" placeholder="Full residential address" />
                            <flux:field>
                                <flux:label>Profile Photo</flux:label>
                                @if($employee->photo)
                                    <div class="mb-2 flex items-center gap-3">
                                        <img src="{{ asset('storage/'.$employee->photo) }}" class="size-12 rounded-full object-cover border border-zinc-200" />
                                        <span class="text-xs text-zinc-400">Current photo — upload a new one to replace</span>
                                    </div>
                                @endif
                                <flux:input wire:model="photo" type="file" accept="image/*" class="mt-1" />
                                @error('photo') <flux:error>{{ $message }}</flux:error> @enderror
                            </flux:field>
                            <div class="flex justify-end pt-2">
                                <flux:button type="submit" variant="primary" icon="check">Save Personal Info</flux:button>
                            </div>
                        </div>

                    {{-- ── Job Tab ── --}}
                    @elseif($activeTab === 'Job')
                        <div class="space-y-5">
                            <h3 class="text-base font-black text-zinc-900 dark:text-white">Job Information</h3>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="joining_date" type="date" label="Joining Date" required />
                                <flux:input wire:model="probation_end_date" type="date" label="Probation End Date" />

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
                                    <option value="">Select Office…</option>
                                    @foreach($offices as $office)
                                        <option value="{{ $office->id }}">{{ $office->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="department_id" label="Department">
                                    <option value="">Select Department…</option>
                                    @foreach($departments as $dept)
                                        <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="job_title_id" label="Job Title">
                                    <option value="">Select Job Title…</option>
                                    @foreach($jobTitles as $title)
                                        <option value="{{ $title->id }}">{{ $title->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="manager_id" label="Line Manager">
                                    <option value="">Select Manager…</option>
                                    @foreach($managers as $mgr)
                                        <option value="{{ $mgr->id }}">{{ $mgr->name }} ({{ ucfirst($mgr->role->value) }})</option>
                                    @endforeach
                                </flux:select>

                                <flux:select wire:model="shift_id" label="Shift">
                                    <option value="">No Shift Assigned</option>
                                    @foreach($shifts as $shift)
                                        <option value="{{ $shift->id }}">
                                            {{ $shift->name }} — {{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A') }} to {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') }} (Grace: {{ $shift->grace_minutes }}m)
                                        </option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="salary_cycle" label="Salary Cycle">
                                    <option value="A">Cycle A — 1st to 31st</option>
                                    <option value="B">Cycle B — 21st to 20th</option>
                                </flux:select>
                            </div>
                            <div class="flex justify-end pt-2">
                                <flux:button type="submit" variant="primary" icon="check">Save Job Info</flux:button>
                            </div>
                        </div>

                    {{-- ── Probation Tab ── --}}
                    @elseif($activeTab === 'Probation')
                        <div class="space-y-6">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="text-base font-black text-zinc-900 dark:text-white">Probation Management</h3>
                                    <p class="mt-1 text-sm text-zinc-500">Review and action this employee's probation period.</p>
                                </div>
                                @if($employee->status->value === 'probation')
                                    <flux:badge color="amber">On Probation</flux:badge>
                                @else
                                    <flux:badge color="green">Permanent</flux:badge>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-4 rounded-xl border border-zinc-100 bg-zinc-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                                <div>
                                    <div class="mb-1 text-zinc-400">Joining Date</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->joining_date->format('d M Y') }}</div>
                                </div>
                                <div>
                                    <div class="mb-1 text-zinc-400">Probation End Date</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">
                                        {{ $employee->probation_end_date?->format('d M Y') ?? 'Not set' }}
                                    </div>
                                </div>
                                @if($employee->probation_extension_reason)
                                    <div class="col-span-2">
                                        <div class="mb-1 text-zinc-400">Extension Reason</div>
                                        <div class="text-zinc-700 dark:text-zinc-300">{{ $employee->probation_extension_reason }}</div>
                                    </div>
                                @endif
                            </div>

                            @if($employee->status->value === 'probation')
                                <div class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                                    <h4 class="font-bold text-emerald-800 dark:text-emerald-300">Confirm Probation</h4>
                                    <p class="text-sm text-emerald-700 dark:text-emerald-400">Mark as permanent. Status will change to <strong>Active</strong>.</p>
                                    <flux:button wire:click="confirmProbation"
                                        wire:confirm="Confirm probation? This will mark the employee as permanent."
                                        variant="primary" icon="check">
                                        Confirm &amp; Make Permanent
                                    </flux:button>
                                </div>

                                <div class="space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/40 dark:bg-amber-950/20">
                                    <h4 class="font-bold text-amber-800 dark:text-amber-300">Extend Probation</h4>
                                    <p class="text-sm text-amber-700 dark:text-amber-400">Set a new end date. Line manager will be notified.</p>
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <flux:input wire:model="extend_end_date" type="date" label="New Probation End Date" required />
                                    </div>
                                    <flux:textarea wire:model="extend_reason" label="Reason for Extension" rows="2" required placeholder="Describe the reason..." />
                                    <flux:button wire:click="extendProbation" icon="clock">Extend Probation</flux:button>
                                </div>
                            @else
                                <div class="flex flex-col items-center justify-center py-10 text-center">
                                    <div class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-emerald-50 dark:bg-emerald-900/20">
                                        <flux:icon.check-circle class="size-8 text-emerald-500" />
                                    </div>
                                    <h3 class="font-bold text-zinc-900 dark:text-white">Probation Completed</h3>
                                    <p class="mt-1 text-sm text-zinc-500">This employee has completed their probation period.</p>
                                </div>
                            @endif
                        </div>

                    {{-- ── Payroll Tab ── --}}
                    @elseif($activeTab === 'Payroll')
                        @php
                            $grossSalary     = $employee->salaries->where('component.type', 'earning')->sum('amount');
                            $totalDeductions = $employee->salaries->where('component.type', 'deduction')->sum('amount');
                            $netSalary       = $grossSalary - $totalDeductions;
                        @endphp
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-black text-zinc-900 dark:text-white">Payroll Summary</h3>
                                <span class="text-xs font-bold text-zinc-400 uppercase">Cycle {{ $employee->salary_cycle ?? 'A' }}</span>
                            </div>

                            <div class="grid grid-cols-2 gap-4 rounded-xl border border-zinc-100 bg-zinc-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900 lg:grid-cols-4">
                                <div>
                                    <div class="mb-1 text-zinc-400">Status</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->status->label() }}</div>
                                </div>
                                <div>
                                    <div class="mb-1 text-zinc-400">Job Title</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->jobTitle?->name ?? 'N/A' }}</div>
                                </div>
                                <div>
                                    <div class="mb-1 text-zinc-400">Employment</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->employment_type->label() }}</div>
                                </div>
                                <div>
                                    <div class="mb-1 text-zinc-400">Joining Date</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->joining_date->format('d M Y') }}</div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-zinc-100 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-800/50">
                                <div class="flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                                    <div>
                                        <div class="text-sm font-bold text-zinc-900 dark:text-white">Total Net Compensation</div>
                                        <div class="text-xs text-zinc-400">Based on assigned salary components</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-black text-brand-600">₹{{ number_format($netSalary, 2) }}</div>
                                        <div class="flex gap-3 text-xs">
                                            <span class="text-zinc-400">Gross: ₹{{ number_format($grossSalary, 2) }}</span>
                                            <span class="text-rose-500">Ded: -₹{{ number_format($totalDeductions, 2) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400">Earnings</h4>
                                @forelse($employee->salaries->where('component.type', 'earning') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <span class="text-sm font-black text-zinc-900 dark:text-white">₹{{ number_format($salary->amount, 2) }}</span>
                                    </div>
                                @empty
                                    <p class="text-sm text-zinc-400">No earning components assigned.</p>
                                @endforelse

                                <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mt-6">Deductions</h4>
                                @forelse($employee->salaries->where('component.type', 'deduction') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <span class="text-sm font-black text-rose-600">-₹{{ number_format($salary->amount, 2) }}</span>
                                    </div>
                                @empty
                                    <p class="text-sm text-zinc-400">No deduction components assigned.</p>
                                @endforelse

                                <div class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-800">
                                    <flux:button href="{{ route('payroll.components') }}" wire:navigate variant="ghost" icon="cog-6-tooth">
                                        Manage Salary Profile
                                    </flux:button>
                                </div>
                            </div>
                        </div>
                    @elseif($activeTab === 'Nexflow')
                        <livewire:employees.nexflow-activity :employee="$employee" :key="'nexflow-'.$employee->id" />
                    @endif

                </form>
            </div>
        </div>
    </div>
</flux:main>
