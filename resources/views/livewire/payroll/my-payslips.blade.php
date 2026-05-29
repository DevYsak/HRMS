<flux:main class="min-h-screen bg-[#F7F8FA] dark:bg-zinc-950 p-6 space-y-6">

    {{-- ── Header ──────────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">My Payslip</h1>
            <p class="text-sm text-zinc-500 mt-0.5">View salary details, payslips and compensation history</p>
        </div>
    </div>

    {{-- ── KPI Cards ───────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center shrink-0">
                <flux:icon.banknotes class="size-6 text-brand-600" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Current Monthly Salary</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">₹{{ number_format($monthlyNet, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">Net payable amount</div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center shrink-0">
                <flux:icon.chart-bar class="size-6 text-violet-600" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Annual CTC</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">₹{{ number_format($ctc, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">Current package</div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                <flux:icon.calendar-days class="size-6 text-emerald-600" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Last Payslip</div>
                <div class="text-xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">
                    {{ $latestPayslip ? ($latestPayslip->payroll->month . ' ' . $latestPayslip->payroll->year) : '—' }}
                </div>
                <div class="text-xs text-emerald-600 mt-1 font-semibold">
                    {{ $latestPayslip ? 'Salary credited on ' . \Carbon\Carbon::parse($latestPayslip->updated_at)->format('M d, Y') : 'No payslip yet' }}
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center shrink-0">
                <flux:icon.arrow-trending-down class="size-6 text-red-500" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Total Deductions</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">₹{{ number_format($monthlyDeductions, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">PF, PT and taxes</div>
            </div>
        </div>
    </div>

    {{-- ── Main two-column layout ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {{-- LEFT: Trend + Revision + History ──────────────────────────────── --}}
        <div class="xl:col-span-2 space-y-6">

            {{-- Salary Trend Chart --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="font-bold text-zinc-900 dark:text-white">Salary Trend <span class="text-sm font-normal text-zinc-400">(Last 6 Months)</span></h3>
                </div>

                @if(count($trendGross) > 0)
                    @php
                        $maxVal = max(array_merge($trendGross, [1]));
                        $chartH = 140;
                    @endphp
                    <div x-data="{
                        gross: {{ json_encode($trendGross) }},
                        net: {{ json_encode($trendNet) }},
                        labels: {{ json_encode($trendLabels) }},
                        max: {{ $maxVal }},
                        tooltip: null,
                        tooltipX: 0, tooltipY: 0,
                        points(arr) {
                            const w = 100 / (arr.length - 1 || 1);
                            return arr.map((v, i) => `${i * w},${100 - (v / this.max * 85)}`).join(' ');
                        }
                    }" class="relative">
                        {{-- Y-axis labels --}}
                        <div class="flex">
                            <div class="flex flex-col justify-between text-[10px] text-zinc-400 text-right pr-2 shrink-0" style="height:{{ $chartH }}px; width:60px">
                                <span>₹{{ number_format($maxVal, 0) }}</span>
                                <span>₹{{ number_format($maxVal * 0.5, 0) }}</span>
                                <span>₹0</span>
                            </div>
                            <div class="flex-1 relative" style="height:{{ $chartH }}px">
                                <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="absolute inset-0 w-full h-full">
                                    {{-- Grid lines --}}
                                    <line x1="0" y1="15" x2="100" y2="15" stroke="#e5e7eb" stroke-width="0.3" />
                                    <line x1="0" y1="57" x2="100" y2="57" stroke="#e5e7eb" stroke-width="0.3" />
                                    <line x1="0" y1="100" x2="100" y2="100" stroke="#e5e7eb" stroke-width="0.3" />
                                    {{-- Gross fill --}}
                                    <template x-if="gross.length > 1">
                                        <polygon
                                            :points="'0,100 ' + gross.map((v,i) => `${i * (100/(gross.length-1))},${100-(v/max*85)}`).join(' ') + ` ${100},100`"
                                            fill="rgba(254,154,0,0.12)" />
                                    </template>
                                    {{-- Gross line --}}
                                    <template x-if="gross.length > 1">
                                        <polyline
                                            :points="gross.map((v,i) => `${i*(100/(gross.length-1))},${100-(v/max*85)}`).join(' ')"
                                            fill="none" stroke="#fe9a00" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
                                    </template>
                                    {{-- Net line --}}
                                    <template x-if="net.length > 1">
                                        <polyline
                                            :points="net.map((v,i) => `${i*(100/(net.length-1))},${100-(v/max*85)}`).join(' ')"
                                            fill="none" stroke="#10b981" stroke-width="1.5" stroke-dasharray="2 1" stroke-linejoin="round" stroke-linecap="round" />
                                    </template>
                                    {{-- Data points --}}
                                    <template x-for="(v,i) in gross" :key="i">
                                        <circle
                                            :cx="i*(100/(gross.length-1))" :cy="100-(v/max*85)"
                                            r="1.5" fill="#fe9a00" />
                                    </template>
                                </svg>
                            </div>
                        </div>
                        {{-- X-axis labels --}}
                        <div class="flex ml-[68px] mt-2">
                            <div class="flex-1 flex justify-between">
                                @foreach($trendLabels as $label)
                                    <span class="text-[10px] text-zinc-400">{{ $label }}</span>
                                @endforeach
                            </div>
                        </div>
                        {{-- Legend --}}
                        <div class="flex items-center gap-4 mt-3 ml-[68px]">
                            <div class="flex items-center gap-1.5"><div class="w-4 h-0.5 bg-brand-500"></div><span class="text-xs text-zinc-500">Gross</span></div>
                            <div class="flex items-center gap-1.5"><div class="w-4 h-0.5 bg-emerald-500" style="border-top: 1.5px dashed #10b981"></div><span class="text-xs text-zinc-500">Net</span></div>
                        </div>
                    </div>
                @else
                    <div class="flex items-center justify-center h-32 text-zinc-400 text-sm">No payslip data yet</div>
                @endif
            </div>

            {{-- Salary Revision History --}}
            @if(count($salaryRevisions) > 0)
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm">
                    <h3 class="font-bold text-zinc-900 dark:text-white mb-5">Salary Revision History</h3>
                    <div class="space-y-4">
                        @foreach($salaryRevisions as $rev)
                            <div class="flex items-start gap-3">
                                <div class="mt-1 size-3 rounded-full shrink-0
                                    {{ $rev['color'] === 'emerald' ? 'bg-emerald-500' : ($rev['color'] === 'red' ? 'bg-red-500' : 'bg-blue-500') }}">
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $rev['month'] }}</span>
                                        <span class="text-sm font-bold text-zinc-900 dark:text-white">₹{{ number_format($rev['net'], 0) }}</span>
                                    </div>
                                    <div class="text-xs text-zinc-500 mt-0.5">{{ $rev['label'] }}
                                        @if($rev['change'] != 0)
                                            &nbsp;· Revised to ₹{{ number_format($rev['gross'], 0) }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Payslip History Table --}}
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
                    <h3 class="font-bold text-zinc-900 dark:text-white">Payslip History</h3>
                    <select wire:model.live="filterYear"
                        class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-brand-400/30">
                        <option value="">All Years</option>
                        @foreach(range(now()->year, now()->year - 3) as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-zinc-50/70 dark:bg-zinc-950/40 border-b border-zinc-100 dark:border-zinc-800">
                                <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Month</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Payroll Cycle</th>
                                <th class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Gross Salary (₹)</th>
                                <th class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Deductions (₹)</th>
                                <th class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Net Salary (₹)</th>
                                <th class="py-3 pr-4 text-center text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                                <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @forelse($payslips as $slip)
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors">
                                    <td class="py-3.5 pl-6 pr-4 font-semibold text-zinc-800 dark:text-zinc-200">
                                        {{ $slip->payroll->month }} {{ $slip->payroll->year }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-400">
                                        {{ $slip->payroll->month }} {{ $slip->payroll->year }} – Cycle {{ strtoupper(str_replace('cycle_', '', $slip->payroll->cycle ?? 'a')) }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-right font-medium text-zinc-700 dark:text-zinc-300">₹{{ number_format($slip->gross_salary, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-right text-red-600 font-medium">₹{{ number_format($slip->total_deductions, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-right font-black text-zinc-900 dark:text-white">₹{{ number_format($slip->net_salary, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-center">
                                        @if($slip->status === 'paid')
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">PAID</span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">DRAFT</span>
                                        @endif
                                    </td>
                                    <td class="py-3.5 pr-6 text-right" wire:key="actions-{{ $slip->id }}">
                                        <div class="flex items-center justify-end gap-1">
                                            {{-- View / Open PDF in new tab --}}
                                            <a href="{{ route('payroll.payslips.download', $slip->id) }}" target="_blank"
                                                title="View Payslip PDF"
                                                class="cursor-pointer p-1.5 text-zinc-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 rounded-lg transition-colors">
                                                <flux:icon.eye class="size-4" />
                                            </a>
                                            {{-- Download PDF --}}
                                            <a href="{{ route('payroll.payslips.download', $slip->id) }}" target="_blank"
                                                title="Download PDF"
                                                class="cursor-pointer p-1.5 text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-700 rounded-lg transition-colors">
                                                <flux:icon.arrow-down-tray class="size-4" />
                                            </a>
                                            {{-- Email --}}
                                            <button type="button"
                                                wire:click="emailPayslip({{ $slip->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="emailPayslip({{ $slip->id }})"
                                                title="Email Payslip"
                                                class="cursor-pointer p-1.5 text-zinc-400 hover:text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 rounded-lg transition-colors disabled:opacity-50">
                                                <span wire:loading.remove wire:target="emailPayslip({{ $slip->id }})">
                                                    <flux:icon.envelope class="size-4" />
                                                </span>
                                                <span wire:loading wire:target="emailPayslip({{ $slip->id }})">
                                                    <flux:icon.arrow-path class="size-4 animate-spin" />
                                                </span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-12 text-center text-zinc-400 text-sm">No payslips found</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                    <div class="text-xs text-zinc-500">Showing {{ $payslips->firstItem() ?? 0 }} to {{ $payslips->lastItem() ?? 0 }} of {{ $payslips->total() }} records</div>
                    {{ $payslips->links('vendor.pagination.simple-tailwind') }}
                </div>
            </div>

        </div>

        {{-- RIGHT: Current Payslip Panel ──────────────────────────────────── --}}
        <div class="space-y-5">

            @if($latestPayslip)
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
                    {{-- Panel header --}}
                    <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="font-bold text-zinc-900 dark:text-white text-sm">
                            Current Payslip ({{ $latestPayslip->payroll->month }} {{ $latestPayslip->payroll->year }})
                        </h3>
                        @if($latestPayslip->status === 'paid')
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span> PAID
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700">DRAFT</span>
                        @endif
                    </div>

                    <div class="p-5 space-y-5">
                        {{-- Employee info --}}
                        @php
                            $emp = $latestPayslip->employee;
                            $colors = ['bg-brand-500','bg-violet-500','bg-emerald-500','bg-rose-500','bg-sky-500'];
                            $color = $colors[$emp->id % count($colors)];
                        @endphp
                        <div class="flex items-center gap-3">
                            <div class="size-11 rounded-full {{ $color }} flex items-center justify-center font-bold text-white text-base shrink-0">
                                {{ strtoupper(substr($emp->user?->name ?? 'U', 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white text-sm">{{ $emp->user?->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $emp->employee_id }}</div>
                                <div class="text-xs text-zinc-400">{{ $emp->department?->name ?? '—' }} · {{ $emp->jobTitle?->name ?? '—' }}</div>
                            </div>
                        </div>

                        {{-- Earnings --}}
                        @php
                            $earnings   = $latestPayslip->items->where('type','earning');
                            $deductions = $latestPayslip->items->where('type','deduction');
                        @endphp
                        @if($earnings->isNotEmpty())
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-wide">Earnings</span>
                                    <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($earnings->sum('amount'), 2) }}</span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach($earnings as $item)
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                            <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">₹{{ number_format($item->amount, 2) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Deductions --}}
                        @if($deductions->isNotEmpty())
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-wide">Deductions</span>
                                    <span class="text-sm font-bold text-red-600">- ₹{{ number_format($deductions->sum('amount'), 2) }}</span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach($deductions as $item)
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                            <span class="text-xs font-semibold text-red-600">- ₹{{ number_format($item->amount, 2) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- Net Payable --}}
                        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-xs text-zinc-500">Net Payable</div>
                                    <div class="text-2xl font-black text-brand-600 mt-0.5">₹{{ number_format($latestPayslip->net_salary, 2) }}</div>
                                </div>
                                @if($latestPayslip->status === 'paid')
                                    <div class="text-right">
                                        <div class="flex items-center gap-1 text-emerald-600 text-xs font-semibold">
                                            <flux:icon.check-circle class="size-4" />
                                            Salary Credited
                                        </div>
                                        <div class="text-xs text-zinc-500 mt-0.5">{{ \Carbon\Carbon::parse($latestPayslip->updated_at)->format('M d, Y') }}</div>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Quick Actions --}}
                        <div x-data="{ showStructure: false }">
                            <div class="text-xs font-bold text-zinc-500 uppercase tracking-wide mb-3">Quick Actions</div>
                            <div class="grid grid-cols-4 gap-2">
                                <a href="{{ route('payroll.payslips.download', $latestPayslip->id) }}" target="_blank"
                                    class="flex flex-col items-center gap-1.5 p-2.5 bg-brand-50 dark:bg-brand-900/20 rounded-xl hover:bg-brand-100 transition-colors">
                                    <flux:icon.arrow-down-tray class="size-5 text-brand-600" />
                                    <span class="text-[10px] font-semibold text-brand-700 dark:text-brand-400 text-center leading-tight">Download PDF</span>
                                </a>
                                <button type="button"
                                    wire:click="emailPayslip({{ $latestPayslip->id }})"
                                    wire:loading.attr="disabled" wire:target="emailPayslip({{ $latestPayslip->id }})"
                                    title="Email payslip to your inbox"
                                    class="cursor-pointer flex flex-col items-center gap-1.5 p-2.5 bg-zinc-50 dark:bg-zinc-800 rounded-xl hover:bg-emerald-50 hover:text-emerald-600 transition-colors disabled:opacity-50">
                                    <span wire:loading.remove wire:target="emailPayslip({{ $latestPayslip->id }})">
                                        <flux:icon.envelope class="size-5 text-zinc-600" />
                                    </span>
                                    <span wire:loading wire:target="emailPayslip({{ $latestPayslip->id }})">
                                        <flux:icon.arrow-path class="size-5 text-emerald-500 animate-spin" />
                                    </span>
                                    <span class="text-[10px] font-semibold text-zinc-600 text-center leading-tight">Email Payslip</span>
                                </button>
                                <a href="{{ route('payroll.payslips.download', $latestPayslip->id) }}" target="_blank"
                                    title="Open PDF in new tab"
                                    class="cursor-pointer flex flex-col items-center gap-1.5 p-2.5 bg-zinc-50 dark:bg-zinc-800 rounded-xl hover:bg-violet-50 transition-colors">
                                    <flux:icon.arrow-top-right-on-square class="size-5 text-zinc-600" />
                                    <span class="text-[10px] font-semibold text-zinc-600 text-center leading-tight">Open PDF</span>
                                </a>
                                <button type="button" @click="showStructure = !showStructure"
                                    title="View salary structure breakdown"
                                    :class="showStructure ? 'bg-brand-50 dark:bg-brand-900/20' : 'bg-zinc-50 dark:bg-zinc-800 hover:bg-brand-50'"
                                    class="cursor-pointer flex flex-col items-center gap-1.5 p-2.5 rounded-xl transition-colors">
                                    <flux:icon.chart-pie class="size-5 text-zinc-600" />
                                    <span class="text-[10px] font-semibold text-zinc-600 text-center leading-tight">Salary Structure</span>
                                </button>
                            </div>

                            {{-- Inline Salary Structure (no modal) --}}
                            <div x-show="showStructure" x-transition class="mt-4 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                <div class="flex items-center justify-between px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                    <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Salary Structure</span>
                                    <button @click="showStructure = false" class="text-zinc-400 hover:text-zinc-600 text-lg leading-none font-bold">&times;</button>
                                </div>
                                @php
                                    $struE = $salaryComponents->filter(fn($s) => ($s->component?->type ?? '') === 'earning');
                                    $struD = $salaryComponents->filter(fn($s) => ($s->component?->type ?? '') === 'deduction');
                                @endphp
                                @if($struE->isNotEmpty())
                                    <div class="px-4 pt-3 pb-1">
                                        <div class="text-[10px] font-bold text-emerald-600 uppercase tracking-wide mb-1.5">Earnings</div>
                                        @foreach($struE as $s)
                                            <div class="flex justify-between py-1 text-xs border-b border-zinc-50 dark:border-zinc-800">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ $s->component?->name }}</span>
                                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">₹{{ number_format($s->amount, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if($struD->isNotEmpty())
                                    <div class="px-4 pt-2 pb-1">
                                        <div class="text-[10px] font-bold text-red-500 uppercase tracking-wide mb-1.5">Deductions</div>
                                        @foreach($struD as $s)
                                            <div class="flex justify-between py-1 text-xs border-b border-zinc-50 dark:border-zinc-800">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ $s->component?->name }}</span>
                                                <span class="font-semibold text-red-600">- ₹{{ number_format($s->amount, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="flex justify-between px-4 py-2.5 bg-brand-50 dark:bg-brand-900/20">
                                    <span class="text-xs font-bold text-brand-700 dark:text-brand-400">Net Take-Home</span>
                                    <span class="text-sm font-black text-brand-700 dark:text-brand-400">₹{{ number_format($monthlyNet, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tax Summary --}}
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon.document-text class="size-4 text-violet-500" />
                        <h4 class="text-sm font-bold text-zinc-700 dark:text-zinc-300">Tax Summary (FY {{ now()->year - 1 }}–{{ substr(now()->year, -2) }})</h4>
                    </div>
                    @php
                        $allSlips = \App\Models\Payslip::where('employee_id', $latestPayslip->employee_id)->where('status','paid')->with('items')->get();
                        $ytdGross = $allSlips->sum('gross_salary');
                        $ytdNet = $allSlips->sum('net_salary');
                        $ytdTax = $allSlips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
                        $ytdDeductions = $allSlips->sum('total_deductions');
                    @endphp
                    <div class="space-y-2.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Earnings</span>
                            <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdGross, 0) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Tax Paid</span>
                            <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdTax, 0) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Deductions</span>
                            <span class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdDeductions, 0) }}</span>
                        </div>
                    </div>
                </div>

            @else
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-8 shadow-sm text-center">
                    <flux:icon.document-text class="size-10 text-zinc-300 mx-auto mb-3" />
                    <p class="text-sm font-bold text-zinc-500">No payslip generated yet</p>
                    <p class="text-xs text-zinc-400 mt-1">Your payslip will appear here after payroll is processed</p>
                </div>
            @endif
        </div>

    </div>
                @if($struDeductions->isNotEmpty())
                    <div>
                        <div class="text-xs font-bold text-red-600 uppercase tracking-wide mb-2">Deductions</div>
                        <div class="space-y-2 rounded-xl bg-zinc-50 dark:bg-zinc-900 p-3">
                            @foreach($struDeductions as $s)
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $s->component?->name ?? 'Component' }}</span>
                                    <span class="text-sm font-bold text-red-600">- ₹{{ number_format($s->amount, 2) }}</span>
                                </div>
                            @endforeach
                            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-2 flex justify-between font-bold text-sm">
                                <span class="text-red-600">Total Deductions</span>
                                <span class="text-red-600">- ₹{{ number_format($struDeductions->sum('amount'), 2) }}</span>
                            </div>
                        </div>
                    </div>
                @endif
                <div class="rounded-xl bg-brand-50 dark:bg-brand-900/20 p-4 flex items-center justify-between">
                    <span class="text-sm font-bold text-brand-700 dark:text-brand-400">Net Monthly Take-Home</span>
                    <span class="text-xl font-black text-brand-700 dark:text-brand-400">₹{{ number_format($monthlyNet, 2) }}</span>
                </div>
                <div class="flex justify-end">
                    <button type="button" @click="$wire.set('showSalaryBreakup', false)"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

</flux:main>
