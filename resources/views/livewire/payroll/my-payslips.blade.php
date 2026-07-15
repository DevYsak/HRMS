<flux:main class="min-h-screen bg-[#F7F8FA] dark:bg-zinc-950 p-6 space-y-6">

    {{-- ── Header ──────────────────────────────────────────────────────────── --}}
    <div class="pulse-hero shadow-xl">
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]">
        </div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl"
            style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl"
            style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>

        <div class="relative">
            <div
                class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 backdrop-blur-sm">
                <div class="size-1.5 animate-pulse rounded-full bg-orange-200"></div>
                <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-100">Payroll</span>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-white">My Payslip</h1>
            <p class="mt-1.5 text-sm font-medium text-violet-200/80">View salary details, payslips and compensation
                history.</p>
        </div>
    </div>

    {{-- ── KPI Cards ───────────────────────────────────────────────────────── --}}
    <div class="pluse-margin grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">

        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center shrink-0">
                <flux:icon.banknotes class="size-6 text-brand-600" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Current Monthly Salary</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">
                    ₹{{ number_format($monthlyNet, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">Net payable amount</div>
            </div>
        </div>

        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div
                class="size-12 rounded-xl bg-violet-50 dark:bg-violet-900/20 flex items-center justify-center shrink-0">
                <flux:icon.chart-bar class="size-6 text-violet-600" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Annual CTC</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">
                    ₹{{ number_format($ctc, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">Current package</div>
            </div>
        </div>

        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div
                class="size-12 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
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

        <div
            class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
            <div class="size-12 rounded-xl bg-red-50 dark:bg-red-900/20 flex items-center justify-center shrink-0">
                <flux:icon.arrow-trending-down class="size-6 text-red-500" />
            </div>
            <div class="min-w-0">
                <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Total Deductions</div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white leading-none mt-0.5">
                    ₹{{ number_format($monthlyDeductions, 0) }}</div>
                <div class="text-xs text-zinc-500 mt-1">PF, PT and taxes</div>
            </div>
        </div>
    </div>

    {{-- ── Main two-column layout ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        {{-- LEFT: Trend + Revision + History ──────────────────────────────── --}}
        <div class="xl:col-span-2 space-y-6">

            {{-- Salary Trend Chart --}}
            <div
                class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm">

                @if(count($trendGross) >= 2)
                    @php
                        // ── Chart computation (all in PHP → no SVG namespace issues) ──
                        $n = count($trendGross);
                        $allVals = array_merge($trendGross, $trendNet);
                        $rawMin = min($allVals);
                        $rawMax = max($allVals);
                        $pad = ($rawMax - $rawMin) * 0.15 ?: $rawMax * 0.05;
                        $cMin = max(0, floor(($rawMin - $pad) / 1000) * 1000);
                        $cMax = ceil(($rawMax + $pad) / 1000) * 1000;
                        $cRange = $cMax - $cMin ?: 1;

                        // SVG viewBox: 0 0 600 200 — edge-to-edge, Y labels overlaid inside
                        $svgW = 600;
                        $svgH = 200;
                        $padL = 0;
                        $padR = 0;
                        $padT = 24;
                        $padB = 32;
                        $chartW = $svgW - $padL - $padR;
                        $chartH = $svgH - $padT - $padB;

                        $mapX = fn($i) => $padL + ($n > 1 ? $i / ($n - 1) * $chartW : $chartW / 2);
                        $mapY = fn($v) => $padT + $chartH - (($v - $cMin) / $cRange) * $chartH;

                        // Bezier smooth path
                        $bezierPath = function (array $vals) use ($mapX, $mapY, $n): string {
                            if ($n < 2)
                                return '';
                            $pts = [];
                            foreach ($vals as $i => $v)
                                $pts[] = [$mapX($i), $mapY($v)];
                            $d = "M {$pts[0][0]},{$pts[0][1]}";
                            for ($i = 1; $i < count($pts); $i++) {
                                [$x0, $y0] = $pts[$i - 1];
                                [$x1, $y1] = $pts[$i];
                                $cp = ($x0 + $x1) / 2;
                                $d .= " C {$cp},{$y0} {$cp},{$y1} {$x1},{$y1}";
                            }
                            return $d;
                        };

                        $grossPath = $bezierPath($trendGross);
                        $netPath = $bezierPath($trendNet);

                        // Y-axis ticks (4 levels)
                        $yTicks = [];
                        for ($t = 0; $t <= 3; $t++) {
                            $val = $cMin + ($cRange / 3) * $t;
                            $y = $mapY($val);
                            $lbl = '₹' . (abs($val) >= 1000 ? number_format($val / 1000, 0) . 'K' : number_format($val, 0));
                            $yTicks[] = compact('y', 'lbl');
                        }

                        // Summary stats
                        $latestGross = $trendGross[$n - 1] ?? 0;
                        $latestNet = $trendNet[$n - 1] ?? 0;
                        $firstGross = $trendGross[0] ?? $latestGross;
                        $growthPct = $firstGross > 0 ? round((($latestGross - $firstGross) / $firstGross) * 100, 1) : 0;
                        $fmtK = fn($v) => '₹' . number_format($v / 1000, 1) . 'K';

                        // Point coordinates
                        $gPts = array_map(fn($i, $v) => [$mapX($i), $mapY($v)], array_keys($trendGross), $trendGross);
                        $nPts = array_map(fn($i, $v) => [$mapX($i), $mapY($v)], array_keys($trendNet), $trendNet);
                    @endphp

                    {{-- Summary strip --}}
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h3 class="font-bold text-zinc-900 dark:text-white text-sm">Salary Trend
                                <span class="text-xs font-normal text-zinc-400 ml-1">(Last {{ $n }} Months)</span>
                            </h3>
                        </div>
                        <div class="flex items-center gap-5">
                            <div class="text-right">
                                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Gross</div>
                                <div class="text-sm font-black text-zinc-800 dark:text-zinc-200">
                                    ₹{{ number_format($latestGross, 0) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-wide">Net</div>
                                <div class="text-sm font-black text-emerald-600">₹{{ number_format($latestNet, 0) }}</div>
                            </div>
                            @if($growthPct != 0)
                                <div
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold {{ $growthPct > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-600' }}">
                                    {{ $growthPct > 0 ? '↑' : '↓' }} {{ abs($growthPct) }}%
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- SVG Chart --}}
                    <div x-data="{ tip: null }" class="relative" style="height: 200px;">
                        <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" class="w-full h-full" style="overflow:visible;">

                            {{-- Grid lines + overlaid Y-axis labels --}}
                            @foreach($yTicks as $tick)
                                <line x1="0" y1="{{ $tick['y'] }}" x2="{{ $svgW }}" y2="{{ $tick['y'] }}" stroke="#f3f4f6"
                                    stroke-width="1" />
                                <text x="6" y="{{ $tick['y'] - 3 }}" text-anchor="start" font-size="10" fill="#9ca3af"
                                    font-family="Arial, sans-serif"
                                    style="dominant-baseline: text-after-edge;">{{ $tick['lbl'] }}</text>
                            @endforeach

                            {{-- Subtle gross area fill --}}
                            <path
                                d="{{ $grossPath }} L {{ $mapX($n - 1) }},{{ $padT + $chartH }} L {{ $padL }},{{ $padT + $chartH }} Z"
                                fill="rgba(249,115,22,0.06)" />

                            {{-- Net line (dashed) --}}
                            <path d="{{ $netPath }}" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round"
                                stroke-linejoin="round" stroke-dasharray="6 3" />

                            {{-- Gross line --}}
                            <path d="{{ $grossPath }}" fill="none" stroke="#f97316" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round" />

                            {{-- Net data points --}}
                            @foreach($nPts as $i => [$x, $y])
                                @php $isLast = $i === $n - 1; @endphp
                                <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $isLast ? 6 : 4 }}" fill="#fff" stroke="#10b981"
                                    stroke-width="{{ $isLast ? 2.5 : 2 }}" />
                            @endforeach

                            {{-- Gross data points --}}
                            @foreach($gPts as $i => [$x, $y])
                                @php $isLast = $i === $n - 1; @endphp
                                <circle cx="{{ $x }}" cy="{{ $y }}" r="{{ $isLast ? 7 : 4.5 }}"
                                    fill="{{ $isLast ? '#f97316' : '#fff' }}" stroke="#f97316"
                                    stroke-width="{{ $isLast ? 0 : 2 }}" />
                                @if($isLast)
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="#fff" opacity="0.5" />
                                @endif
                            @endforeach

                            {{-- "Current" badge on last point --}}
                            @php [$lx, $ly] = $gPts[$n - 1]; @endphp
                            <rect x="{{ $lx - 44 }}" y="{{ $ly - 26 }}" width="88" height="18" rx="9" fill="#f97316" />
                            <text x="{{ $lx }}" y="{{ $ly - 14 }}" text-anchor="middle" font-size="10" fill="#fff"
                                font-weight="bold" font-family="Arial, sans-serif">{{ $fmtK($latestNet) }} Net</text>

                            {{-- Hover hit areas + tooltip trigger (Alpine) --}}
                            @foreach($gPts as $i => [$x, $y])
                                @php
                                    $tipData = json_encode([
                                        'label' => $trendLabels[$i] ?? '',
                                        'gross' => $fmtK($trendGross[$i]),
                                        'net' => $fmtK($trendNet[$i] ?? 0),
                                    ]);
                                @endphp
                                <rect x="{{ $x - 28 }}" y="{{ $padT }}" width="56" height="{{ $chartH }}" fill="transparent"
                                    @mouseenter="tip = {{ $tipData }}" @mouseleave="tip = null" />
                            @endforeach

                            {{-- X-axis labels --}}
                            @foreach($trendLabels as $i => $label)
                                <text x="{{ $mapX($i) }}" y="{{ $svgH - 8 }}" text-anchor="middle" font-size="10" fill="#9ca3af"
                                    font-family="Arial, sans-serif">{{ $label }}</text>
                            @endforeach

                        </svg>

                        {{-- Tooltip (Alpine) --}}
                        <div x-show="tip !== null" x-cloak
                            class="absolute pointer-events-none bg-zinc-900 text-white rounded-xl px-3 py-2 text-xs shadow-xl z-10"
                            style="top: 10px; left: 50%; transform: translateX(-50%); min-width: 120px;">
                            <template x-if="tip">
                                <div class="space-y-1">
                                    <div class="font-bold text-zinc-300 mb-1" x-text="tip.label"></div>
                                    <div class="flex justify-between gap-3">
                                        <span class="text-orange-400">Gross</span>
                                        <span class="font-bold" x-text="tip.gross"></span>
                                    </div>
                                    <div class="flex justify-between gap-3">
                                        <span class="text-emerald-400">Net</span>
                                        <span class="font-bold" x-text="tip.net"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Legend --}}
                    <div class="flex items-center gap-5 mt-3">
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-0.5 rounded-full bg-orange-500"></div>
                            <span class="text-xs text-zinc-500">Gross Salary</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-5 h-px bg-emerald-500"
                                style="border-top: 2px dashed #10b981; background:transparent;"></div>
                            <span class="text-xs text-zinc-500">Net Salary</span>
                        </div>
                    </div>

                @else
                    <div class="flex items-center justify-center h-52 text-zinc-400 text-sm">
                        <div class="text-center">
                            <div class="text-2xl mb-2">📈</div>
                            <div>Need at least 2 payslips to show trend</div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Salary Revision History --}}
            @if(count($salaryRevisions) > 0)
                <div
                    class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-6 shadow-sm">
                    <h3 class="font-bold text-zinc-900 dark:text-white mb-5">Salary Revision History</h3>
                    <div class="space-y-4">
                        @foreach($salaryRevisions as $rev)
                            <div class="flex items-start gap-3">
                                <div
                                    class="mt-1 size-3 rounded-full shrink-0
                                                    {{ $rev['color'] === 'emerald' ? 'bg-emerald-500' : ($rev['color'] === 'red' ? 'bg-red-500' : 'bg-blue-500') }}">
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <span
                                            class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $rev['month'] }}</span>
                                        <span
                                            class="text-sm font-bold text-zinc-900 dark:text-white">₹{{ number_format($rev['net'], 0) }}</span>
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
            <div
                class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div
                    class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-4">
                    <div class="flex items-baseline gap-3">
                        <h3 class="font-bold text-zinc-900 dark:text-white">Payslip History</h3>
                        <span class="text-xs text-zinc-400">Tick up to {{ $maxCombined }} months to print together</span>
                    </div>
                    <div class="flex items-center gap-3">
                        @if(count($selected) > 0)
                            <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">
                                {{ count($selected) }} of {{ $maxCombined }} selected
                            </span>
                            <button type="button" wire:click="clearSelection"
                                class="cursor-pointer text-xs font-medium text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors">
                                Clear
                            </button>
                            <button type="button" wire:click="printSelected"
                                class="cursor-pointer inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-colors hover:bg-orange-600">
                                <flux:icon.printer class="size-3.5" />
                                Print {{ count($selected) }} payslip{{ count($selected) === 1 ? '' : 's' }}
                            </button>
                        @endif
                        <x-clean-select model="filterYear" :live="true"
                            :options="array_merge([['value' => '', 'label' => 'All Years']], collect(range(now()->year, now()->year - 3))->map(fn ($y) => ['value' => $y, 'label' => $y])->all())" />
                    </div>
                </div>

                {{-- Period filters --}}
                <div class="px-6 py-3 border-b border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-2">
                    @foreach([
                        'all' => 'All',
                        'last_3' => 'Last 3 Months',
                        'last_6' => 'Last 6 Months',
                        'fy' => 'Financial Year',
                        'month' => 'Month',
                        'custom' => 'Custom Range',
                    ] as $key => $label)
                        <button type="button" wire:click="$set('filterPeriod', '{{ $key }}')"
                            class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors
                                {{ $filterPeriod === $key
                                    ? 'bg-orange-500 text-white shadow-sm'
                                    : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}">
                            {{ $label }}
                        </button>
                    @endforeach

                    @if($filterPeriod === 'fy')
                        <x-clean-select model="filterFy" :live="true"
                            :options="collect(range(now()->year, now()->year - 3))->map(fn ($y) => ['value' => $y, 'label' => 'FY ' . $y . '-' . substr($y + 1, 2)])->all()" />
                    @endif

                    @if($filterPeriod === 'month')
                        <x-clean-select model="filterMonth" :live="true"
                            :options="array_merge([['value' => '', 'label' => 'Select month']], collect(range(1, 12))->map(fn ($m) => ['value' => \Carbon\Carbon::create(null, $m, 1)->format('F'), 'label' => \Carbon\Carbon::create(null, $m, 1)->format('F')])->all())" />
                    @endif

                    @if($filterPeriod === 'custom')
                        <input type="month" wire:model.live="rangeFrom" aria-label="Range from"
                            class="rounded-lg border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        <span class="text-xs text-zinc-400">to</span>
                        <input type="month" wire:model.live="rangeTo" aria-label="Range to"
                            class="rounded-lg border-zinc-200 bg-white px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    @endif

                    @if($filterPeriod !== 'all' || $filterYear)
                        <button type="button" wire:click="resetFilters"
                            class="cursor-pointer ml-auto text-xs font-medium text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200 transition-colors">
                            Reset filters
                        </button>
                    @endif
                </div>

                {{-- Period summary — totals across the whole filtered window --}}
                @if($periodTotals['months'] > 0)
                    <div class="px-6 py-3 bg-zinc-50/60 dark:bg-zinc-950/30 border-b border-zinc-100 dark:border-zinc-800 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Months</div>
                            <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $periodTotals['months'] }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Total Gross</div>
                            <div class="text-sm font-black text-zinc-900 dark:text-white">₹{{ number_format($periodTotals['gross'], 2) }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Total Deductions</div>
                            <div class="text-sm font-black text-red-600">₹{{ number_format($periodTotals['deductions'], 2) }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Total Net</div>
                            <div class="text-sm font-black text-emerald-600">₹{{ number_format($periodTotals['net'], 2) }}</div>
                        </div>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-zinc-50/70 dark:bg-zinc-950/40 border-b border-zinc-100 dark:border-zinc-800">
                                <th class="py-3 pl-6 pr-2 w-8"><span class="sr-only">Select for combined print</span></th>
                                <th
                                    class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Month</th>
                                <th
                                    class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Payroll Cycle</th>
                                <th
                                    class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Gross Salary (₹)</th>
                                <th
                                    class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Deductions (₹)</th>
                                <th
                                    class="py-3 pr-4 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Net Salary (₹)</th>
                                <th
                                    class="py-3 pr-4 text-center text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Status</th>
                                <th
                                    class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                                    Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                            @forelse($payslips as $slip)
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors">
                                    <td class="py-3.5 pl-6 pr-2">
                                        <input type="checkbox" wire:model.live="selected" value="{{ $slip->id }}"
                                            @disabled(!in_array($slip->id, $selected) && count($selected) >= $maxCombined)
                                            aria-label="Select {{ $slip->payroll->month }} {{ $slip->payroll->year }} payslip"
                                            class="size-4 rounded border-zinc-300 text-orange-500 focus:ring-orange-500 disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-800">
                                    </td>
                                    <td class="py-3.5 pr-4 font-semibold text-zinc-800 dark:text-zinc-200">
                                        {{ $slip->payroll->month }} {{ $slip->payroll->year }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-400">
                                        {{ $slip->payroll->month }} {{ $slip->payroll->year }} – Cycle
                                        {{ strtoupper(str_replace('cycle_', '', $slip->payroll->cycle ?? 'a')) }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-right font-medium text-zinc-700 dark:text-zinc-300">
                                        ₹{{ number_format($slip->gross_salary, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-right text-red-600 font-medium">
                                        ₹{{ number_format($slip->total_deductions, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-right font-black text-zinc-900 dark:text-white">
                                        ₹{{ number_format($slip->net_salary, 2) }}</td>
                                    <td class="py-3.5 pr-4 text-center">
                                        @if($slip->status === 'paid')
                                            <span
                                                class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">PAID</span>
                                        @else
                                            <span
                                                class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">DRAFT</span>
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
                                            <button type="button" wire:click="emailPayslip({{ $slip->id }})"
                                                wire:loading.attr="disabled" wire:target="emailPayslip({{ $slip->id }})"
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
                                    <td colspan="8" class="py-12 text-center text-zinc-400 text-sm">No payslips found</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-3 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                    <div class="text-xs text-zinc-500">Showing {{ $payslips->firstItem() ?? 0 }} to
                        {{ $payslips->lastItem() ?? 0 }} of {{ $payslips->total() }} records
                    </div>
                    {{ $payslips->links('vendor.pagination.simple-tailwind') }}
                </div>
            </div>

        </div>

        {{-- RIGHT: Current Payslip Panel ──────────────────────────────────── --}}
        <div class="space-y-5">

            @if($latestPayslip)
                <div
                    class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">
                    {{-- Panel header --}}
                    <div class="flex items-center justify-between px-5 py-4 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="font-bold text-zinc-900 dark:text-white text-sm">
                            Current Payslip ({{ $latestPayslip->payroll->month }} {{ $latestPayslip->payroll->year }})
                        </h3>
                        @if($latestPayslip->status === 'paid')
                            <span
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span> PAID
                            </span>
                        @else
                            <span
                                class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700">DRAFT</span>
                        @endif
                    </div>

                    <div class="p-5 space-y-5">
                        {{-- Employee info --}}
                        @php
                            $emp = $latestPayslip->employee;
                            $colors = ['bg-brand-500', 'bg-violet-500', 'bg-emerald-500', 'bg-rose-500', 'bg-sky-500'];
                            $color = $colors[$emp->id % count($colors)];
                        @endphp
                        <div class="flex items-center gap-3">
                            <div
                                class="size-11 rounded-full {{ $color }} flex items-center justify-center font-bold text-white text-base shrink-0">
                                {{ strtoupper(substr($emp->user?->name ?? 'U', 0, 1)) }}
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white text-sm">{{ $emp->user?->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $emp->employee_id }}</div>
                                <div class="text-xs text-zinc-400">{{ $emp->department?->name ?? '—' }} ·
                                    {{ $emp->jobTitle?->name ?? '—' }}
                                </div>
                            </div>
                        </div>

                        {{-- Earnings --}}
                        @php
                            $earnings = $latestPayslip->items->where('type', 'earning');
                            $deductions = $latestPayslip->items->where('type', 'deduction');
                        @endphp
                        @if($earnings->isNotEmpty())
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold text-zinc-500 uppercase tracking-wide">Earnings</span>
                                    <span
                                        class="text-sm font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($earnings->sum('amount'), 2) }}</span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach($earnings as $item)
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                            <span
                                                class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">₹{{ number_format($item->amount, 2) }}</span>
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
                                    <span class="text-sm font-bold text-red-600">-
                                        ₹{{ number_format($deductions->sum('amount'), 2) }}</span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach($deductions as $item)
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $item->name }}</span>
                                            <span class="text-xs font-semibold text-red-600">-
                                                ₹{{ number_format($item->amount, 2) }}</span>
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
                                    <div class="text-2xl font-black text-brand-600 mt-0.5">
                                        ₹{{ number_format($latestPayslip->net_salary, 2) }}</div>
                                </div>
                                @if($latestPayslip->status === 'paid')
                                    <div class="text-right">
                                        <div class="flex items-center gap-1 text-emerald-600 text-xs font-semibold">
                                            <flux:icon.check-circle class="size-4" />
                                            Salary Credited
                                        </div>
                                        <div class="text-xs text-zinc-500 mt-0.5">
                                            {{ \Carbon\Carbon::parse($latestPayslip->updated_at)->format('M d, Y') }}
                                        </div>
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
                                    <span
                                        class="text-[10px] font-semibold text-brand-700 dark:text-brand-400 text-center leading-tight">Download
                                        PDF</span>
                                </a>
                                <button type="button" wire:click="emailPayslip({{ $latestPayslip->id }})"
                                    wire:loading.attr="disabled" wire:target="emailPayslip({{ $latestPayslip->id }})"
                                    title="Email payslip to your inbox"
                                    class="cursor-pointer flex flex-col items-center gap-1.5 p-2.5 bg-zinc-50 dark:bg-zinc-800 rounded-xl hover:bg-emerald-50 hover:text-emerald-600 transition-colors disabled:opacity-50">
                                    <span wire:loading.remove wire:target="emailPayslip({{ $latestPayslip->id }})">
                                        <flux:icon.envelope class="size-5 text-zinc-600" />
                                    </span>
                                    <span wire:loading wire:target="emailPayslip({{ $latestPayslip->id }})">
                                        <flux:icon.arrow-path class="size-5 text-emerald-500 animate-spin" />
                                    </span>
                                    <span class="text-[10px] font-semibold text-zinc-600 text-center leading-tight">Email
                                        Payslip</span>
                                </button>
                                <button type="button" @click="showStructure = !showStructure"
                                    title="View salary structure breakdown"
                                    :class="showStructure ? 'bg-brand-50 dark:bg-brand-900/20' : 'bg-zinc-50 dark:bg-zinc-800 hover:bg-brand-50'"
                                    class="cursor-pointer flex flex-col items-center gap-1.5 p-2.5 rounded-xl transition-colors">
                                    <flux:icon.chart-pie class="size-5 text-zinc-600" />
                                    <span class="text-[10px] font-semibold text-zinc-600 text-center leading-tight">Salary
                                        Structure</span>
                                </button>
                            </div>

                            {{-- Inline Salary Structure (no modal) --}}
                            <div x-show="showStructure" x-transition
                                class="mt-4 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                <div
                                    class="flex items-center justify-between px-4 py-2.5 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                    <span
                                        class="text-xs font-bold text-zinc-700 dark:text-zinc-300 uppercase tracking-wide">Salary
                                        Structure</span>
                                    <button @click="showStructure = false"
                                        class="text-zinc-400 hover:text-zinc-600 text-lg leading-none font-bold">&times;</button>
                                </div>
                                @php
                                    $struE = $salaryComponents->filter(fn($s) => ($s->component?->type ?? '') === 'earning');
                                    $struD = $salaryComponents->filter(fn($s) => ($s->component?->type ?? '') === 'deduction');
                                @endphp
                                @if($struE->isNotEmpty())
                                    <div class="px-4 pt-3 pb-1">
                                        <div class="text-[10px] font-bold text-emerald-600 uppercase tracking-wide mb-1.5">
                                            Earnings</div>
                                        @foreach($struE as $s)
                                            <div
                                                class="flex justify-between py-1 text-xs border-b border-zinc-50 dark:border-zinc-800">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ $s->component?->name }}</span>
                                                <span
                                                    class="font-semibold text-zinc-800 dark:text-zinc-200">₹{{ number_format($s->amount, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @if($struD->isNotEmpty())
                                    <div class="px-4 pt-2 pb-1">
                                        <div class="text-[10px] font-bold text-red-500 uppercase tracking-wide mb-1.5">
                                            Deductions</div>
                                        @foreach($struD as $s)
                                            <div
                                                class="flex justify-between py-1 text-xs border-b border-zinc-50 dark:border-zinc-800">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ $s->component?->name }}</span>
                                                <span class="font-semibold text-red-600">-
                                                    ₹{{ number_format($s->amount, 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="flex justify-between px-4 py-2.5 bg-brand-50 dark:bg-brand-900/20">
                                    <span class="text-xs font-bold text-brand-700 dark:text-brand-400">Net Take-Home</span>
                                    <span
                                        class="text-sm font-black text-brand-700 dark:text-brand-400">₹{{ number_format($monthlyNet, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Tax Summary --}}
                <div
                    class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm">
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon.document-text class="size-4 text-violet-500" />
                        <h4 class="text-sm font-bold text-zinc-700 dark:text-zinc-300">Tax Summary (FY
                            {{ now()->year - 1 }}–{{ substr(now()->year, -2) }})
                        </h4>
                    </div>
                    @php
                        $allSlips = \App\Models\Payslip::where('employee_id', $latestPayslip->employee_id)->where('status', 'paid')->with('items')->get();
                        $ytdGross = $allSlips->sum('gross_salary');
                        $ytdNet = $allSlips->sum('net_salary');
                        $ytdTax = $allSlips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
                        $ytdDeductions = $allSlips->sum('total_deductions');
                    @endphp
                    <div class="space-y-2.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Earnings</span>
                            <span
                                class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdGross, 0) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Tax Paid</span>
                            <span
                                class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdTax, 0) }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-zinc-500">YTD Deductions</span>
                            <span
                                class="text-xs font-bold text-zinc-800 dark:text-zinc-200">₹{{ number_format($ytdDeductions, 0) }}</span>
                        </div>
                    </div>
                </div>

            @else
                <div
                    class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-8 shadow-sm text-center">
                    <flux:icon.document-text class="size-10 text-zinc-300 mx-auto mb-3" />
                    <p class="text-sm font-bold text-zinc-500">No payslip generated yet</p>
                    <p class="text-xs text-zinc-400 mt-1">Your payslip will appear here after payroll is processed</p>
                </div>
            @endif
        </div>

    </div>

</flux:main>