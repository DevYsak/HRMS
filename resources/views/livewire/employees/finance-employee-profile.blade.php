<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $employee->user->name }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>Finance View</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl">Finance Profile — {{ $employee->user->name }}</flux:heading>
    <flux:subheading>Read-only salary and banking information for payroll processing.</flux:subheading>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

        {{-- Bank Details --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg" icon="building-library">Bank Details</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">Bank Name</dt><dd class="font-semibold">{{ $employee->bank_name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Account Number</dt><dd class="font-semibold font-mono">{{ $employee->bank_account ? '****' . substr($employee->bank_account, -4) : '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">IFSC Code</dt><dd class="font-semibold font-mono">{{ $employee->ifsc_code ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Account Type</dt><dd class="font-semibold">Savings</dd></div>
            </dl>
        </flux:card>

        {{-- Statutory / Tax Info --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg" icon="document-text">Statutory Information</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">PAN Number</dt><dd class="font-semibold font-mono">{{ $employee->pan_number ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">UAN (PF)</dt><dd class="font-semibold font-mono">{{ $employee->uan_number ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">PF Account No</dt><dd class="font-semibold font-mono">{{ $employee->pf_account ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">ESI Number</dt><dd class="font-semibold font-mono">{{ $employee->esi_number ?? '—' }}</dd></div>
            </dl>
        </flux:card>

        {{-- Employment & Salary Cycle --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg" icon="calendar">Payroll Settings</flux:heading>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-zinc-500">Employee ID</dt><dd class="font-semibold">{{ $employee->employee_id ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Employment Type</dt><dd class="font-semibold">{{ $employee->employmentType?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Salary Cycle</dt><dd class="font-semibold">{{ $employee->salaryCycle?->name ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Joining Date</dt><dd class="font-semibold">{{ $employee->joining_date?->format('d M Y') ?? '—' }}</dd></div>
            </dl>
        </flux:card>

        {{-- Salary Components --}}
        <flux:card class="space-y-4">
            <flux:heading size="lg" icon="currency-rupee">Salary Components</flux:heading>
            @forelse($salaries as $salary)
                <div class="flex justify-between text-sm border-b pb-2 last:border-0 last:pb-0 dark:border-zinc-700">
                    <span class="text-zinc-500">{{ $salary->salaryComponent?->name ?? 'Component' }}</span>
                    <span class="font-semibold">₹{{ number_format($salary->amount, 2) }}</span>
                </div>
            @empty
                <p class="text-sm text-zinc-400">No salary components configured.</p>
            @endforelse
        </flux:card>
    </div>

    {{-- Recent Payslips --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg" icon="document-duplicate">Recent Payslips</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Month</flux:table.column>
                <flux:table.column>Gross</flux:table.column>
                <flux:table.column>Deductions</flux:table.column>
                <flux:table.column>Net Pay</flux:table.column>
                <flux:table.column>Status</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($payslips as $payslip)
                    <flux:table.row>
                        <flux:table.cell>{{ $payslip->payroll->month }} {{ $payslip->payroll->year }}</flux:table.cell>
                        <flux:table.cell>₹{{ number_format($payslip->gross_salary, 2) }}</flux:table.cell>
                        <flux:table.cell>₹{{ number_format($payslip->total_deductions, 2) }}</flux:table.cell>
                        <flux:table.cell class="font-semibold text-green-600">₹{{ number_format($payslip->net_salary, 2) }}</flux:table.cell>
                        <flux:table.cell><flux:badge color="{{ $payslip->status === 'paid' ? 'green' : 'yellow' }}">{{ ucfirst($payslip->status) }}</flux:badge></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-400">No payslips found.</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        {{ $payslips->links() }}
    </flux:card>

</flux:main>
