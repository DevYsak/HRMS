<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950">

    {{-- ── PREMIUM HEADER ── --}}
    <div class="relative overflow-hidden bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900 px-6 md:px-10 py-8">
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(99,102,241,0.18),_transparent_60%)]"></div>
        <div class="absolute bottom-0 left-0 w-64 h-48 bg-indigo-500/8 rounded-full blur-3xl pointer-events-none"></div>
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-5">
            <div>
                <div class="flex items-center gap-2.5 mb-3">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 border border-white/10 rounded-full">
                        <flux:icon.calendar-days class="size-3 text-indigo-300" />
                        <span class="text-[11px] font-semibold text-white/70">Attendance</span>
                    </div>
                    <span class="text-white/40 text-xs">{{ now()->format('l, d F Y') }}</span>
                </div>
                <h1 class="text-3xl font-black text-white tracking-tight">Employee Attendance</h1>
                <p class="text-white/55 text-sm mt-1.5">View, audit and manage attendance across the organisation.</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <a href="{{ route('reports.attendance-summary', ['month' => now()->month, 'year' => now()->year]) }}"
                   class="flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white rounded-xl text-sm font-semibold transition-all"
                   target="_blank">
                    <flux:icon.arrow-down-tray class="size-4" /> Export CSV
                </a>
                <button wire:click="openMarkModal"
                    class="flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 border border-indigo-500/50 text-white rounded-xl text-sm font-semibold transition-all shadow-lg shadow-indigo-900/30">
                    <flux:icon.pencil-square class="size-4" /> Mark Attendance
                </button>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:input wire:model.live.debounce.300ms="search"
                    placeholder="Search employee name or ID..."
                    icon="magnifying-glass" size="sm" class="w-64" />
                <flux:input wire:model.live="date" type="date" size="sm" class="w-44" />
                <flux:select wire:model.live="status" placeholder="All Status" size="sm" class="w-40">
                    <option value="">All Status</option>
                    <option value="on_time">On Time</option>
                    <option value="late">Late</option>
                    <option value="remote">Remote</option>
                    <option value="absent">Absent</option>
                </flux:select>
                @if($search || $date || $status)
                    <button wire:click="$set('search','');$set('date','');$set('status','')"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-700 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 rounded-lg transition">
                        <flux:icon.x-mark class="size-3.5" /> Clear
                    </button>
                @endif
                <div class="ml-auto text-xs text-zinc-400">
                    {{ $attendances->total() }} records
                </div>
            </div>
        </div>

        {{-- ── PENDING REGULARISATIONS ── --}}
        @if($pendingRegularisations->isNotEmpty())
            <div class="bg-white dark:bg-zinc-900 border border-amber-200 dark:border-amber-800/50 rounded-2xl overflow-hidden">
                <div class="flex items-center gap-3 px-6 py-4 border-b border-amber-100 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20">
                    <div class="flex size-8 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-900/30 shrink-0">
                        <flux:icon.clock class="size-4 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Pending Regularisation Requests</h3>
                        <p class="text-xs text-zinc-500 mt-0.5">{{ $pendingRegularisations->count() }} request{{ $pendingRegularisations->count() > 1 ? 's' : '' }} awaiting review</p>
                    </div>
                    <span class="ml-auto px-2.5 py-1 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-xs font-black rounded-full">
                        {{ $pendingRegularisations->count() }} PENDING
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-950/30">
                                <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Employee</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Date</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Requested Time</th>
                                <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Reason</th>
                                <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @foreach($pendingRegularisations as $req)
                                <tr class="hover:bg-amber-50/30 dark:hover:bg-amber-950/10 transition-colors">
                                    <td class="py-3.5 pl-6 pr-4">
                                        <div class="flex items-center gap-2.5">
                                            <div class="size-7 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                                                <span class="text-[10px] font-black text-amber-700 dark:text-amber-400">
                                                    {{ collect(explode(' ', $req->employee->user->name))->map(fn($n) => $n[0] ?? '')->take(2)->join('') }}
                                                </span>
                                            </div>
                                            <span class="font-semibold text-zinc-900 dark:text-white text-xs">{{ $req->employee->user->name }}</span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 pr-4 text-xs text-zinc-600 dark:text-zinc-300">
                                        {{ \Carbon\Carbon::parse($req->work_date)->format('d M Y') }}
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-amber-50 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-800/50 rounded-lg text-xs font-bold text-amber-700 dark:text-amber-400 font-mono">
                                            {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }}
                                            <span class="text-zinc-400">→</span>
                                            {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 pr-4 text-xs text-zinc-500 max-w-xs truncate" title="{{ $req->reason }}">
                                        {{ $req->reason }}
                                    </td>
                                    <td class="py-3.5 pr-6 text-right">
                                        <button wire:click="openReviewModal({{ $req->id }})"
                                            class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-lg transition">
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── MAIN ATTENDANCE TABLE ── --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-indigo-500" />
                    Attendance Records
                </h3>
                <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">
                    Page {{ $attendances->currentPage() }} of {{ $attendances->lastPage() }}
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-950/40">
                            <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Employee</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Date</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Office</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Clock In</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Clock Out</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                            <th class="py-3 pr-6 text-right text-[10px] font-bold uppercase tracking-widest text-zinc-400">Hours</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($attendances as $log)
                            @php
                                $initials = collect(explode(' ', $log->employee->user->name))
                                    ->map(fn($n) => $n[0] ?? '')->take(2)->join('');
                                $avatarColor = match($log->status) {
                                    'on_time' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    'late'    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                    'absent'  => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400',
                                    'remote'  => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    default   => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                };
                                $statusBadge = match($log->status) {
                                    'on_time' => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/50',
                                    'late'    => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/50',
                                    'absent'  => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/30 dark:text-rose-400 dark:border-rose-800/50',
                                    'remote'  => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:text-blue-400 dark:border-blue-800/50',
                                    default   => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700',
                                };
                                $statusLabel = match($log->status) {
                                    'on_time' => 'On Time',
                                    'late'    => 'Late',
                                    'absent'  => 'Absent',
                                    'remote'  => 'Remote',
                                    default   => ucfirst($log->status),
                                };
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors group">

                                {{-- Employee --}}
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-3">
                                        <div class="size-9 rounded-xl {{ $avatarColor }} flex items-center justify-center shrink-0 text-[11px] font-black">
                                            {{ $initials }}
                                        </div>
                                        <div>
                                            <div class="font-semibold text-zinc-900 dark:text-white text-sm leading-tight">{{ $log->employee->user->name }}</div>
                                            <div class="text-[10px] text-zinc-400 mt-0.5">{{ $log->employee->employee_id ?? '—' }}</div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Date --}}
                                <td class="py-4 pr-4">
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $log->date->format('d M Y') }}</div>
                                    <div class="text-[10px] text-zinc-400">{{ $log->date->format('l') }}</div>
                                </td>

                                {{-- Office --}}
                                <td class="py-4 pr-4">
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400 truncate max-w-[100px] block">
                                        {{ $log->employee->office->name ?? '—' }}
                                    </span>
                                </td>

                                {{-- Clock In --}}
                                <td class="py-4 pr-4">
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white font-mono tabular-nums">
                                        {{ $log->check_in?->format('H:i') ?? '—' }}
                                    </div>
                                    @if($log->is_verified)
                                        <div class="flex items-center gap-1 text-[10px] text-emerald-600 dark:text-emerald-400 mt-0.5">
                                            <flux:icon.check-circle class="size-3" /> Verified
                                        </div>
                                    @else
                                        <div class="text-[10px] text-zinc-400 mt-0.5">Unverified</div>
                                    @endif
                                </td>

                                {{-- Clock Out --}}
                                <td class="py-4 pr-4">
                                    @if($log->check_out)
                                        <div class="text-sm font-bold text-zinc-900 dark:text-white font-mono tabular-nums">
                                            {{ $log->check_out->format('H:i') }}
                                        </div>
                                    @elseif($log->check_in)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-black text-emerald-600 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-emerald-500 animate-pulse inline-block"></span>
                                            LIVE
                                        </span>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600 font-mono">—</span>
                                    @endif
                                </td>

                                {{-- Status --}}
                                <td class="py-4 pr-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-black {{ $statusBadge }}">
                                        {{ strtoupper($statusLabel) }}
                                    </span>
                                </td>

                                {{-- Hours --}}
                                <td class="py-4 pr-6 text-right">
                                    @php $hrs = (float) $log->total_hours; @endphp
                                    <span class="text-sm font-black tabular-nums {{ $hrs >= 8 ? 'text-emerald-600 dark:text-emerald-400' : ($hrs > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-300 dark:text-zinc-600') }}">
                                        {{ $hrs > 0 ? number_format($hrs, 1).'h' : '—' }}
                                    </span>
                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-16 text-center">
                                    <flux:icon.calendar-days class="size-10 mx-auto mb-3 text-zinc-300 dark:text-zinc-700" />
                                    <p class="text-sm font-medium text-zinc-400">No attendance records found.</p>
                                    <p class="text-xs text-zinc-300 dark:text-zinc-600 mt-1">Try adjusting your filters.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($attendances->hasPages())
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-950/30">
                    {{ $attendances->links() }}
                </div>
            @endif
        </div>

    </div>

    {{-- ── REVIEW REGULARISATION MODAL ── --}}
    <flux:modal wire:model="showReviewModal" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Review Regularisation</flux:heading>
                <flux:subheading>Evaluate the attendance correction request</flux:subheading>
            </div>

            @if($activeRequest)
                @if($regularisationLocked)
                    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Already approved by Super Admin ({{ $lockedByName }})</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">A mandatory comment is required to override this approval.</p>
                        </div>
                    </div>
                @endif

                <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900 p-4 text-sm space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Employee</span>
                        <span class="font-bold text-zinc-900 dark:text-white">{{ $activeRequest->employee->user->name }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Date</span>
                        <span class="font-bold text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeRequest->work_date ?? $activeRequest->date)->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-zinc-500">Requested Time</span>
                        <span class="font-bold text-amber-600 font-mono">
                            {{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }}
                            →
                            {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}
                        </span>
                    </div>
                    <div class="pt-1 border-t border-zinc-100 dark:border-zinc-800">
                        <span class="text-zinc-500 block mb-1 text-xs">Reason</span>
                        <p class="text-zinc-700 dark:text-zinc-300 italic text-xs">"{{ $activeRequest->reason }}"</p>
                    </div>
                </div>

                <flux:textarea
                    wire:model="reviewComment"
                    label="{{ $regularisationLocked ? 'Comment (Required for Override)' : 'HR Comment (Required for Rejection)' }}"
                    rows="2"
                    placeholder="{{ $regularisationLocked ? 'Explain why you are overriding…' : 'Add a comment…' }}"
                />
                @error('reviewComment')
                    <p class="text-xs text-red-500">{{ $message }}</p>
                @enderror

                <div class="flex gap-2 justify-end pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="rejectRegularisation" variant="ghost" class="!text-red-600 hover:!bg-red-50 dark:hover:!bg-red-950/30">Reject</flux:button>
                    <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- ── HR MARK ATTENDANCE MODAL ── --}}
    <flux:modal wire:model="showMarkModal" class="w-full max-w-lg">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 p-2.5">
                    <flux:icon.pencil-square class="size-5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <div>
                    <flux:heading size="lg">Mark Attendance for Employee</flux:heading>
                    <flux:subheading>HR can submit attendance on an employee's behalf. Requires manager approval.</flux:subheading>
                </div>
            </div>

            <div class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-800/30 dark:bg-amber-900/10">
                <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <p class="text-xs leading-relaxed text-amber-700 dark:text-amber-300">
                    This creates a <strong>Regularisation Request</strong> on behalf of the employee.
                    It will be sent to the employee's <strong>Line Manager</strong> for approval before attendance is updated.
                </p>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="markEmployeeId" label="Employee" placeholder="Select employee..." required>
                    @foreach($allEmployees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->user->name }} ({{ $emp->employee_id ?? 'No ID' }})</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="markDate" type="date" label="Date of Attendance"
                    max="{{ now()->format('Y-m-d') }}" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="markCheckIn" type="time" label="Check-in Time" required />
                    <flux:input wire:model="markCheckOut" type="time" label="Check-out Time" required />
                </div>

                <div>
                    <flux:label>Work Mode</flux:label>
                    <div class="mt-1.5 grid grid-cols-3 gap-2">
                        @foreach(['office' => 'Office', 'wfh' => 'WFH', 'remote' => 'Remote'] as $val => $label)
                            <button type="button" wire:click="$set('markWorkMode', '{{ $val }}')"
                                class="rounded-xl border py-2 text-sm font-bold transition-all
                                    {{ $markWorkMode === $val
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-400'
                                        : 'border-zinc-200 text-zinc-500 hover:border-zinc-300 dark:border-zinc-700' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <flux:textarea wire:model="markReason" label="Reason for HR Entry"
                    placeholder="e.g. Employee's biometric failed, client visit, system issue..."
                    rows="2" required />
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="submitMarkAttendance" variant="primary" icon="paper-airplane">
                    Submit for Approval
                </flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
