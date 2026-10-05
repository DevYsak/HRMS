<flux:main class="space-y-6 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Modules</flux:heading>
        <flux:subheading>Switch whole product areas on or off. Switching a module off hides and blocks it — it never deletes data.</flux:subheading>
    </div>

    {{-- Payroll & Payslips --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 dark:bg-orange-900/20">
                    <flux:icon.banknotes class="size-5 text-orange-500" />
                </span>
                <div>
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">Payroll &amp; Payslips</flux:heading>
                        @if($payrollEnabled)
                            <flux:badge size="sm" color="emerald">Enabled</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">Disabled</flux:badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Controls payroll processing, Finance payroll approvals and employee payslip access.</p>
                    @if($updatedBy)
                        <p class="mt-1 text-xs text-zinc-400">Last changed by {{ $updatedBy }} on {{ $updatedAt?->format('d M Y, g:i A') }}.</p>
                    @endif
                </div>
            </div>

            @if($payrollEnabled)
                <flux:button wire:click="askDisablePayroll" variant="danger" icon="power">Disable Payroll &amp; Payslips</flux:button>
            @else
                <flux:button wire:click="enablePayroll" variant="primary" icon="power">Enable Payroll &amp; Payslips</flux:button>
            @endif
        </div>

        <div class="mt-4 flex items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-white/5">
            <div>
                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Payslips</div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Employee payslip pages, downloads and emails. Payslips cannot be enabled while Payroll is disabled.</p>
            </div>
            <flux:button size="sm" wire:click="togglePayslips" :disabled="! $payrollEnabled">
                {{ $payslipsEnabled ? 'Disable payslips' : 'Enable payslips' }}
            </flux:button>
        </div>
    </div>

    <flux:modal wire:model.self="confirmingDisable" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Disable Payroll &amp; Payslips?</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                Payroll processing, Finance payroll approval and employee payslip access will be hidden and blocked.
                Existing payroll and payslip records will be preserved.
            </p>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('confirmingDisable', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="confirmDisablePayroll" variant="danger">Disable</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
