<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950" x-data="{ tab: 'salary' }">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Pay</h1>
            <p class="pulse-page-subtitle">Current compensation, salary history and payslips</p>
        </div>
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="flex items-center gap-1 p-1 bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-100 dark:border-zinc-800">
            <button @click="tab = 'salary'" :class="tab === 'salary' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700'" class="px-6 py-2 text-sm font-bold rounded-xl transition-all">My Salary</button>
            <button @click="tab = 'payslips'" :class="tab === 'payslips' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700'" class="px-6 py-2 text-sm font-bold rounded-xl transition-all">Pay Slips</button>
        </div>

        <div class="p-6">
            {{-- My Salary Tab --}}
            <div x-show="tab === 'salary'" x-transition>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="text-xs font-bold text-zinc-400 uppercase tracking-widest">Current CTC</div>
                        <div class="text-2xl font-black mt-3">₹{{ number_format($ctc, 2) }}</div>
                        <div class="text-sm text-zinc-500 mt-1">Gross annual compensation</div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="text-xs font-bold text-zinc-400 uppercase tracking-widest">Pay Cycle</div>
                        <div class="text-xl font-black mt-3">{{ ($payCycle === 'A' || $payCycle === 'cycle_a') ? 'Cycle A' : (($payCycle === 'B' || $payCycle === 'cycle_b') ? 'Cycle B' : ($payCycle ?? '—')) }}</div>
                        <div class="text-sm text-zinc-500 mt-1">Salary cycle for payroll</div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 flex flex-col justify-between">
                        <div>
                            <div class="text-xs font-bold text-zinc-400 uppercase tracking-widest">Salary Timeline</div>
                            <div class="mt-3 space-y-3">
                                @foreach($timeline as $i => $row)
                                    <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-100 dark:border-zinc-800">
                                        <div>
                                            <div class="text-sm font-bold">{{ $row['effective_from'] }}</div>
                                            <div class="text-xs text-zinc-500">Revised on</div>
                                        </div>
                                        <div class="text-right">
                                            @if($i === 0)
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-[11px] font-black bg-emerald-50 text-emerald-700">CURRENT</span>
                                            @endif
                                            <div class="text-sm font-bold mt-2">₹{{ number_format($row['regular_salary'], 2) }} + ₹{{ number_format($row['allowances'], 2) }} = <span class="font-black">₹{{ number_format($row['total'], 2) }}</span></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="mt-4 text-right">
                            <flux:button wire:click="openSalaryBreakup" variant="ghost">View Salary Breakup</flux:button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Pay Slips Tab --}}
            <div x-show="tab === 'payslips'" x-transition>
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    {{-- Left sidebar: years + months --}}
                    <div class="col-span-1">
                        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="flex items-center justify-between mb-4">
                                <div class="text-sm font-bold">Select Year</div>
                                <div class="text-xs text-zinc-400">{{ $selectedYear }}</div>
                            </div>
                            <div class="space-y-3">
                                @foreach($years as $y)
                                    <button wire:click="selectYear({{ $y }})" class="w-full text-left px-3 py-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 {{ $selectedYear == $y ? 'bg-zinc-100 dark:bg-zinc-800 font-bold' : '' }}">{{ $y }}</button>
                                @endforeach
                            </div>

                            <div class="mt-6 border-t pt-4">
                                @if(isset($payslipsByYear[$selectedYear]))
                                    @foreach($payslipsByYear[$selectedYear] as $pslip)
                                        <div wire:click="selectPayslip({{ $pslip->id }})" class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 cursor-pointer {{ $selectedPayslipId == $pslip->id ? 'bg-zinc-100 dark:bg-zinc-800' : '' }}">
                                            <div>
                                                <div class="text-sm font-bold">{{ $pslip->payroll->month }} {{ $pslip->payroll->year }}</div>
                                                <div class="text-xs text-zinc-400">Issued {{ $pslip->updated_at->format('M d, Y') }}</div>
                                            </div>
                                            <div class="text-right">
                                                    <div class="font-black">₹{{ number_format($pslip->net_salary, 2) }}</div>
                                                    <div class="mt-1 text-xs">
                                                        <a href="{{ URL::temporarySignedRoute('payroll.payslips.download', now()->addMinutes(5), ['payslip' => $pslip->id]) }}" target="_blank" onclick="event.stopPropagation()" class="text-xs text-brand-600 hover:underline">View PDF</a>
                                                    </div>
                                            </div>
                                        </div>
                                    @endforeach
                                @else
                                    <div class="text-sm text-zinc-400 mt-3">No payslips for this year.</div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Right preview panel --}}
                    <div class="col-span-3">
                        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 min-h-[420px]">
                            @if($selectedSlip)
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <h3 class="text-lg font-bold">Payslip — {{ $selectedSlip->payroll->month }} {{ $selectedSlip->payroll->year }}</h3>
                                        <div class="text-xs text-zinc-400">Issued on {{ $selectedSlip->updated_at->format('M d, Y') }}</div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <flux:button href="{{ URL::temporarySignedRoute('payroll.payslips.download', now()->addMinutes(5), ['payslip' => $selectedSlip->id]) }}" target="_blank" variant="primary" icon="arrow-down-tray">View PDF</flux:button>
                                    </div>
                                </div>

                                {{-- Inline Payslip Preview (simplified) --}}
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Employee</div>
                                        <div class="font-bold mt-2">{{ $selectedSlip->employee->user->name }}</div>
                                        <div class="text-sm text-zinc-500">{{ $selectedSlip->employee->jobTitle?->name ?? '—' }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Payslip</div>
                                        <div class="font-bold mt-2">#{{ str_pad($selectedSlip->id, 6, '0', STR_PAD_LEFT) }}</div>
                                        <div class="text-sm text-zinc-500">Net: ₹{{ number_format($selectedSlip->net_salary, 2) }}</div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-4 border-b pb-2">Earnings</h4>
                                        <div class="space-y-2">
                                            @foreach($selectedSlip->items->where('type','earning') as $item)
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-zinc-600">{{ $item->name }}</span>
                                                    <span class="font-bold">₹{{ number_format($item->amount, 2) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div>
                                        <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-4 border-b pb-2">Deductions</h4>
                                        <div class="space-y-2">
                                            @foreach($selectedSlip->items->where('type','deduction') as $item)
                                                <div class="flex justify-between text-sm">
                                                    <span class="text-zinc-600">{{ $item->name }}</span>
                                                    <span class="font-bold text-rose-600">-₹{{ number_format($item->amount, 2) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-zinc-900 text-white p-6 rounded-lg text-center md:text-left">
                                    <div class="text-sm">Gross: ₹{{ number_format($selectedSlip->gross_salary, 2) }}</div>
                                    <div class="text-sm">Deductions: -₹{{ number_format($selectedSlip->total_deductions, 2) }}</div>
                                    <div class="text-2xl font-black mt-2">Net Pay: ₹{{ number_format($selectedSlip->net_salary, 2) }}</div>
                                </div>
                            @else
                                <div class="flex flex-col items-center justify-center h-full text-center text-zinc-400">
                                    <flux:icon.document-text class="size-12 mb-4" />
                                    <div class="font-bold">Select a payslip to preview</div>
                                    <div class="text-sm mt-2">Choose a year and a payslip from the left to view details</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Salary Breakup Modal --}}
    <flux:modal wire:model="showSalaryBreakup" class="max-w-4xl">
        <div>
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-lg">Salary Breakup</h3>
                    <p class="text-sm text-zinc-500">Monthly and annual breakdown of your components</p>
                </div>
                <flux:modal.close>
                    <flux:button variant="ghost">Close</flux:button>
                </flux:modal.close>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-3">Earnings</h4>
                    <div class="space-y-2">
                        @foreach($salaryComponents->where('component.type','!=','deduction') as $sc)
                            <div class="flex justify-between text-sm">
                                <div class="text-zinc-600">{{ $sc->component->name }}</div>
                                <div class="text-right">
                                    <div class="font-bold">₹{{ number_format($sc->amount, 2) }}</div>
                                    <div class="text-xs text-zinc-400">Annual: ₹{{ number_format($sc->amount * 12, 2) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <h4 class="text-xs font-black uppercase tracking-widest text-zinc-400 mb-3">Deductions</h4>
                    <div class="space-y-2">
                        @foreach($salaryComponents->where('component.type','deduction') as $sc)
                            <div class="flex justify-between text-sm">
                                <div class="text-zinc-600">{{ $sc->component->name }}</div>
                                <div class="text-right">
                                    <div class="font-bold">₹{{ number_format($sc->amount, 2) }}</div>
                                    <div class="text-xs text-zinc-400">Annual: ₹{{ number_format($sc->amount * 12, 2) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-6 text-right">
                <flux:button wire:click="$set('showSalaryBreakup', false)" variant="ghost">Close</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>