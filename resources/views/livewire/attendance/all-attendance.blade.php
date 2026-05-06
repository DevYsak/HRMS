<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    {{-- Page Header: title row + filters row stacked --}}
    <div>
        <div class="flex items-start justify-between mb-4">
            <div>
                <h1 class="pulse-page-title">Employee Attendance Master</h1>
                <p class="pulse-page-subtitle">View and audit attendance across the organization</p>
            </div>
            <flux:button wire:click="openMarkModal" variant="primary" icon="pencil-square" size="sm">Mark for Employee</flux:button>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search employee..." icon="magnifying-glass" size="sm" class="w-64" />
            <flux:input wire:model.live="date" type="date" size="sm" class="w-48" />
            <flux:select wire:model.live="status" placeholder="Filter by status" size="sm" class="w-44">
                <option value="">All Status</option>
                <option value="on_time">On Time</option>
                <option value="late">Late</option>
                <option value="remote">Remote</option>
                <option value="absent">Absent</option>
            </flux:select>
            <flux:button href="{{ route('reports.attendance-summary', ['month' => now()->month, 'year' => now()->year]) }}" variant="ghost" icon="arrow-down-tray" size="sm" target="_blank">Export CSV</flux:button>
        </div>
    </div>

    {{-- Pending Regularisation Requests --}}
    @if($pendingRegularisations->isNotEmpty())
        <div class="pulse-card border-brand-200 dark:border-brand-900/50 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-1 h-full bg-brand-500"></div>
            <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">Pending Regularisation Requests</h3>
            <div class="overflow-x-auto -mx-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Requested Time</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Reason</th>
                            <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @foreach($pendingRegularisations as $req)
                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                                <td class="py-3 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                    {{ $req->employee->user->name }}
                                </td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                    {{ \Carbon\Carbon::parse($req->work_date)->format('M d, Y') }}
                                </td>
                                <td class="py-3 pr-4">
                                    {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
                                </td>
                                <td class="py-3 pr-4 text-zinc-500 truncate max-w-xs" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </td>
                                <td class="py-3 pr-6 text-right">
                                    <flux:button wire:click="openReviewModal({{ $req->id }})" size="xs" variant="primary">Review</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="pulse-card">
        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Date</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Office</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Clock In</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Clock Out</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Hours</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($attendances as $log)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/20 transition-colors">
                            <td class="py-4 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $log->employee->user->name }}</div>
                                <div class="text-[10px] text-zinc-500">ID: {{ $log->employee->employee_id }}</div>
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $log->date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-500 truncate max-w-[120px]">
                                {{ $log->employee->office->name ?? 'N/A' }}
                            </td>
                            <td class="py-4 pr-4">
                                <div>{{ $log->check_in->format('H:i') }}</div>
                                @if($log->is_verified)
                                    <div class="text-[10px] text-green-500 flex items-center gap-1">
                                        <flux:icon.check-circle class="size-2.5" /> Verified
                                    </div>
                                @else
                                    <div class="text-[10px] text-zinc-400">Unverified</div>
                                @endif
                            </td>
                            <td class="py-4 pr-4">
                                {{ $log->check_out ? $log->check_out->format('H:i') : '--:--' }}
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $log->status === 'on_time' ? $log->status : ($log->status === 'late' ? 'rejected' : 'manager') }}">
                                    {{ strtoupper($log->status) }}
                                </span>
                            </td>
                            <td class="py-4 pr-6 text-right font-bold text-zinc-900 dark:text-white">
                                {{ (float)$log->total_hours }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-zinc-400">No attendance records found matching filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $attendances->links() }}
        </div>
    </div>

    {{-- Review Modal --}}
    <flux:modal wire:model="showReviewModal" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Review Regularisation Request</flux:heading>
                <flux:subheading>Evaluate the attendance correction</flux:subheading>
            </div>

            @if($activeRequest)
                {{-- Super Admin lock warning --}}
                @if($regularisationLocked)
                    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
                        <svg class="mt-0.5 size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <div>
                            <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Already approved by Super Admin ({{ $lockedByName }})</p>
                            <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">A mandatory comment is required to override this approval.</p>
                        </div>
                    </div>
                @endif

                <div class="bg-zinc-50 p-4 rounded-xl dark:bg-zinc-900 text-sm space-y-3">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Employee</span>
                        <span class="font-bold">{{ $activeRequest->employee->user->name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Date</span>
                        <span class="font-bold">{{ \Carbon\Carbon::parse($activeRequest->work_date ?? $activeRequest->date)->format('d M Y') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Requested Time</span>
                        <span class="font-bold text-orange-600">
                            {{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }}
                            –
                            {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}
                        </span>
                    </div>
                    <div>
                        <span class="text-zinc-500 block mb-1">Reason provided</span>
                        <p class="text-zinc-700 dark:text-zinc-300 italic">"{{ $activeRequest->reason }}"</p>
                    </div>
                </div>

                <div>
                    <flux:textarea
                        wire:model="reviewComment"
                        label="{{ $regularisationLocked ? 'Comment (Required for Override)' : 'HR Comment (Required for Rejection)' }}"
                        rows="2"
                        placeholder="{{ $regularisationLocked ? 'Explain why you are overriding…' : 'Add a comment…' }}"
                    />
                    @error('reviewComment')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                </div>

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

    {{-- ─── HR MARK ATTENDANCE MODAL ─── --}}
    <flux:modal wire:model="showMarkModal" class="w-full max-w-lg">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-brand-50 p-2.5 dark:bg-brand-900/20">
                    <flux:icon.pencil-square class="size-5 text-brand-600 dark:text-brand-400" />
                </div>
                <div>
                    <flux:heading size="lg">Mark Attendance for Employee</flux:heading>
                    <flux:subheading>HR can submit attendance on an employee's behalf. Requires manager approval.</flux:subheading>
                </div>
            </div>

            {{-- Approval info callout --}}
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

                <div class="mt-2 grid grid-cols-7 gap-1">
                    @php
                        $weekStart = \Carbon\Carbon::parse($markDate ?? today())->startOfWeek(\Carbon\Carbon::MONDAY);
                    @endphp
                    @for($d = 0; $d < 7; $d++)
                        @php $day = $weekStart->copy()->addDays($d); @endphp
                        <div class="flex flex-col items-center rounded-lg p-1.5 text-center text-[10px]
                            {{ $day->isToday() ? 'bg-orange-100 text-orange-700 font-bold' : 'bg-zinc-50 text-zinc-400' }}">
                            <span class="font-bold">{{ $day->format('D') }}</span>
                            <span>{{ $day->format('d') }}</span>
                        </div>
                    @endfor
                </div>

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
                                        ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-900/20 dark:text-brand-400'
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
