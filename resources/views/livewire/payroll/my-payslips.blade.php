<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Payslips</h1>
            <p class="pulse-page-subtitle">Access your monthly salary records and tax documents</p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($payslips as $slip)
            <div class="pulse-card hover:border-brand-500 transition-all group cursor-pointer"
                wire:click="viewDetails({{ $slip->id }})">
                <div class="flex justify-between items-start mb-6">
                    <div class="size-12 rounded-2xl bg-zinc-50 flex items-center justify-center dark:bg-zinc-900">
                        <flux:icon.document-text
                            class="size-6 text-zinc-400 group-hover:text-brand-600 transition-colors" />
                    </div>
                    <div class="badge-on_time">PAID</div>
                </div>

                <div class="space-y-1 mb-6">
                    <h3 class="text-xl font-black text-zinc-900 dark:text-white italic">{{ $slip->payroll->month }}
                        {{ $slip->payroll->year }}</h3>
                    <p class="text-sm text-zinc-500">Issued on {{ $slip->updated_at->format('M d, Y') }}</p>
                </div>

                <div class="flex justify-between items-center pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Net Amount</div>
                        <div class="text-lg font-bold text-zinc-900 dark:text-white">
                            ${{ number_format($slip->net_salary, 2) }}</div>
                    </div>
                    <flux:button variant="ghost" size="sm" icon="eye">View Details</flux:button>
                </div>
            </div>
        @empty
            <div class="col-span-full pulse-card py-24 text-center">
                <flux:icon.document-minus class="size-12 text-zinc-300 mx-auto mb-4" />
                <h3 class="font-bold text-zinc-900 dark:text-white">No payslips yet</h3>
                <p class="text-sm text-zinc-500">Your monthly payslips will appear here once they are processed by HR.</p>
            </div>
        @endforelse
    </div>

    {{-- Payslip Detail Modal --}}
    <flux:modal wire:model="showModal" class="w-full max-w-4xl p-0 overflow-hidden">
        @if($selectedSlip)
            <div x-data="{ printPage() { window.print(); } }" class="bg-white dark:bg-zinc-950">
                {{-- Modal Header --}}
                <div
                    class="p-6 border-b border-zinc-100 flex justify-between items-center print:hidden dark:border-zinc-800">
                    <h3 class="font-bold">Payslip Details</h3>
                    <div class="flex gap-2">
                        <flux:button
                            href="{{ URL::temporarySignedRoute('payroll.payslips.download', now()->addMinutes(5), ['payslip' => $selectedSlip->id]) }}"
                            icon="arrow-down-tray" variant="primary">Download PDF</flux:button>
                        <flux:button x-on:click="printPage()" icon="printer">Print</flux:button>
                        <flux:modal.close>
                            <flux:button variant="ghost">Close</flux:button>
                        </flux:modal.close>
                    </div>
                </div>

                {{-- Printable Payslip Content --}}
                <div class="p-12 print:p-0" id="printable-payslip">
                    <div class="flex justify-between items-start mb-12">
                        <div>
                            <h1 class="text-2xl font-black italic tracking-tighter text-brand-600">PULSE HRMS</h1>
                            <p class="text-sm text-zinc-500 mt-1">Official Salary Statement</p>
                        </div>
                        <div class="text-right">
                            <h2 class="text-xl font-bold text-zinc-900 dark:text-white">PAYSLIP
                                #{{ str_pad($selectedSlip->id, 6, '0', STR_PAD_LEFT) }}</h2>
                            <p class="text-sm text-zinc-500">For the month of {{ $selectedSlip->payroll->month }}
                                {{ $selectedSlip->payroll->year }}</p>
                        </div>
                    </div>

                    {{-- Employee Info --}}
                    <div class="grid grid-cols-2 gap-12 mb-12 py-8 border-y border-zinc-100 dark:border-zinc-800">
                        <div class="space-y-4">
                            <div>
                                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Employee Details
                                </div>
                                <div class="font-bold text-zinc-900 mt-1 dark:text-white">
                                    {{ $selectedSlip->employee->user->name }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $selectedSlip->employee->jobTitle->name }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-400">ID:
                                    {{ $selectedSlip->employee->employee_id }}</div>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Department &
                                    Location</div>
                                <div class="font-bold text-zinc-900 mt-1 dark:text-white">
                                    {{ $selectedSlip->employee->department->name }}</div>
                                <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $selectedSlip->employee->office->name }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- Earnings & Deductions --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mb-12">
                        {{-- Earnings --}}
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-4 border-b pb-2">
                                Earnings</h4>
                            <div class="space-y-3">
                                @foreach($selectedSlip->items->where('type', 'earning') as $item)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                        <span
                                            class="font-bold text-zinc-900 dark:text-white">${{ number_format($item->amount, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Deductions --}}
                        <div>
                            <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-4 border-b pb-2">
                                Deductions</h4>
                            <div class="space-y-3">
                                @foreach($selectedSlip->items->where('type', 'deduction') as $item)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                        <span class="font-bold text-red-600">-${{ number_format($item->amount, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Totals --}}
                    <div
                        class="bg-zinc-900 text-white p-8 rounded-2xl flex flex-col md:flex-row justify-between gap-6 print:bg-white print:text-black print:border print:border-zinc-200">
                        <div class="text-center md:text-left">
                            <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest print:text-zinc-400">
                                Gross Salary</div>
                            <div class="text-xl font-bold mt-1">${{ number_format($selectedSlip->gross_salary, 2) }}</div>
                        </div>
                        <div class="text-center md:text-left">
                            <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest print:text-zinc-400">
                                Total Deductions</div>
                            <div class="text-xl font-bold mt-1 text-red-400 print:text-red-600">
                                -${{ number_format($selectedSlip->total_deductions, 2) }}</div>
                        </div>
                        <div class="text-center md:text-right">
                            <div
                                class="text-[10px] font-bold text-brand-400 uppercase tracking-widest print:text-brand-600">
                                Net Payable</div>
                            <div class="text-3xl font-black">${{ number_format($selectedSlip->net_salary, 2) }}</div>
                        </div>
                    </div>

                    <div class="mt-12 text-center text-[10px] text-zinc-400 uppercase tracking-widest hidden print:block">
                        This is a computer-generated payslip and does not require a physical signature.
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    <style>
        @media print {
            body * {
                visibility: hidden;
            }

            #printable-payslip,
            #printable-payslip * {
                visibility: visible;
            }

            #printable-payslip {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }

            flux-modal {
                display: block !important;
                visibility: visible !important;
            }
        }
    </style>
</flux:main>