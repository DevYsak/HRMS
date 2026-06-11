<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Add Employee</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Add New Employee</h1>
            <p class="pulse-page-subtitle">Create a new user account and employee profile. Spec §3.1</p>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">

        {{-- Section 1: Account --}}
        <div class="pulse-card space-y-5">
            <h3 class="font-semibold text-zinc-900 dark:text-white border-b border-zinc-100 pb-2 dark:border-zinc-800">
                Account Information</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input wire:model="name" label="Full Name" placeholder="e.g. John Doe" required />
                <flux:input wire:model="email" type="email" label="Email Address" placeholder="e.g. john@conexus.com"
                    required />
                <flux:select wire:model="role" label="System Role">
                    @foreach($roles as $roleCase)
                        <option value="{{ $roleCase->value }}">{{ $roleCase->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
            <p class="text-xs text-zinc-500">Default password: <kbd
                    class="font-mono bg-zinc-100 px-1 rounded dark:bg-zinc-800">Password@123</kbd> — employee should
                change on first login.</p>
        </div>

        {{-- Section 2: Personal Profile (Spec §3.1) --}}
        <div class="pulse-card space-y-5">
            <h3 class="font-semibold text-zinc-900 dark:text-white border-b border-zinc-100 pb-2 dark:border-zinc-800">
                Personal Profile</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <flux:input wire:model="employee_id" label="Employee ID" required />
                <flux:field>
                    <flux:label>
                        Bio Code
                        <flux:badge size="sm" color="amber" class="ml-1">Biometric</flux:badge>
                    </flux:label>
                    <flux:input wire:model="employee_code" type="number" min="1" max="65535" placeholder="e.g. 17" />
                    <flux:error name="employee_code" />
                    <!-- <flux:description></flux:description> -->
                </flux:field>
                <flux:input wire:model="phone" label="Phone" placeholder="+91 98765 43210" />
                <flux:input wire:model="date_of_birth" type="date" label="Date of Birth" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="gender" label="Gender">
                    <option value="">Select…</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                    <option value="prefer_not_to_say">Prefer not to say</option>
                </flux:select>
                <flux:input wire:model="emergency_contact" label="Emergency Contact"
                    placeholder="Name & phone number" />
            </div>
            <flux:textarea wire:model="address" label="Address" rows="2" placeholder="Full residential address" />
            <div>
                <flux:label>Profile Photo</flux:label>
                <flux:input wire:model="photo" type="file" accept="image/*" class="mt-1" />
                @error('photo') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Section 3: Employment Record (Spec §3.1) --}}
        <div class="pulse-card space-y-5">
            <h3 class="font-semibold text-zinc-900 dark:text-white border-b border-zinc-100 pb-2 dark:border-zinc-800">
                Employment Record</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
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
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="manager_id" label="Line Manager">
                    <option value="">Select Manager…</option>
                    @foreach($managers as $mgr)
                        <option value="{{ $mgr->id }}">{{ $mgr->name }} ({{ $mgr->role->label() }})</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="shift_id" label="Shift">
                    <option value="">Select Shift…</option>
                    @foreach($shifts as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <flux:select wire:model="status" label="Status">
                    @foreach($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="employment_type_id" label="Employment Type">
                    <option value="">Select Type…</option>
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
                <flux:select wire:model="salary_cycle_id" label="Salary Cycle">
                    <option value="">Select Cycle…</option>
                    @foreach($salaryCycles as $cycle)
                        <option value="{{ $cycle->id }}">{{ $cycle->name }} ({{ $cycle->periodLabel() }})</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="ot_tracking_source" label="OT Tracking Source"
                    description="Auto-populated from department default. Nexflow enables Nexflow sync.">
                    <option value="biometric">Biometric (standard attendance)</option>
                    <option value="manual">Manual (HR-entered)</option>
                    <option value="nexflow">Nexflow (IT/Dev/QA teams)</option>
                    <option value="hybrid">Hybrid (both sources)</option>
                </flux:select>
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model.live="joining_date" type="date" label="Joining Date" required />
                <flux:field>
                    <flux:label>Probation End Date</flux:label>
                    <flux:input wire:model="probation_end_date" type="date" />
                    <flux:description>Auto-calculated from employment type. Override if needed.</flux:description>
                    <flux:error name="probation_end_date" />
                </flux:field>
            </div>
        </div>

        {{-- Section 4: Leave Allocations --}}
        <div class="pulse-card space-y-3">
            <h3 class="font-semibold text-zinc-900 dark:text-white border-b border-zinc-100 pb-2 dark:border-zinc-800">
                Assign Leave Balances (optional)</h3>
            <p class="text-xs text-zinc-500">Set initial allocated days per leave type for this employee. Leave blank or
                zero to skip.</p>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 mt-3">
                @foreach(App\Models\LeaveType::all() as $lt)
                    <div>
                        <flux:input wire:model.defer="leave_allocations.{{ $lt->id }}" type="number" step="0.5"
                            label="{{ $lt->name }} (days)" />
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <flux:button href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
            <flux:button type="submit" variant="primary" icon="user-plus">Create Employee</flux:button>
        </div>
    </form>
</flux:main>