<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>Add Employee</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <h1 class="pulse-page-title">Add New Employee</h1>
            <p class="pulse-page-subtitle">Create a new user and employee profile.</p>
        </div>
    </div>

    <form wire:submit="save" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- User Account Info --}}
        <div class="pulse-card space-y-5">
            <h3 class="font-semibold text-zinc-900 dark:text-white mb-4 border-b border-zinc-100 pb-2 dark:border-zinc-800">Account Information</h3>
            
            <flux:input wire:model="name" label="Full Name" placeholder="e.g. John Doe" required />
            <flux:input wire:model="email" type="email" label="Email Address" placeholder="e.g. john@example.com" required />
            
            <flux:select wire:model="role" label="Account Role">
                @foreach($roles as $roleCase)
                    <option value="{{ $roleCase->value }}">{{ ucfirst($roleCase->value) }}</option>
                @endforeach
            </flux:select>

            <div class="text-sm text-zinc-500 mt-2">
                Note: A default password "<kbd class="font-mono bg-zinc-100 px-1 rounded dark:bg-zinc-800">password</kbd>" will be set.
            </div>
        </div>

        {{-- HR Profile Info --}}
        <div class="pulse-card space-y-5">
            <h3 class="font-semibold text-zinc-900 dark:text-white mb-4 border-b border-zinc-100 pb-2 dark:border-zinc-800">HR Information</h3>
            
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="employee_id" label="Employee ID" required />
                <flux:input wire:model="joining_date" type="date" label="Joining Date" required />
            </div>

            <div class="grid grid-cols-2 gap-4">
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
            </div>

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

            <div class="grid grid-cols-2 gap-4">
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
            </div>
        </div>

        <div class="lg:col-span-2 flex items-center justify-end gap-3 mt-4">
            <flux:button href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create Employee</flux:button>
        </div>
    </form>
</flux:main>
