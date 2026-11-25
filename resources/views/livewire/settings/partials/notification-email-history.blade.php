{{--
    Settings → Notifications & Email → Email history: every email the system
    sent, failed to send or held back (and why), with the event, recipient,
    the employee's department, the request it was about and who triggered it.
--}}
@php
    $statusTone = [
        'sent' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'failed' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        'skipped' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300',
        'sending' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    ];
@endphp
<div class="space-y-4" data-email-history>
    <div class="ui-surface grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <flux:input wire:model.live.debounce.300ms="historySearch" icon="magnifying-glass" placeholder="Recipient or subject" class="lg:col-span-2" />
        <flux:select wire:model.live="historyEvent">
            <flux:select.option value="">All events</flux:select.option>
            @foreach($eventLabels as $key => $label)
                <flux:select.option value="{{ $key }}">{{ str_contains($key, '@') ? '— '.$label : $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="historyStatus">
            <flux:select.option value="">Any status</flux:select.option>
            <flux:select.option value="sent">Sent</flux:select.option>
            <flux:select.option value="failed">Failed</flux:select.option>
            <flux:select.option value="skipped">Not sent (held back)</flux:select.option>
            <flux:select.option value="sending">Sending</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="historyDepartment">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach($departmentList as $department)
                <flux:select.option value="{{ $department->id }}">{{ $department->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <div class="flex gap-2">
            <flux:input type="date" wire:model.live="historyFrom" aria-label="From" />
            <flux:input type="date" wire:model.live="historyTo" aria-label="To" />
        </div>
    </div>

    <div class="ui-surface overflow-hidden">
        @if($history === null || $history->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-zinc-400">No emails match.</p>
        @else
            <div class="overflow-x-auto" data-table-scroll><table class="w-full min-w-[980px] text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 bg-zinc-50/70 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:border-white/5 dark:bg-zinc-800/40">
                        <th class="px-5 py-2 text-left">When</th>
                        <th class="px-3 py-2 text-left">Event</th>
                        <th class="px-3 py-2 text-left">To</th>
                        <th class="px-3 py-2 text-left">Department</th>
                        <th class="px-3 py-2 text-left">About</th>
                        <th class="px-3 py-2 text-left">Triggered by</th>
                        <th class="px-5 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($history as $log)
                        @php $ctx = $historyContext[$log->id] ?? []; @endphp
                        <tr wire:key="history-{{ $log->id }}" class="align-top text-zinc-700 dark:text-zinc-300" data-history-email="{{ $log->id }}">
                            <td class="whitespace-nowrap px-5 py-2.5 text-xs">
                                {{ $log->created_at?->toImmutable()->userDate() }}
                                <div class="text-zinc-400">{{ $log->created_at?->toImmutable()->userTime() }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-xs">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $eventLabels[$log->notification_key] ?? ($log->notification_key ? class_basename(\Illuminate\Support\Str::before($log->notification_key, '@')) : '—') }}</div>
                                <div class="max-w-xs truncate text-zinc-400" title="{{ $log->subject }}">{{ $log->subject }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-xs">
                                <div class="font-medium">{{ $log->to_name ?? '—' }}</div>
                                <div class="text-zinc-400">{{ $log->to_email }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-xs">{{ $ctx['department'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-xs">
                                @if(! empty($ctx['url']))
                                    <a href="{{ $ctx['url'] }}" wire:navigate class="font-semibold text-orange-600 hover:underline">{{ $ctx['related'] }}</a>
                                @else
                                    {{ $ctx['related'] ?? '—' }}
                                @endif
                                @if(! empty($ctx['employee']))<div class="text-zinc-400">{{ $ctx['employee'] }}</div>@endif
                            </td>
                            <td class="px-3 py-2.5 text-xs">{{ $ctx['by'] ?? '—' }}</td>
                            <td class="px-5 py-2.5 text-xs">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $statusTone[$log->status] ?? $statusTone['skipped'] }}">{{ $log->status === 'skipped' ? 'Not sent' : $log->status }}</span>
                                @if($log->skip_reason)<div class="mt-0.5 text-zinc-400">{{ ucfirst(str_replace('_', ' ', $log->skip_reason)) }}</div>@endif
                                @if($log->status === 'failed' && $log->error)<div class="mt-0.5 max-w-xs truncate text-rose-500" title="{{ $log->error }}">{{ $log->error }}</div>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="border-t border-zinc-100 px-5 py-3 dark:border-white/5">{{ $history->links() }}</div>
        @endif
    </div>
</div>
