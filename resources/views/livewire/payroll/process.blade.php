<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    {{-- ── Page Header ─────────────────────────────────────────────────────── --}}
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Run Payroll</h1>
            <p class="pulse-page-subtitle">Generate and verify monthly salary payments</p>
        </div>

        <div class="flex gap-3 items-center">
            {{-- Month / Year selectors (shown when no locked payroll) --}}
            @if(!$currentPayroll || $currentPayroll->status === 'draft')
                <div class="flex gap-2">
                    <flux:select wire:model.live="month" size="sm" class="w-32">
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="year" size="sm" class="w-24">
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                    </flux:select>
                </div>
                <flux:button wire:click="startProcessing" variant="primary" icon="play">
                    {{ $currentPayroll ? 'Re-generate Draft' : 'Generate Draft' }}
                </flux:button>
            @else
                {{-- Export + Finalize when payroll exists --}}
                <flux:button wire:click="exportExcel" variant="ghost" icon="arrow-down-tray">
                    Export Excel
                </flux:button>

                @if($currentPayroll->status === 'draft')
                    <flux:button wire:click="submitForApproval" class="bg-amber-500 hover:bg-amber-600 text-white border-amber-500" icon="paper-airplane">
                        Submit for Approval
                    </flux:button>
                @endif

                @if(in_array($currentPayroll->status, ['pending_finance']))
                    <flux:button
                        wire:click="finalize"
                        wire:confirm="Finalize payroll? This will mark all payslips as paid and notify employees."
                        variant="primary"
                        icon="play">
                        Finalize &amp; Pay
                    </flux:button>
                @endif
            @endif
        </div>
    </div>

    @if($currentPayroll)

        {{-- ── Stats Cards ─────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">

            {{-- Gross Payout --}}
            <div class="pulse-card flex items-center gap-4">
                <div class="flex-shrink-0 size-14 rounded-2xl bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center">
                    <flux:icon.banknotes class="size-7 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-zinc-400 uppercase tracking-widest">Gross Payout</p>
                    <p class="text-2xl font-black text-zinc-900 dark:text-white leading-tight">
                        ₹{{ number_format($currentPayroll->total_payout, 2) }}
                    </p>
                    <p class="text-xs text-zinc-400 mt-0.5">Total draft amount</p>
                </div>
            </div>

            {{-- Employees --}}
            <div class="pulse-card flex items-center gap-4">
                <div class="flex-shrink-0 size-14 rounded-2xl bg-teal-100 dark:bg-teal-900/30 flex items-center justify-center">
                    <flux:icon.users class="size-7 text-teal-600 dark:text-teal-400" />
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-zinc-400 uppercase tracking-widest">Employees</p>
                    <p class="text-2xl font-black text-zinc-900 dark:text-white leading-tight">
                        {{ $currentPayroll->payslips_count ?? $currentPayroll->payslips->count() }}
                    </p>
                    <p class="text-xs text-zinc-400 mt-0.5">Employees in this run</p>
                </div>
            </div>

            {{-- Status --}}
            <div class="pulse-card flex items-center gap-4">
                <div class="flex-shrink-0 size-14 rounded-2xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                    <flux:icon.clock class="size-7 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-zinc-400 uppercase tracking-widest">Status</p>
                    <div class="mt-1">
                        @php
                            $statusClass = match($currentPayroll->status) {
                                'finalized'       => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                'pending_finance' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                default           => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                            };
                            $statusLabel = match($currentPayroll->status) {
                                'finalized'       => 'Finalized',
                                'pending_finance' => 'Pending Finance',
                                default           => 'Draft',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                    <p class="text-xs text-zinc-400 mt-1">
                        {{ $currentPayroll->status === 'pending_finance' ? 'Awaiting approval' : ($currentPayroll->status === 'finalized' ? 'Payment complete' : 'In progress') }}
                    </p>
                </div>
            </div>

            {{-- Payroll Period --}}
            <div class="pulse-card flex items-center gap-4">
                <div class="flex-shrink-0 size-14 rounded-2xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                    <flux:icon.calendar-days class="size-7 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-zinc-400 uppercase tracking-widest">Payroll Period</p>
                    <p class="text-xl font-black text-zinc-900 dark:text-white leading-tight">
                        {{ $currentPayroll->month }} {{ $currentPayroll->year }}
                    </p>
                    <p class="text-xs text-zinc-400 mt-0.5">
                        01 {{ substr($currentPayroll->month, 0, 3) }} - {{ \Carbon\Carbon::parse("1 {$currentPayroll->month} {$currentPayroll->year}")->endOfMonth()->format('d M') }}, {{ $currentPayroll->year }}
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Filters ──────────────────────────────────────────────────────── --}}
        <div class="pulse-card space-y-4">
            <div class="flex flex-wrap gap-3 items-end">

                {{-- Search --}}
                <div class="flex-1 min-w-48">
                    <flux:input wire:model.live.debounce.300ms="search"
                        placeholder="Search employee..."
                        icon="magnifying-glass"
                        size="sm" />
                </div>

                {{-- Department --}}
                <div class="min-w-44">
                    <flux:select wire:model.live="filterDepartment" size="sm">
                        <option value="">All Departments</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                {{-- Location --}}
                <div class="min-w-40">
                    <flux:select wire:model.live="filterLocation" size="sm">
                        <option value="">All Locations</option>
                        @foreach($offices as $office)
                            <option value="{{ $office->id }}">{{ $office->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                {{-- Payment Type --}}
                <div class="min-w-36">
                    <flux:select wire:model.live="filterPaymentType" size="sm">
                        <option value="">All</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="cheque">Cheque</option>
                    </flux:select>
                </div>

                {{-- Status --}}
                <div class="min-w-36">
                    <flux:select wire:model.live="filterStatus" size="sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="pending_finance">Pending Finance</option>
                        <option value="paid">Paid</option>
                    </flux:select>
                </div>

                {{-- Month --}}
                <div class="min-w-36">
                    <flux:select wire:model.live="month" size="sm">
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                        @endforeach
                    </flux:select>
                </div>

                {{-- Year --}}
                <div class="min-w-28">
                    <flux:select wire:model.live="year" size="sm">
                        @foreach(range(now()->year, now()->year - 3) as $y)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="flex gap-3">
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="arrow-path">
                    Clear Filters
                </flux:button>
                <flux:button wire:click="applyFilters" size="sm" variant="primary">
                    Apply Filters
                </flux:button>
            </div>
        </div>

        {{-- ── Payslips Table ───────────────────────────────────────────────── --}}
        <div class="pulse-card overflow-hidden p-0">
            {{-- Table header row --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <h3 class="font-bold text-zinc-900 dark:text-white">
                        {{ ucfirst(str_replace('_', ' ', $currentPayroll->status ?? 'Draft')) }} Payslips
                    </h3>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-brand-50 text-brand-700 dark:bg-brand-900/20 dark:text-brand-400">
                        {{ $payslips->total() }}
                    </span>
                </div>
                <button type="button" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">
                    <flux:icon.chart-bar class="size-4" />
                    View Summary
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-50/80 dark:bg-zinc-900/60 border-b border-zinc-100 dark:border-zinc-800">
                            <th class="py-3 pl-5 pr-3 w-10">
                                <input type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600 text-brand-600 focus:ring-brand-500" />
                            </th>
                            <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                            <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Department</th>
                            <th class="py-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Gross (₹)</th>
                            <th class="py-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Deductions (₹)</th>
                            <th class="py-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Net Payable (₹)</th>
                            <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                            <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @forelse($payslips as $slip)
                            @php
                                $name    = $slip->employee->user?->name ?? '—';
                                $empCode = $slip->employee->employee_id ?? '—';
                                $dept    = $slip->employee->department?->name ?? '—';
                                $initials = collect(explode(' ', $name))->take(2)->map(fn($w) => strtoupper($w[0] ?? ''))->implode('');
                                $colors  = ['bg-violet-500','bg-teal-500','bg-rose-500','bg-amber-500','bg-blue-500','bg-emerald-500','bg-indigo-500','bg-pink-500'];
                                $colorClass = $colors[crc32($empCode) % count($colors)];
                                $slipStatus = $slip->payroll->status ?? $slip->status;
                                $rowStatusClass = match($slipStatus) {
                                    'pending_finance' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                    'finalized', 'paid' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    default             => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                };
                                $rowStatusLabel = match($slipStatus) {
                                    'pending_finance' => 'Pending Finance',
                                    'finalized'       => 'Finalized',
                                    'paid'            => 'Paid',
                                    default           => 'Draft',
                                };
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-3.5 pl-5 pr-3 w-10">
                                    <input type="checkbox" class="rounded border-zinc-300 dark:border-zinc-600 text-brand-600 focus:ring-brand-500" />
                                </td>

                                {{-- Employee --}}
                                <td class="py-3.5 pr-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-full {{ $colorClass }} flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            {{ $initials }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-zinc-900 dark:text-white text-sm">{{ $name }}</div>
                                            <div class="text-xs text-zinc-400">{{ $empCode }}</div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Department --}}
                                <td class="py-3.5 pr-4 text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ $dept }}
                                </td>

                                {{-- Gross --}}
                                <td class="py-3.5 pr-4 text-right font-medium text-zinc-700 dark:text-zinc-300">
                                    ₹{{ number_format($slip->gross_salary, 2) }}
                                </td>

                                {{-- Deductions --}}
                                <td class="py-3.5 pr-4 text-right font-medium text-red-500">
                                    ₹{{ number_format($slip->total_deductions, 2) }}
                                </td>

                                {{-- Net Payable --}}
                                <td class="py-3.5 pr-4 text-right font-bold text-zinc-900 dark:text-white">
                                    ₹{{ number_format($slip->net_salary, 2) }}
                                </td>

                                {{-- Status --}}
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $rowStatusClass }}">
                                        {{ $rowStatusLabel }}
                                    </span>
                                </td>

                                {{-- Actions --}}
                                <td class="py-3.5 pr-6 text-right" wire:click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('payroll.payslips.download', $slip->id) }}" target="_blank"
                                            class="inline-flex items-center justify-center size-8 rounded-lg text-zinc-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition-colors"
                                            title="Download PDF">
                                            <flux:icon.eye class="size-4" />
                                        </a>
                                        <a href="{{ route('payroll.payslips.download', $slip->id) }}" target="_blank"
                                            class="inline-flex items-center justify-center size-8 rounded-lg text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors"
                                            title="Download PDF">
                                            <flux:icon.arrow-down-tray class="size-4" />
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-16 text-center text-zinc-400 text-sm">
                                    No payslips match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($payslips->hasPages() || $payslips->total() > 0)
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-sm text-zinc-500">
                    <span>
                        Showing {{ $payslips->firstItem() ?? 0 }} to {{ $payslips->lastItem() ?? 0 }}
                        of {{ $payslips->total() }} employees
                    </span>
                    <div class="flex items-center gap-3">
                        {{ $payslips->links('vendor.pagination.simple-tailwind') }}
                        <flux:select wire:model.live="perPage" size="sm" class="w-28">
                            <option value="10">10 / page</option>
                            <option value="25">25 / page</option>
                            <option value="50">50 / page</option>
                        </flux:select>
                    </div>
                </div>
            @endif
        </div>

    @else

        {{-- ── Empty State ──────────────────────────────────────────────────── --}}
        <div class="pulse-card py-24 flex flex-col items-center justify-center text-center">
            <div class="size-20 bg-brand-50 rounded-full flex items-center justify-center mb-6 dark:bg-brand-900/20">
                <flux:icon.banknotes class="size-10 text-brand-600" />
            </div>
            <h2 class="text-xl font-black text-zinc-900 dark:text-white italic">
                Ready to process {{ $month }}'s Payroll?
            </h2>
            <p class="text-sm text-zinc-500 max-w-md mt-2">
                Generating the draft will calculate earnings and deductions for all active employees
                based on their latest salary profiles.
            </p>
            <div class="mt-8 flex gap-3">
                <div class="flex gap-2">
                    <flux:select wire:model.live="month" size="sm" class="w-36">
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $m)
                            <option value="{{ $m }}">{{ $m }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="year" size="sm" class="w-24">
                        <option value="2026">2026</option>
                        <option value="2025">2025</option>
                    </flux:select>
                </div>
                <flux:button wire:click="startProcessing" variant="primary" icon="play">
                    Start Current Month Payroll
                </flux:button>
            </div>
        </div>

    @endif

</flux:main>
