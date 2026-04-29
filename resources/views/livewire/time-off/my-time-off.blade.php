<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5" x-data="{ tab: 'requests' }">

    {{-- ─── HERO HEADER ─── --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-600 via-purple-600 to-indigo-700 p-7 md:p-8 shadow-xl shadow-violet-500/20">
        {{-- Decorative blobs --}}
        <div class="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-12 -left-12 size-40 rounded-full bg-indigo-400/20 blur-3xl"></div>
        {{-- Dot grid overlay --}}
        <div class="pointer-events-none absolute inset-0 opacity-[0.07]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 backdrop-blur-sm">
                    <div class="size-1.5 animate-pulse rounded-full bg-violet-200"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-violet-100">Leave Management</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">My Time Off</h1>
                <p class="mt-1.5 text-sm font-medium text-violet-200/80">Manage balances, apply for leave & track encashments.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                @if($encashableTypes->isNotEmpty())
                    <button @click="$flux.modal('encashment-modal').show()"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/25 bg-white/15 px-4 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-all hover:bg-white/25">
                        <flux:icon.banknotes class="size-4 shrink-0" />
                        <span>Encash Leave</span>
                    </button>
                @endif
                <button wire:click="openRequestModal"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-violet-700 shadow-lg shadow-black/20 transition-all hover:bg-violet-50">
                    <flux:icon.plus class="size-4 shrink-0" />
                    <span>Apply for Leave</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ─── PENDING ALERT ─── --}}
    @if(!empty($pendingCount) && $pendingCount > 0)
        <div class="flex items-center gap-4 rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 p-4 dark:border-amber-800/40 dark:from-amber-950/30 dark:to-orange-950/20">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-900/40">
                <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-amber-900 dark:text-amber-200">
                    {{ $pendingCount }} pending leave {{ Str::plural('request', $pendingCount) }} awaiting approval
                </p>
                <p class="mt-0.5 text-xs text-amber-700/70 dark:text-amber-400/70">
                    Your applications are under review. You'll be notified once actioned.
                </p>
            </div>
            <button wire:click="openRequestModal"
                class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-amber-600">
                <flux:icon.plus class="size-3" />
                New Request
            </button>
        </div>
    @endif

    {{-- ─── LEAVE BALANCE CARDS ─── --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @forelse($balances as $balance)
            @php
                $remaining = (float)($balance->allocated_days - $balance->used_days - $balance->encashed_days);
                $percentage = ($balance->allocated_days > 0)
                    ? (($balance->used_days + $balance->encashed_days) / $balance->allocated_days) * 100
                    : 0;
                $pct = min(100, $percentage);
                $radius = 20;
                $circumference = 2 * M_PI * $radius;
                $strokeOffset = $circumference * (1 - $pct / 100);
                $icon = match(strtolower($balance->leaveType->name)) {
                    'casual leave', 'cl' => 'sun',
                    'sick leave', 'sl' => 'heart',
                    'earned leave', 'el' => 'briefcase',
                    'compensatory off', 'comp off' => 'gift',
                    default => 'calendar-days'
                };
            @endphp
            <div class="group relative overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                {{-- Colored top accent bar --}}
                <div class="h-1 w-full" style="background: linear-gradient(90deg, {{ $balance->leaveType->color }}, {{ $balance->leaveType->color }}80)"></div>

                <div class="p-5">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="text-[10px] font-black uppercase tracking-[0.15em] text-zinc-400 dark:text-zinc-500">
                                {{ $balance->leaveType->name }}
                            </span>
                            <div class="mt-1 flex items-baseline gap-1">
                                <span class="text-3xl font-black tabular-nums text-zinc-900 dark:text-white">{{ (float)$remaining }}</span>
                                <span class="text-xs font-bold uppercase text-zinc-400">days left</span>
                            </div>
                        </div>

                        {{-- SVG Ring Progress --}}
                        <div class="relative shrink-0">
                            <svg width="56" height="56" class="-rotate-90">
                                <circle cx="28" cy="28" r="{{ $radius }}" fill="none"
                                    class="stroke-zinc-100 dark:stroke-zinc-800"
                                    stroke-width="4"/>
                                <circle cx="28" cy="28" r="{{ $radius }}" fill="none"
                                    stroke="{{ $balance->leaveType->color }}"
                                    stroke-width="4"
                                    stroke-dasharray="{{ number_format($circumference, 4) }}"
                                    stroke-dashoffset="{{ number_format($strokeOffset, 4) }}"
                                    stroke-linecap="round"
                                    style="transition: stroke-dashoffset 0.6s ease"/>
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <flux:icon :name="$icon" class="size-4 text-zinc-400 dark:text-zinc-500" />
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-between text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                        <span>Used <span class="text-zinc-600 dark:text-zinc-300">{{ (float)$balance->used_days }}</span></span>
                        <span>Total <span class="text-zinc-600 dark:text-zinc-300">{{ (float)$balance->allocated_days }}</span></span>
                    </div>

                    @if($balance->encashed_days > 0)
                        <div class="mt-3 flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1.5 text-[10px] font-bold text-amber-600 dark:bg-amber-900/20 dark:text-amber-400">
                            <flux:icon.banknotes class="size-3 shrink-0" />
                            <span>{{ (float)$balance->encashed_days }} days encashed</span>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-zinc-200 p-10 text-center dark:border-zinc-800 sm:col-span-2 lg:col-span-4">
                <flux:icon.calendar-days class="mx-auto mb-3 size-10 text-zinc-200 dark:text-zinc-700" />
                <p class="text-sm font-medium text-zinc-400">No leave balances found. Please contact HR.</p>
            </div>
        @endforelse
    </div>

    {{-- ─── ANALYTICS SECTION ─── --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- Weekly Pattern --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.calendar class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Weekly Pattern</h3>
            </div>
            @php $dayLabels = ['M','T','W','T','F','S','S']; $dayFull = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']; @endphp
            <div class="flex h-20 items-end gap-1.5">
                @foreach($dayLabels as $i => $d)
                    <div class="flex flex-1 flex-col items-center gap-1.5">
                        <div class="w-full rounded-lg transition-all duration-500"
                            style="height: {{ $weeklyPattern[$i] ? '100%' : '28%' }}; background-color: {{ $weeklyPattern[$i] ? '#7c3aed' : '#f4f4f5' }};"
                            title="{{ $dayFull[$i] }}">
                        </div>
                        <span class="text-[9px] font-bold uppercase text-zinc-400">{{ $d }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-[11px] leading-relaxed text-zinc-400">Weekdays you've taken leave this year.</p>
        </div>

        {{-- CSL Donut --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.chart-pie class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Leave Distribution</h3>
            </div>
            <div class="flex items-center gap-5">
                <div class="relative h-32 w-32 shrink-0">
                    <canvas id="cslDonut" class="absolute inset-0"></canvas>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-xl font-black text-zinc-900 dark:text-white">{{ $cslData['used'] }}</span>
                        <span class="text-[9px] font-bold uppercase text-zinc-400">used</span>
                    </div>
                </div>
                <div class="space-y-2.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $cslData['label'] }}</span>
                    <div class="flex items-center gap-2">
                        <div class="size-2 rounded-full" style="background: {{ $cslData['color'] }}"></div>
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">{{ $cslData['used'] }} days used</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="size-2 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                        <span class="text-xs text-zinc-400">{{ $cslData['remaining'] }} remaining</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Monthly Trend --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center gap-2">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.chart-bar class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <h3 class="text-xs font-black uppercase tracking-[0.12em] text-zinc-600 dark:text-zinc-300">Monthly Trend</h3>
            </div>
            <div class="h-28 w-full">
                <canvas id="monthlyBar"></canvas>
            </div>
        </div>
    </div>

    {{-- ─── HISTORY SECTION ─── --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        {{-- Tab Nav --}}
        <div class="flex items-center gap-1 border-b border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-950/30">
            <button
                @click="tab = 'requests'"
                :class="tab === 'requests' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.document-text class="size-3.5" />
                Leave Applications
            </button>
            <button
                @click="tab = 'encashments'"
                :class="tab === 'encashments' ? 'bg-white dark:bg-zinc-800 text-zinc-900 dark:text-white shadow-sm' : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                class="flex items-center gap-2 rounded-xl px-5 py-2 text-sm font-bold transition-all">
                <flux:icon.banknotes class="size-3.5" />
                Encashment History
            </button>
        </div>

        {{-- Leave Requests Tab --}}
        <div x-show="tab === 'requests'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Leave Type</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Period</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Days</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Reviewer</th>
                            <th class="py-3 pr-6 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Applied</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($requests as $req)
                            <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full shadow-sm" style="background-color: {{ $req->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $req->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                        <span class="font-semibold">{{ $req->start_date->format('d M') }}</span>
                                        <flux:icon.arrow-long-right class="inline size-3 opacity-30" />
                                        <span class="font-semibold">{{ $req->end_date->format('d M, Y') }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-50 text-sm font-black text-zinc-900 dark:bg-zinc-800 dark:text-white">
                                        {{ (float)$req->days }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @php
                                        [$statusClass, $statusIcon] = match($req->status) {
                                            'approved' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle'],
                                            'pending'  => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock'],
                                            default    => ['bg-zinc-50 text-zinc-700', 'question-mark-circle'],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $statusClass }}">
                                        <flux:icon :name="$statusIcon" class="size-3" />
                                        {{ $req->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @if($req->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $req->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500">{{ $req->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                                <td class="py-4 pr-6 text-right">
                                    <span class="text-xs font-medium text-zinc-400">{{ $req->created_at->format('d M Y') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                            <flux:icon.document-magnifying-glass class="size-7 text-zinc-300 dark:text-zinc-600" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-zinc-400">No leave applications yet</p>
                                            <p class="mt-0.5 text-xs text-zinc-300 dark:text-zinc-600">Click "Apply for Leave" to get started.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($requests->hasPages())
                <div class="border-t border-zinc-50 px-6 py-4 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>

        {{-- Encashments Tab --}}
        <div x-show="tab === 'encashments'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-1">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/50">
                            <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Leave Type</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Days</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Payout Cycle</th>
                            <th class="py-3 pr-6 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                        @forelse($encashments as $enc)
                            <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-2.5 rounded-full" style="background-color: {{ $enc->leaveType->color }}"></div>
                                        <span class="text-sm font-bold text-zinc-800 dark:text-zinc-200">{{ $enc->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex size-7 items-center justify-center rounded-lg bg-zinc-50 text-sm font-black text-zinc-900 dark:bg-zinc-800 dark:text-white">
                                        {{ (float)$enc->requested_days }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    @php
                                        [$encStatusClass, $encStatusIcon] = match($enc->status) {
                                            'approved', 'processed' => ['bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400', 'check-circle'],
                                            'rejected' => ['bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400', 'x-circle'],
                                            'pending'  => ['bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400', 'clock'],
                                            default    => ['bg-zinc-50 text-zinc-700', 'question-mark-circle'],
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $encStatusClass }}">
                                        <flux:icon :name="$encStatusIcon" class="size-3" />
                                        {{ $enc->status }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <flux:icon.calendar class="size-3" />
                                        {{ $enc->payout_month ? Carbon::parse($enc->payout_month)->format('M Y') : '--' }}
                                    </div>
                                </td>
                                <td class="py-4 pr-6">
                                    @if($enc->reviewer)
                                        <div class="flex items-center gap-2">
                                            <div class="flex size-7 items-center justify-center rounded-full bg-gradient-to-br from-violet-400 to-indigo-500 text-[9px] font-black uppercase text-white shadow-sm">
                                                {{ collect(explode(' ', $enc->reviewer->name))->map(fn($n) => $n[0])->take(2)->join('') }}
                                            </div>
                                            <span class="text-xs font-medium text-zinc-500">{{ $enc->reviewer->name }}</span>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-zinc-400">
                                            <div class="size-1.5 animate-pulse rounded-full bg-amber-400"></div>
                                            Awaiting
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-16 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                            <flux:icon.banknotes class="size-7 text-zinc-300 dark:text-zinc-600" />
                                        </div>
                                        <p class="text-sm font-bold text-zinc-400">No encashment requests yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ─── REQUEST LEAVE MODAL ─── --}}
    <flux:modal wire:model="showRequestModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-violet-50 p-2.5 dark:bg-violet-900/20">
                    <flux:icon.calendar-days class="size-5 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <flux:heading size="lg">Request Time Off</flux:heading>
                    <flux:subheading>Submit a new leave application for review.</flux:subheading>
                </div>
            </div>

            <form wire:submit="submitRequest" class="space-y-5">
                <flux:select wire:model="leave_type_id" label="Leave Type" placeholder="Select type..." required>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'Paid' : 'Unpaid' }})</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>

                <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                    <flux:checkbox wire:model.live="is_half_day" id="half_day_toggle" />
                    <div>
                        <label for="half_day_toggle" class="cursor-pointer text-sm font-semibold text-zinc-900 dark:text-zinc-100">Half-Day Leave</label>
                        <p class="mt-0.5 text-xs text-zinc-500">Deducts 0.5 days. Only valid when start and end date are the same.</p>
                    </div>
                </div>

                <flux:textarea wire:model="reason" label="Reason" placeholder="Please describe why you need time off..." rows="3" required />

                <div class="flex justify-end gap-3 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Submit Request</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- ─── ENCASHMENT MODAL ─── --}}
    <flux:modal name="encashment-modal" class="max-w-md">
        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-amber-50 p-2.5 dark:bg-amber-900/20">
                    <flux:icon.banknotes class="size-5 text-amber-600 dark:text-amber-400" />
                </div>
                <div>
                    <flux:heading size="lg">Encash Leave Balance</flux:heading>
                    <flux:subheading>Convert unused paid leave into salary payout.</flux:subheading>
                </div>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="encash_leave_type_id" label="Select Leave Type" required>
                    @foreach($encashableTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="encash_days" label="Days to Encash" type="number" step="0.5" suffix="Days" required />

                <div class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-800/30 dark:bg-amber-900/10">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
                        Only CSL leaves are eligible for encashment per company policy. Requests require HR &amp; Finance approval.
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button @click="$flux.modal('encashment-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitEncashment" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Chart.js --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('livewire:load', function () {
            try {
                const donutEl = document.getElementById('cslDonut');
                if (donutEl) {
                    new Chart(donutEl.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: ['Used', 'Remaining'],
                            datasets: [{
                                data: [parseFloat(@json($cslData['used'])), parseFloat(@json($cslData['remaining']))],
                                backgroundColor: [@json($cslData['color']), '#f4f4f5'],
                                borderWidth: 0,
                                borderRadius: 4,
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            cutout: '72%',
                            plugins: { legend: { display: false } }
                        }
                    });
                }

                const barEl = document.getElementById('monthlyBar');
                if (barEl) {
                    new Chart(barEl.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: ['J','F','M','A','M','J','J','A','S','O','N','D'],
                            datasets: [{
                                label: 'Days off',
                                data: @json($monthlyStats),
                                backgroundColor: 'rgba(124,58,237,0.12)',
                                borderColor: '#7c3aed',
                                borderWidth: 2,
                                borderRadius: 5,
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            scales: {
                                x: { grid: { display: false }, border: { display: false }, ticks: { font: { size: 9 }, color: '#a1a1aa' } },
                                y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 9 }, color: '#a1a1aa' }, grid: { color: 'rgba(0,0,0,0.04)' }, border: { display: false } }
                            },
                            plugins: { legend: { display: false } }
                        }
                    });
                }
            } catch (e) {
                console.error('Chart init error', e);
            }
        });
    </script>

</flux:main>
