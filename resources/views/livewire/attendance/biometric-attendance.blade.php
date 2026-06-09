<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">
    <div class="space-y-6">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Biometric Attendance</flux:heading>
            <flux:subheading>Live attendance data from the biometric system.</flux:subheading>
        </div>
        <flux:button wire:click="refresh" icon="arrow-path" variant="ghost" size="sm">
            Refresh
        </flux:button>
    </div>

    {{-- Error banner --}}
    @if ($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 flex items-start gap-3">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.75-11.25a.75.75 0 011.5 0v4.5a.75.75 0 01-1.5 0v-4.5zm.75 8a1 1 0 110-2 1 1 0 010 2z"
                    clip-rule="evenodd" />
            </svg>
            <span><strong>Biometric server error:</strong> {{ $error }} — attendance data may be stale.</span>
        </div>
    @endif

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-xl border bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Total Employees</p>
            <p class="mt-1 text-3xl font-bold text-zinc-900">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-emerald-600">Present</p>
            <p class="mt-1 text-3xl font-bold text-emerald-700">{{ $stats['present'] }}</p>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-blue-600">In Office</p>
            <p class="mt-1 text-3xl font-bold text-blue-700">{{ $stats['in_office'] }}</p>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Absent</p>
            <p class="mt-1 text-3xl font-bold text-zinc-400">{{ $stats['absent'] }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <flux:label for="date-picker">Date</flux:label>
            <flux:input id="date-picker" type="date" wire:model.live="date" />
        </div>
        <div>
            <flux:label for="dept-filter">Department</flux:label>
            <flux:select id="dept-filter" wire:model.live="department">
                <option value="">All departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept }}">{{ $dept }}</option>
                @endforeach
            </flux:select>
        </div>
        <div class="flex-1 min-w-48">
            <flux:label for="search-box">Search</flux:label>
            <flux:input id="search-box" wire:model.live.debounce.300ms="search" placeholder="Name or employee code…"
                icon="magnifying-glass" />
        </div>
        @if ($fetchedAt)
            <p class="mb-2 text-xs text-zinc-400">Last fetched: {{ $fetchedAt }}</p>
        @endif
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-100 text-sm">
            <thead class="bg-zinc-50 text-xs font-semibold uppercase tracking-wide text-zinc-500">
                <tr>
                    <th class="px-4 py-3 text-left">Employee</th>
                    <th class="px-4 py-3 text-left">Department</th>
                    <th class="px-4 py-3 text-left">First In</th>
                    <th class="px-4 py-3 text-left">Last Out</th>
                    <th class="px-4 py-3 text-left">Working Hours</th>
                    <th class="px-4 py-3 text-left">Sessions</th>
                    <th class="px-4 py-3 text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($rows as $emp)
                    @php $summary = $emp['summary']; @endphp
                    <tr class="hover:bg-zinc-50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="font-medium text-zinc-900">{{ $emp['name'] }}</div>
                            <div class="text-xs text-zinc-400">{{ $emp['employee_code'] }}</div>
                        </td>
                        <td class="px-4 py-3 text-zinc-600">{{ $emp['department'] ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-zinc-700">
                            {{ $summary['first_in'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-zinc-700">
                            {{ $summary['last_out'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 font-mono text-zinc-700">
                            {{ $summary['working_hours'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-zinc-500">
                            {{ count($emp['sessions']) }}
                            @if ($summary['punch_count'] > 0)
                                <span class="text-xs text-zinc-400">({{ $summary['punch_count'] }} punches)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $status = $summary['status'] ?? 'absent';
                                $badge = match ($status) {
                                    'checked_out' => 'bg-emerald-100 text-emerald-700',
                                    'in_office' => 'bg-blue-100 text-blue-700',
                                    'admin_corrected' => 'bg-violet-100 text-orange-700',
                                    default => 'bg-zinc-100 text-zinc-500',
                                };
                                $label = match ($status) {
                                    'checked_out' => 'Checked Out',
                                    'in_office' => 'In Office',
                                    'admin_corrected' => 'Corrected',
                                    default => 'Absent',
                                };
                            @endphp
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $badge }}">
                                {{ $label }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-zinc-400">
                            @if ($error)
                                Could not load attendance — check the error above.
                            @else
                                No employees match your filters.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-zinc-400">
        Attendance is calculated exclusively in the biometric system and displayed read-only here.
        To correct a record, use the Biometric App admin panel.
    </p>

    </div>
</flux:main>