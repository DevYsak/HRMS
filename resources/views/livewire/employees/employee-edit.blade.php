<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- Page Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $employee->user->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center gap-2">
            <flux:button
                wire:click="resetPassword"
                wire:loading.attr="disabled"
                wire:target="resetPassword"
                variant="outline" icon="lock-closed" size="sm"
            >
                <span wire:loading.remove wire:target="resetPassword">Reset Password</span>
                <span wire:loading wire:target="resetPassword">Sending…</span>
            </flux:button>
            <flux:button wire:click="openEmailModal" variant="outline" icon="envelope" size="sm">Send Email</flux:button>
            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                <flux:button @click="open = !open" variant="outline" size="sm" icon="ellipsis-vertical" />
                <div x-show="open" x-transition class="absolute right-0 top-full z-50 mt-1 w-44 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800" style="display:none">
                    <button type="button" wire:click="setTab('Activity')" @click="open = false" class="w-full px-4 py-2.5 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-700/60">View Activity</button>
                    <div class="border-t border-zinc-100 dark:border-zinc-700"></div>
                    @if($employee->status->value !== 'inactive')
                        <button
                            type="button"
                            wire:click="deactivate"
                            wire:confirm="Deactivate this employee? Their status will be set to Inactive."
                            @click="open = false"
                            class="w-full px-4 py-2.5 text-left text-sm font-medium text-red-500 hover:bg-zinc-50 dark:hover:bg-zinc-700/60"
                        >Deactivate</button>
                    @else
                        <span class="block w-full px-4 py-2.5 text-left text-sm font-medium text-zinc-300 dark:text-zinc-600 cursor-default">Already Inactive</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    @if($localResetUrl)
        <div class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-200">
            <div class="flex items-start gap-2">
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-semibold">Mail is configured for local logging, not delivery.</p>
                    <p class="mt-1 text-amber-800/90 dark:text-amber-200/90">Use this reset link for now, or switch `MAIL_MAILER` to a real SMTP service to send the email inbox-to-inbox.</p>
                </div>
            </div>

            <div class="rounded-lg border border-amber-200/80 bg-white/80 p-3 font-mono text-xs break-all dark:border-amber-800 dark:bg-black/10">
                {{ $localResetUrl }}
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ $localResetUrl }}" class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                    Open Reset Link
                </a>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">

        {{-- ─── LEFT: Profile Card ─── --}}
        <div class="space-y-4 lg:col-span-4 xl:col-span-3">
            <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                {{-- Avatar + name --}}
                <div class="bg-gradient-to-br from-indigo-500 to-[#f97316] px-6 pt-8 pb-12 text-center">
                    @if($employee->photo)
                        <div class="relative mx-auto inline-block">
                            <img src="{{ asset('storage/'.$employee->photo) }}" class="size-20 rounded-full border-4 border-white/30 object-cover shadow-lg" />
                            <span class="absolute bottom-0.5 right-0.5 size-4 rounded-full border-2 border-white bg-emerald-400 shadow-sm"></span>
                        </div>
                    @else
                        <div class="relative mx-auto inline-block">
                            <div class="flex size-20 items-center justify-center rounded-full border-4 border-white/30 bg-white/20 text-2xl font-black text-white shadow-lg">
                                {{ strtoupper(substr($employee->user->name, 0, 1)) }}
                            </div>
                            <span class="absolute bottom-0.5 right-0.5 size-4 rounded-full border-2 border-white bg-emerald-400 shadow-sm"></span>
                        </div>
                    @endif
                    <h2 class="mt-3 text-lg font-black text-white">{{ $employee->user->name }}</h2>
                    <p class="text-sm font-medium text-indigo-200">{{ $employee->jobTitle?->name ?? 'Employee' }}</p>
                </div>

                {{-- EMP ID --}}
                <div class="-mt-6 mx-4 rounded-xl border border-zinc-100 bg-white px-4 py-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-800">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-black uppercase tracking-widest text-zinc-500">Emp ID</span>
                        <span class="font-mono font-black text-zinc-900 dark:text-white">{{ $employee->employee_id ?? '—' }}</span>
                    </div>
                </div>

                {{-- Info rows --}}
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
                        @php
                            $dotColor = match($employee->status->value) {
                                'active'    => 'bg-emerald-400',
                                'probation' => 'bg-amber-400',
                                default     => 'bg-zinc-400',
                            };
                            $statusColor = match($employee->status->value) {
                                'active'    => 'text-emerald-600 dark:text-emerald-400',
                                'probation' => 'text-amber-600 dark:text-amber-400',
                                default     => 'text-zinc-500 dark:text-zinc-400',
                            };
                        @endphp
                        <span class="size-2.5 shrink-0 rounded-full {{ $dotColor }}"></span>
                        <span class="font-semibold {{ $statusColor }}">{{ $employee->status->label() }}</span>
                    </div>
                </div>

                {{-- Employee Actions --}}
                <div class="border-t border-zinc-100 px-5 pb-5 pt-4 dark:border-zinc-800">
                    <h3 class="mb-3 text-xs font-bold uppercase tracking-widest text-zinc-400">Employee Actions</h3>
                    <div class="grid grid-cols-4 gap-2">
                        <a href="{{ route('payroll.payslips') }}" wire:navigate
                            class="flex flex-col items-center gap-1.5 rounded-xl bg-zinc-50 p-3 text-center transition-colors hover:bg-indigo-50 dark:bg-zinc-800 dark:hover:bg-indigo-900/20">
                            <flux:icon.document-text class="size-5 text-indigo-500" />
                            <span class="text-[10px] font-semibold leading-tight text-zinc-500 dark:text-zinc-400">Payslip</span>
                        </a>
                        <a href="{{ route('attendance.employees') }}" wire:navigate
                            class="flex flex-col items-center gap-1.5 rounded-xl bg-zinc-50 p-3 text-center transition-colors hover:bg-indigo-50 dark:bg-zinc-800 dark:hover:bg-indigo-900/20">
                            <flux:icon.calendar-days class="size-5 text-indigo-500" />
                            <span class="text-[10px] font-semibold leading-tight text-zinc-500 dark:text-zinc-400">Attendance</span>
                        </a>
                        <button type="button" wire:click="setTab('Documents')"
                            class="flex flex-col items-center gap-1.5 rounded-xl bg-zinc-50 p-3 text-center transition-colors hover:bg-indigo-50 dark:bg-zinc-800 dark:hover:bg-indigo-900/20">
                            <flux:icon.folder class="size-5 text-indigo-500" />
                            <span class="text-[10px] font-semibold leading-tight text-zinc-500 dark:text-zinc-400">Documents</span>
                        </button>
                        <button type="button" wire:click="setTab('Activity')"
                            class="flex flex-col items-center gap-1.5 rounded-xl bg-zinc-50 p-3 text-center transition-colors hover:bg-indigo-50 dark:bg-zinc-800 dark:hover:bg-indigo-900/20">
                            <flux:icon.squares-2x2 class="size-5 text-indigo-500" />
                            <span class="text-[10px] font-semibold leading-tight text-zinc-500 dark:text-zinc-400">More</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ─── RIGHT: Tabbed Editor ─── --}}
        <div class="flex flex-col overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 lg:col-span-8 xl:col-span-9">

            {{-- Tab bar --}}
            <div class="flex items-center gap-1 overflow-x-auto border-b border-zinc-100 px-6 pt-4 pb-0 dark:border-zinc-800">
                @foreach(array_merge(['General', 'Personal', 'Job', 'Leave', 'Probation', 'Payroll', 'Documents', 'Activity'], in_array($employee->ot_tracking_source, ['nexflow', 'hybrid']) ? ['Nexflow'] : []) as $tab)
                    <button type="button" wire:click="setTab('{{ $tab }}')"
                        class="whitespace-nowrap border-b-2 pb-3 px-3 text-sm font-semibold transition-colors
                            {{ $activeTab === $tab
                                ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
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
            <div class="flex-1 p-8">
                <form wire:submit="save">

                    {{-- ── General Tab ── --}}
                    @if($activeTab === 'General')
                        <div wire:key="tab-general" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Account Information</h3>
                                <p class="mt-0.5 text-sm text-zinc-500">Update and manage employee account details</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="name" label="Full Name" icon="user" required />
                                <flux:input wire:model="email" type="email" label="Email Address" icon="envelope" required />
                                <flux:select wire:model="role" label="System Role">
                                    @foreach($roles as $roleCase)
                                        <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model="employee_id" label="Employee ID" icon="identification" placeholder="CNX-0001" required />
                            </div>
                            <div class="flex items-center gap-2 rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-700 dark:bg-indigo-950/30 dark:text-indigo-300">
                                <flux:icon.information-circle class="size-4 shrink-0" />
                                These details are used for system access and employee identification.
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                                <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                                    <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                    Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check">Save Changes</flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Personal Tab ── --}}
                    @elseif($activeTab === 'Personal')
                        <div wire:key="tab-personal" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Personal Information</h3>
                                <p class="mt-0.5 text-sm text-zinc-500">Manage personal and contact details</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="phone" label="Phone Number" icon="phone" placeholder="+91 98765 43210" />
                                <flux:input wire:model="date_of_birth" type="date" label="Date of Birth" />
                                <flux:select wire:model="gender" label="Gender">
                                    <option value="">Select…</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                    <option value="prefer_not_to_say">Prefer not to say</option>
                                </flux:select>
                                <flux:input wire:model="emergency_contact" label="Emergency Contact" icon="phone-arrow-up-right" placeholder="Name & phone" />
                            </div>
                            <flux:textarea wire:model="address" label="Residential Address" rows="2" placeholder="Full residential address" />
                            <flux:field>
                                <flux:label>Profile Photo</flux:label>
                                @if($employee->photo)
                                    <div class="mb-2 flex items-center gap-3">
                                        <img src="{{ asset('storage/'.$employee->photo) }}" class="size-12 rounded-full border border-zinc-200 object-cover" />
                                        <span class="text-xs text-zinc-400">Current photo — upload a new one to replace</span>
                                    </div>
                                @endif
                                <flux:input wire:model="photo" type="file" accept="image/*" class="mt-1" />
                                @error('photo') <flux:error>{{ $message }}</flux:error> @enderror
                            </flux:field>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                                <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                                    <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                    Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check">Save Changes</flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Job Tab ── --}}
                    @elseif($activeTab === 'Job')
                        <div wire:key="tab-job" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Job Information</h3>
                                <p class="mt-0.5 text-sm text-zinc-500">Employment details, role assignments, and leave allocations</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="joining_date" type="date" label="Joining Date" required />
                                <flux:input wire:model="probation_end_date" type="date" label="Probation End Date" />
                                <flux:select wire:model="status" label="Current Status">
                                    @foreach($statuses as $case)
                                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="employment_type_id" label="Employment Type">
                                    <option value="">Select Employment Type…</option>
                                    @foreach($employmentTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="work_mode_id" label="Work Mode">
                                    <option value="">Select Work Mode…</option>
                                    @foreach($workModes as $mode)
                                        <option value="{{ $mode->id }}">{{ $mode->name }}</option>
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
                                <flux:select wire:model="salary_cycle_id" label="Salary Cycle">
                                    <option value="">Select Salary Cycle…</option>
                                    @foreach($salaryCycles as $cycle)
                                        <option value="{{ $cycle->id }}">{{ $cycle->name }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model.live="ot_tracking_source" label="OT Tracking Source"
                                    description="Nexflow/Hybrid enables the Nexflow tab and sync.">
                                    <option value="biometric">Biometric (standard attendance)</option>
                                    <option value="manual">Manual (HR-entered)</option>
                                    <option value="nexflow">Nexflow (IT/Dev/QA teams)</option>
                                    <option value="hybrid">Hybrid (both sources)</option>
                                </flux:select>
                                <flux:input wire:model="biometric_id" label="Biometric Device ID"
                                    placeholder="e.g. 3"
                                    description="Leave blank if not enrolled on the biometric device." />
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-5 dark:border-zinc-800">
                                <div class="flex items-center gap-1.5 text-xs text-zinc-400">
                                    <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                    Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check">Save Changes</flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Leave Tab ── --}}
                    @elseif($activeTab === 'Leave')
                        <div wire:key="tab-leave" class="space-y-6">
                            {{-- Header row --}}
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Leave Balance</h3>
                                    <p class="mt-0.5 text-sm text-zinc-500">Centrally managed — allocations are set via Leave Settings.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Year selector --}}
                                    <select wire:model.live="leaveBalanceYear"
                                        class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-1.5 text-sm text-zinc-700 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-indigo-400/40">
                                        @foreach([now()->year - 1, now()->year, now()->year + 1] as $yr)
                                            <option value="{{ $yr }}">{{ $yr }}</option>
                                        @endforeach
                                    </select>
                                    @if($canManageLeaveBalance)
                                        <flux:button wire:click="openManageLeaveModal" variant="primary" icon="adjustments-horizontal" size="sm">
                                            Manage Balance
                                        </flux:button>
                                    @endif
                                </div>
                            </div>

                            {{-- Balance summary table --}}
                            <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-zinc-800">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                                            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Leave Type</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Allocated</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Carried Fwd</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Used</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Encashed</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Available</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Pending</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @forelse($balanceSummary as $row)
                                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition-colors">
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center gap-2">
                                                        <div class="size-2.5 rounded-full shrink-0" style="background-color: {{ $row->leave_type->color }}"></div>
                                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row->leave_type->name }}</span>
                                                        @if($row->leave_type->is_system_controlled)
                                                            <span class="px-1.5 py-0.5 text-[10px] font-bold bg-zinc-100 dark:bg-zinc-800 text-zinc-500 rounded">System</span>
                                                        @endif
                                                        @if($row->leave_type->is_monthly_accrual)
                                                            <span class="px-1.5 py-0.5 text-[10px] font-bold bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 rounded">Accrual</span>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 text-center font-semibold text-zinc-700 dark:text-zinc-300">{{ $row->allocated > 0 ? $row->allocated : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-zinc-500">{{ $row->carried_forward > 0 ? $row->carried_forward : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-amber-600 dark:text-amber-400 font-semibold">{{ $row->used > 0 ? $row->used : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-zinc-500">{{ $row->encashed > 0 ? $row->encashed : '—' }}</td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="font-bold {{ $row->available > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400' }}">
                                                        {{ $row->available > 0 ? $row->available : '0' }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    @if($row->pending > 0)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">
                                                            {{ $row->pending }}d
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">
                                                    No leave balances found for {{ $leaveBalanceYear }}. Balance initializes when leave is allocated via Leave Settings.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            {{-- Adjustment History --}}
                            @if($adjustmentHistory->count() > 0)
                                <div>
                                    <h4 class="mb-3 text-sm font-bold text-zinc-700 dark:text-zinc-300">Adjustment History</h4>
                                    <div class="space-y-2 max-h-64 overflow-y-auto">
                                        @foreach($adjustmentHistory as $log)
                                            <div class="flex items-start gap-3 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-4 py-3">
                                                <div class="mt-0.5 shrink-0">
                                                    @if($log->action === 'credit')
                                                        <span class="flex size-6 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                                            <flux:icon.plus class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                                        </span>
                                                    @else
                                                        <span class="flex size-6 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                                                            <flux:icon.minus class="size-3.5 text-red-600 dark:text-red-400" />
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                            {{ ucfirst($log->action) }} {{ $log->days }} day(s)
                                                        </span>
                                                        <span class="text-xs text-zinc-400">{{ $log->leaveType?->name }}</span>
                                                        <span class="text-xs text-zinc-400">·</span>
                                                        <span class="text-xs text-zinc-400">{{ $log->adjusted_at->format('d M Y, h:i A') }}</span>
                                                        <span class="text-xs text-zinc-400">by {{ $log->adjustedByUser?->name }}</span>
                                                    </div>
                                                    <p class="mt-0.5 text-xs text-zinc-500">{{ $log->reason }}</p>
                                                    @if($log->remarks)
                                                        <p class="text-xs text-zinc-400 italic">{{ $log->remarks }}</p>
                                                    @endif
                                                    <div class="mt-1 flex items-center gap-2 text-[11px] text-zinc-400">
                                                        <span>Balance: {{ $log->previous_balance }} → <strong class="text-zinc-600 dark:text-zinc-300">{{ $log->new_balance }}</strong></span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                        </div>

                    {{-- ── Probation Tab ── --}}
                    @elseif($activeTab === 'Probation')
                        <div wire:key="tab-probation" class="space-y-6">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Probation Management</h3>
                                    <p class="mt-0.5 text-sm text-zinc-500">Review and action this employee's probation period.</p>
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
                                    <flux:button type="button" wire:click="confirmProbation"
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
                                    <flux:button type="button" wire:click="extendProbation" icon="clock">Extend Probation</flux:button>
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
                        <div wire:key="tab-payroll" class="space-y-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Payroll Summary</h3>
                                    <p class="mt-0.5 text-sm text-zinc-500">Manage salary structure and compensation for this employee</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase text-zinc-400">{{ $employee->salaryCycle?->name ?? 'Default Cycle' }}</span>
                                    <flux:button wire:click="openAddSalary" variant="primary" icon="plus" size="sm">Add Component</flux:button>
                                </div>
                            </div>

                            {{-- Net compensation card --}}
                            <div class="rounded-2xl border border-zinc-100 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-800/50">
                                <div class="flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                                    <div>
                                        <div class="text-sm font-bold text-zinc-900 dark:text-white">Total Net Compensation</div>
                                        <div class="text-xs text-zinc-400">Based on assigned salary components</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-black text-indigo-600">₹{{ number_format($netSalary, 2) }}</div>
                                        <div class="flex gap-3 text-xs">
                                            <span class="text-emerald-500">Gross: ₹{{ number_format($grossSalary, 2) }}</span>
                                            <span class="text-rose-500">Deductions: -₹{{ number_format($totalDeductions, 2) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Earnings --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-black uppercase tracking-widest text-emerald-500">Earnings</h4>
                                    <span class="text-xs text-zinc-400">₹{{ number_format($grossSalary, 2) }}</span>
                                </div>
                                @forelse($employee->salaries->where('component.type', 'earning') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-black text-zinc-900 dark:text-white">₹{{ number_format($salary->amount, 2) }}</span>
                                            <flux:button wire:click="openEditSalary({{ $salary->id }})" variant="ghost" size="xs" icon="pencil" class="text-zinc-400 hover:text-indigo-500" />
                                            <flux:button wire:click="removeSalary({{ $salary->id }})" wire:confirm="Remove this earning component?" variant="ghost" size="xs" icon="trash" class="text-zinc-400 hover:text-red-500" />
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-zinc-200 py-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                        No earnings assigned — click <strong>Add Component</strong> to get started.
                                    </div>
                                @endforelse
                            </div>

                            {{-- Deductions --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-black uppercase tracking-widest text-rose-500">Deductions</h4>
                                    <span class="text-xs text-zinc-400">-₹{{ number_format($totalDeductions, 2) }}</span>
                                </div>
                                @forelse($employee->salaries->where('component.type', 'deduction') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-black text-rose-600">-₹{{ number_format($salary->amount, 2) }}</span>
                                            <flux:button wire:click="openEditSalary({{ $salary->id }})" variant="ghost" size="xs" icon="pencil" class="text-zinc-400 hover:text-indigo-500" />
                                            <flux:button wire:click="removeSalary({{ $salary->id }})" wire:confirm="Remove this deduction component?" variant="ghost" size="xs" icon="trash" class="text-zinc-400 hover:text-red-500" />
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-zinc-200 py-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                        No deductions assigned.
                                    </div>
                                @endforelse
                            </div>

                            <div class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-800">
                                <flux:button href="{{ route('payroll.components') }}" wire:navigate variant="ghost" icon="cog-6-tooth" size="sm">
                                    Manage Global Components
                                </flux:button>
                            </div>
                        </div>

                        {{-- Salary component modal --}}
                        @if($showSalaryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showSalaryModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showSalaryModal', false)"></div>
            <div class="relative w-full max-w-sm bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showSalaryModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                            <div class="space-y-5">
                                <div>
                                    <flux:heading size="lg">{{ $editingSalaryId ? 'Edit' : 'Add' }} Salary Component</flux:heading>
                                    <flux:subheading>For {{ $employee->user->name }}</flux:subheading>
                                </div>

                                <div class="space-y-4">
                                    <flux:field>
                                        <flux:label>Component</flux:label>
                                        <flux:select wire:model="salaryComponentId">
                                            <option value="">Select component…</option>
                                            @foreach($salaryComponents->where('type', 'earning') as $sc)
                                                <option value="{{ $sc->id }}">{{ $sc->name }} (Earning)</option>
                                            @endforeach
                                            @foreach($salaryComponents->where('type', 'deduction') as $sc)
                                                <option value="{{ $sc->id }}">{{ $sc->name }} (Deduction)</option>
                                            @endforeach
                                        </flux:select>
                                        @error('salaryComponentId') <flux:error>{{ $message }}</flux:error> @enderror
                                    </flux:field>

                                    <flux:input wire:model="salaryAmount" type="number" step="0.01" min="0" label="Amount (₹)" placeholder="e.g. 25000" required />
                                    @error('salaryAmount') <p class="text-xs text-red-500 -mt-2">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                                    <button type="button" @click="$wire.set('showSalaryModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                                    <flux:button
                                        wire:click="saveSalary"
                                        wire:loading.attr="disabled"
                                        wire:target="saveSalary"
                                        variant="primary"
                                        icon="check"
                                    >Save</flux:button>
                                </div>
                            </div>
                        
            </div>
        </div>
    @endif

                    {{-- ── Documents Tab ── --}}
                    @elseif($activeTab === 'Documents')
                        <div wire:key="tab-documents" class="space-y-5">
                            <div>
                                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Documents</h3>
                                <p class="mt-0.5 text-sm text-zinc-500">Employee documents and files</p>
                            </div>
                            <livewire:employees.employee-documents :employee="$employee" :key="'docs-'.$employee->id" />
                        </div>

                    {{-- ── Activity Tab ── --}}
                    @elseif($activeTab === 'Activity')
                        <div wire:key="tab-activity" class="space-y-5">
                            <div>
                                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Activity Log</h3>
                                <p class="mt-0.5 text-sm text-zinc-500">Recent actions and changes for this employee</p>
                            </div>
                            <div class="flex flex-col items-center justify-center py-16 text-center opacity-60">
                                <flux:icon.clock class="size-10 text-zinc-400 mb-3" />
                                <p class="text-sm text-zinc-500">Activity log coming soon.</p>
                            </div>
                        </div>

                    {{-- ── Nexflow Tab ── --}}
                    @elseif($activeTab === 'Nexflow')
                        <livewire:employees.nexflow-activity :employee="$employee" :key="'nexflow-'.$employee->id" />
                    @endif

                </form>
            </div>
        </div>
    </div>

    {{-- Manage Leave Balance Modal --}}
    @if($showManageLeaveModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeManageLeaveModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeManageLeaveModal"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" wire:click="closeManageLeaveModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">Manage Leave Balance</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">For {{ $employee->user->name }} · {{ $leaveBalanceYear }}</p>
                </div>

                <div class="space-y-4">
                    {{-- Leave Type --}}
                    <flux:field>
                        <flux:label>Leave Type <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model="leaveAdjustTypeId">
                            <option value="">Select leave type…</option>
                            @foreach($adjustableLeaveTypes as $lt)
                                <option value="{{ $lt->id }}">{{ $lt->name }}</option>
                            @endforeach
                        </flux:select>
                        @error('leaveAdjustTypeId') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>

                    {{-- Action --}}
                    <div>
                        <flux:label>Action <span class="text-red-500">*</span></flux:label>
                        <div class="mt-1.5 flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="leaveAdjustAction" value="credit" class="accent-emerald-600" />
                                <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Credit (Add Days)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="leaveAdjustAction" value="debit" class="accent-red-600" />
                                <span class="text-sm font-semibold text-red-600 dark:text-red-400">Debit (Remove Days)</span>
                            </label>
                        </div>
                    </div>

                    {{-- Days --}}
                    <flux:input wire:model="leaveAdjustDays" type="number" step="0.5" min="0.5" label="Days" placeholder="e.g. 2" required />
                    @error('leaveAdjustDays') <p class="text-xs text-red-500 -mt-2">{{ $message }}</p> @enderror

                    {{-- Reason --}}
                    <flux:field>
                        <flux:label>Reason <span class="text-red-500">*</span></flux:label>
                        <flux:textarea wire:model="leaveAdjustReason" rows="2" placeholder="Reason for the adjustment (min 5 chars)…" required />
                        @error('leaveAdjustReason') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>

                    {{-- Remarks --}}
                    <flux:field>
                        <flux:label>Remarks <span class="text-xs font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:textarea wire:model="leaveAdjustRemarks" rows="2" placeholder="Additional notes…" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                    <button type="button" wire:click="closeManageLeaveModal"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                        Cancel
                    </button>
                    <flux:button
                        wire:click="submitLeaveAdjustment"
                        wire:loading.attr="disabled"
                        wire:target="submitLeaveAdjustment"
                        variant="primary"
                        icon="check">
                        <span wire:loading.remove wire:target="submitLeaveAdjustment">Apply Adjustment</span>
                        <span wire:loading wire:target="submitLeaveAdjustment">Applying…</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Send Email Modal --}}
    @if($showEmailModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeEmailModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeEmailModal"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" wire:click="closeEmailModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">Send Email</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">To: {{ $employee->user->email }}</p>
                </div>
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Subject</flux:label>
                        <flux:input wire:model="emailSubject" placeholder="e.g. Important Update" required />
                        @error('emailSubject') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Message</flux:label>
                        <flux:textarea wire:model="emailBody" placeholder="Write your message here…" rows="5" required />
                        @error('emailBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </flux:field>
                </div>
                <div class="flex justify-end gap-3 border-t border-zinc-100 dark:border-zinc-800 pt-4">
                    <button type="button" wire:click="closeEmailModal"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="sendEmail"
                        wire:loading.attr="disabled" wire:target="sendEmail"
                        class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors disabled:opacity-60">
                        <flux:icon.paper-airplane class="size-4" />
                        <span wire:loading.remove wire:target="sendEmail">Send Email</span>
                        <span wire:loading wire:target="sendEmail">Sending…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</flux:main>
