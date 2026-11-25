{{--
    Punch Audit: every attendance exception over a range for the people in
    reach (AttendanceRegister::audit()), newest first, filterable by kind.
--}}
@php
    $audit = $register;
    $typeTone = [
        'missed_punch' => 'red', 'late' => 'amber', 'missing_checkout' => 'red', 'auto_checkout' => 'amber',
        'long_break' => 'blue', 'absent' => 'red', 'regularised' => 'violet', 'extra_taps' => 'zinc',
    ];
@endphp

<div class="ui-surface flex flex-wrap items-end justify-between gap-3 p-3" data-audit>
    <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs font-semibold text-zinc-500">From
            <input type="date" wire:model.live="from" max="{{ now()->toDateString() }}" class="{{ $dateInput }} mt-1 block">
        </label>
        <label class="text-xs font-semibold text-zinc-500">To
            <input type="date" wire:model.live="to" max="{{ now()->toDateString() }}" class="{{ $dateInput }} mt-1 block">
        </label>
        <select wire:model.live="department" class="{{ $select }}" aria-label="Department">
            <option value="">All departments</option>
            @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
        <div class="relative">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search people…" aria-label="Search people"
                class="w-48 rounded-xl border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
        </div>
    </div>
    <div class="flex flex-wrap gap-1">
        @foreach(\App\Livewire\Attendance\LiveAttendance::PRESETS as $key => $label)
            <button type="button" wire:click="preset('{{ $key }}')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-orange-50 hover:text-orange-600 dark:text-zinc-300 dark:hover:bg-orange-500/10">{{ $label }}</button>
        @endforeach
    </div>
</div>

<div class="flex flex-wrap items-center justify-between gap-2">
    <div class="flex flex-wrap items-center gap-2" data-audit-pills>
        <button type="button" wire:click="$set('auditType', '')" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset',
            'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900' => $auditType === '',
            'bg-white text-zinc-700 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $auditType !== ''])>All {{ $audit['counts']['all'] }}</button>
        @foreach(\App\Services\Attendance\AttendanceRegister::AUDIT_TYPES as $key => $label)
            <button type="button" wire:click="setAuditType('{{ $key }}')" data-audit-pill="{{ $key }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset',
                'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900' => $auditType === $key,
                'bg-white text-zinc-700 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $auditType !== $key])>
                {{ $label }} <span class="opacity-70">{{ $audit['counts'][$key] }}</span>
            </button>
        @endforeach
    </div>
    <span class="text-xs text-zinc-400" data-audit-range>{{ \Carbon\CarbonImmutable::parse($audit['from'])->userDate() }} – {{ \Carbon\CarbonImmutable::parse($audit['to'])->userDate() }} · up to {{ \App\Services\Attendance\AttendanceRegister::MAX_AUDIT_DAYS }} days</span>
</div>

<div class="ui-surface overflow-x-auto">
    @if($audit['items'] === [])
        <p class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">Nothing to review in this range.</p>
    @else
        <table class="w-full min-w-[900px] text-sm" data-audit-table>
            <thead>
                <tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <th class="px-4 py-2.5 font-medium">Date</th>
                    <th class="px-3 py-2.5 font-medium">Person</th>
                    <th class="px-3 py-2.5 font-medium">Exception</th>
                    <th class="px-3 py-2.5 font-medium">Details</th>
                    <th class="px-4 py-2.5 font-medium">State</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($audit['items'] as $i => $item)
                    <tr wire:key="audit-{{ $item['employee_id'] }}-{{ $item['date'] }}-{{ $item['type'] }}-{{ $i }}" data-audit-item="{{ $item['type'] }}" class="text-zinc-700 dark:text-zinc-300">
                        <td class="whitespace-nowrap px-4 py-2.5">
                            <button type="button" wire:click="openDay('{{ $item['date'] }}')" class="font-semibold text-zinc-900 hover:text-orange-600 dark:text-white">{{ \Carbon\CarbonImmutable::parse($item['date'])->userDate() }}</button>
                            <div class="text-xs text-zinc-400">{{ \Carbon\CarbonImmutable::parse($item['date'])->format('D') }}</div>
                        </td>
                        <td class="px-3 py-2.5">
                            <button type="button" wire:click="openEmployee({{ $item['employee_id'] }}, '{{ $item['date'] }}')" class="flex items-center gap-2 text-left">
                                <span class="inline-flex size-7 items-center justify-center rounded-full bg-zinc-100 text-[10px] font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-200">{{ $item['initials'] }}</span>
                                <span><span class="block font-semibold text-zinc-900 hover:text-orange-600 dark:text-white">{{ $item['name'] }}</span><span class="block text-xs text-zinc-400">{{ $item['department'] }}</span></span>
                            </button>
                        </td>
                        <td class="px-3 py-2.5">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $chipTone[$typeTone[$item['type']]] }}">{{ \App\Services\Attendance\AttendanceRegister::AUDIT_TYPES[$item['type']] }}</span>
                            <div class="mt-1 text-xs font-semibold text-zinc-800 dark:text-zinc-100">{{ $item['title'] }}</div>
                        </td>
                        <td class="px-3 py-2.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['detail'] }}</td>
                        <td class="px-4 py-2.5 text-xs font-semibold">
                            @if($item['resolved'])<span class="text-emerald-600">Resolved</span>@else<span class="text-red-600">Open</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
