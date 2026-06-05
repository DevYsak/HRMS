<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $employee->user->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Probation Confirmation</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl">Probation Confirmation — {{ $employee->user->name }}</flux:heading>
    <flux:subheading>Two-stage approval: Manager confirms first, then HR Admin co-approves to finalise.</flux:subheading>

    {{-- Status Timeline --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">Approval Status</flux:heading>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

            {{-- Stage 1: Manager --}}
            <div class="rounded-xl border p-4 space-y-2 {{ $employee->probation_confirmed_at ? 'border-green-400 bg-green-50 dark:bg-green-950/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                <div class="flex items-center gap-2">
                    @if($employee->probation_confirmed_at)
                        <flux:icon.check-circle class="text-green-500 size-5" />
                        <flux:badge color="green">Stage 1 Complete</flux:badge>
                    @else
                        <flux:icon.clock class="text-amber-500 size-5" />
                        <flux:badge color="yellow">Stage 1 Pending</flux:badge>
                    @endif
                </div>
                <p class="text-sm font-semibold">Manager / Director Confirmation</p>
                @if($employee->probation_confirmed_at)
                    <p class="text-xs text-zinc-500">Confirmed by {{ $employee->probationConfirmedBy?->name ?? 'N/A' }} on {{ $employee->probation_confirmed_at->format('d M Y, h:i A') }}</p>
                @else
                    <p class="text-xs text-zinc-400">Awaiting manager review</p>
                @endif
            </div>

            {{-- Stage 2: HR --}}
            <div class="rounded-xl border p-4 space-y-2 {{ $employee->probation_hr_approved_at ? 'border-green-400 bg-green-50 dark:bg-green-950/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                <div class="flex items-center gap-2">
                    @if($employee->probation_hr_approved_at)
                        <flux:icon.check-circle class="text-green-500 size-5" />
                        <flux:badge color="green">Stage 2 Complete</flux:badge>
                    @elseif($employee->probation_confirmed_at)
                        <flux:icon.clock class="text-amber-500 size-5" />
                        <flux:badge color="yellow">Stage 2 Pending</flux:badge>
                    @else
                        <flux:icon.lock-closed class="text-zinc-400 size-5" />
                        <flux:badge color="zinc">Locked</flux:badge>
                    @endif
                </div>
                <p class="text-sm font-semibold">HR Admin Co-Approval</p>
                @if($employee->probation_hr_approved_at)
                    <p class="text-xs text-zinc-500">Approved by {{ $employee->probationHrApprovedBy?->name ?? 'N/A' }} on {{ $employee->probation_hr_approved_at->format('d M Y, h:i A') }}</p>
                @else
                    <p class="text-xs text-zinc-400">Awaiting HR sign-off</p>
                @endif
            </div>
        </div>
    </flux:card>

    {{-- Probation Details --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg">Probation Details</flux:heading>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-zinc-500">Joining Date</span><p class="font-semibold">{{ $employee->joining_date?->format('d M Y') ?? '—' }}</p></div>
            <div><span class="text-zinc-500">Probation End Date</span><p class="font-semibold">{{ $employee->probation_end_date?->format('d M Y') ?? '—' }}</p></div>
            <div><span class="text-zinc-500">Current Status</span><p class="font-semibold">{{ $employee->status?->label() ?? '—' }}</p></div>
            <div><span class="text-zinc-500">Extension Reason</span><p class="font-semibold">{{ $employee->probation_extension_reason ?? '—' }}</p></div>
        </div>
    </flux:card>

    @if($employee->isProbationFullyConfirmed())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>Probation Fully Confirmed</flux:callout.heading>
            <flux:callout.text>This employee's probation has been confirmed by both manager and HR. Status is now Active.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Stage 1 Action --}}
        @if(! $employee->probation_confirmed_at && in_array(auth()->user()->role, ['manager', 'director', 'super_admin']))
            <flux:card class="space-y-4">
                <flux:heading size="lg">Stage 1 — Manager Confirmation</flux:heading>
                <flux:field>
                    <flux:label>Comment (optional)</flux:label>
                    <flux:textarea wire:model="managerComment" placeholder="Add any notes about this employee's probation completion…" rows="3" />
                    <flux:error name="managerComment" />
                </flux:field>
                <flux:button wire:click="managerConfirm" wire:confirm="Confirm that this employee has completed their probation period?" variant="primary">
                    Confirm Probation Completion
                </flux:button>
            </flux:card>
        @endif

        {{-- Stage 2 Action --}}
        @if($employee->probation_confirmed_at && ! $employee->probation_hr_approved_at && in_array(auth()->user()->role, ['hr_admin', 'super_admin']))
            <flux:card class="space-y-4">
                <flux:heading size="lg">Stage 2 — HR Co-Approval</flux:heading>
                <flux:field>
                    <flux:label>HR Comment (optional)</flux:label>
                    <flux:textarea wire:model="hrComment" placeholder="Add HR notes…" rows="3" />
                    <flux:error name="hrComment" />
                </flux:field>
                <flux:button wire:click="hrApprove" wire:confirm="Finalise probation and set employee status to Active?" variant="primary">
                    Approve & Activate Employee
                </flux:button>
            </flux:card>
        @endif
    @endif

</flux:main>
