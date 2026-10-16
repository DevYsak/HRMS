@php
    $statusStyles = [
        'pending' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        'rejected' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
        'cancelled' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700',
    ];
    $hours = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'h';
    $hasFilters = $filterStatus !== '' || $filterMonth !== '';
@endphp

<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <div class="mx-auto max-w-7xl space-y-5 p-4 md:p-6">

        {{-- Header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="pulse-page-title">My Overtime</h1>
                <p class="pulse-page-subtitle">Track requests and approved overtime.</p>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="openModal" class="w-full sm:w-auto" data-request-ot>Request OT</flux:button>
        </div>

        {{-- Summary --}}
        <div @class(['grid grid-cols-2 gap-3 sm:gap-4', 'lg:grid-cols-5' => $showPay, 'lg:grid-cols-4' => ! $showPay]) data-ot-summary>
            @foreach([
                ['key' => 'pending', 'label' => 'Pending', 'value' => $summary['pending'], 'hint' => 'awaiting a decision', 'tone' => 'text-amber-600'],
                ['key' => 'approved', 'label' => 'Approved', 'value' => $summary['approved'], 'hint' => 'all time', 'tone' => 'text-emerald-600'],
                ['key' => 'rejected', 'label' => 'Rejected', 'value' => $summary['rejected'], 'hint' => 'all time', 'tone' => 'text-rose-600'],
                ['key' => 'hours', 'label' => 'Payable OT This Month', 'value' => $hours($summary['approved_hours_month']), 'hint' => now()->format('F Y'), 'tone' => 'text-zinc-900 dark:text-white'],
            ] as $card)
                <div class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 dark:border-zinc-800 dark:bg-zinc-900" data-ot-card="{{ $card['key'] }}">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 [overflow-wrap:anywhere]">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-bold sm:text-3xl {{ $card['tone'] }}">{{ $card['value'] }}</p>
                    <p class="mt-1 text-xs text-zinc-400">{{ $card['hint'] }}</p>
                </div>
            @endforeach
            @if($showPay)
                <div class="col-span-2 min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-5 lg:col-span-1 dark:border-zinc-800 dark:bg-zinc-900" data-ot-card="payable">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Estimated payable this month</p>
                    <p class="mt-2 text-2xl font-bold text-zinc-900 sm:text-3xl dark:text-white">₹{{ number_format($summary['approved_amount_month'], 2) }}</p>
                    <p class="mt-1 text-xs text-zinc-400">final amount on your payslip</p>
                </div>
            @endif
        </div>

        {{-- History --}}
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-col gap-3 border-b border-zinc-100 px-4 py-4 sm:px-5 md:flex-row md:items-center md:justify-between dark:border-zinc-800">
                <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Request history</h2>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-[10rem_11rem_auto] sm:items-center" data-ot-filters>
                    <flux:select wire:model.live="filterStatus" size="sm" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </flux:select>
                    <flux:input wire:model.live="filterMonth" type="month" size="sm" aria-label="Filter by month" />
                    @if($hasFilters)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Clear</flux:button>
                    @endif
                </div>
            </div>

            @if($requests->isEmpty())
                <div class="px-6 py-14 text-center" data-ot-empty>
                    <flux:icon.clock class="mx-auto size-10 text-zinc-300 dark:text-zinc-700" />
                    @if($hasFilters)
                        <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-300">No OT requests match these filters.</p>
                        <button type="button" wire:click="clearFilters" class="mt-1 text-xs font-semibold text-orange-600 hover:underline">Clear filters</button>
                    @else
                        <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-300">You have not requested any overtime yet.</p>
                        <p class="mt-1 text-xs text-zinc-400">Use “Request OT” when you need to work beyond your shift.</p>
                    @endif
                </div>
            @else
                {{-- Desktop table --}}
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[56rem] text-sm">
                        <thead class="bg-zinc-50/70 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                            <tr>
                                <th class="px-5 py-3">Date</th>
                                <th class="px-3 py-3">Requested window</th>
                                <th class="px-3 py-3 text-right">Requested hours</th>
                                <th class="px-3 py-3 text-right">Actual approved hours</th>
                                <th class="px-3 py-3">Reason</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Approver</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($requests as $req)
                                <tr wire:key="ot-{{ $req->id }}" class="align-top">
                                    <td class="px-5 py-3.5">
                                        <p class="font-semibold text-zinc-900 dark:text-white">@userDate($req->work_date)</p>
                                        <p class="text-xs text-zinc-400">{{ $req->work_date->format('l') }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3.5 text-zinc-600 dark:text-zinc-300">@userTime($req->start_time) – @userTime($req->end_time)</td>
                                    <td class="px-3 py-3.5 text-right font-semibold text-zinc-900 dark:text-white">{{ $hours($req->requested_hours) }}</td>
                                    <td class="px-3 py-3.5 text-right font-semibold text-zinc-900 dark:text-white">{{ $req->status === 'approved' && $req->overtimeRecord ? $hours($req->overtimeRecord->ot_hours) : '—' }}</td>
                                    <td class="max-w-[16rem] px-3 py-3.5 text-zinc-600 dark:text-zinc-300"><p class="line-clamp-2 [overflow-wrap:anywhere]" title="{{ $req->reason }}">{{ $req->reason }}</p></td>
                                    <td class="px-3 py-3.5"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset {{ $statusStyles[$req->status] ?? $statusStyles['cancelled'] }}">{{ $req->status }}</span></td>
                                    <td class="px-3 py-3.5 text-zinc-600 dark:text-zinc-300">{{ $req->reviewer?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                        <button type="button" wire:click="showDetails({{ $req->id }})" class="text-xs font-semibold text-zinc-600 hover:text-orange-600 hover:underline dark:text-zinc-300">Details</button>
                                        @if($req->isPending())
                                            <button type="button" wire:click="cancel({{ $req->id }})" wire:confirm="Cancel this OT request?" class="ml-3 text-xs font-semibold text-red-600 hover:underline">Cancel</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile cards --}}
                <ul class="divide-y divide-zinc-100 md:hidden dark:divide-zinc-800" data-ot-mobile-list>
                    @foreach($requests as $req)
                        <li wire:key="ot-m-{{ $req->id }}" class="space-y-2 px-4 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-zinc-900 dark:text-white">@userDate($req->work_date)</p>
                                    <p class="text-xs text-zinc-500">@userTime($req->start_time) – @userTime($req->end_time) · {{ $hours($req->requested_hours) }} requested</p>
                                </div>
                                <span class="inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset {{ $statusStyles[$req->status] ?? $statusStyles['cancelled'] }}">{{ $req->status }}</span>
                            </div>
                            <p class="text-sm text-zinc-600 [overflow-wrap:anywhere] dark:text-zinc-300">{{ $req->reason }}</p>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500">
                                <span>
                                    @if($req->status === 'approved' && $req->overtimeRecord) Approved {{ $hours($req->overtimeRecord->ot_hours) }} · @endif
                                    {{ $req->reviewer?->name ? 'By '.$req->reviewer->name : 'No approver yet' }}
                                </span>
                                <span class="flex gap-4">
                                    <button type="button" wire:click="showDetails({{ $req->id }})" class="font-semibold text-zinc-700 dark:text-zinc-200">Details</button>
                                    @if($req->isPending())
                                        <button type="button" wire:click="cancel({{ $req->id }})" wire:confirm="Cancel this OT request?" class="font-semibold text-red-600">Cancel</button>
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($requests->hasPages())
                <div class="border-t border-zinc-100 px-4 py-3 sm:px-5 dark:border-zinc-800">{{ $requests->links() }}</div>
            @endif
        </section>
    </div>

    {{-- Request OT --}}
    @if($showModal)
        <x-pulse.modal title="Request Overtime" subtitle="Ask your manager to approve overtime work." close="$wire.set('showModal', false)" width="sm:max-w-lg">
            <form id="ot-request-form" wire:submit="submit" class="space-y-5" novalidate>
                <div>
                    <x-pulse.label for="ot-work-date" required>Work Date</x-pulse.label>
                    <flux:input id="ot-work-date" wire:model="work_date" type="date" max="{{ now()->toDateString() }}" aria-required="true" />
                    <flux:error name="work_date" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="min-w-0">
                        <x-pulse.label for="ot-start" required>OT Start Time</x-pulse.label>
                        <flux:input id="ot-start" wire:model="start_time" type="time" aria-required="true" />
                        <flux:error name="start_time" />
                    </div>
                    <div class="min-w-0">
                        <x-pulse.label for="ot-end" required>OT End Time</x-pulse.label>
                        <flux:input id="ot-end" wire:model="end_time" type="time" aria-required="true" />
                        <flux:error name="end_time" />
                    </div>
                </div>

                <div>
                    <x-pulse.label for="ot-reason" required>Reason for OT</x-pulse.label>
                    <flux:textarea id="ot-reason" wire:model="reason" rows="3" maxlength="500" aria-required="true"
                        placeholder="Describe the work that needs overtime…" />
                    <flux:error name="reason" />
                </div>

                <p class="rounded-xl bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                    Your manager approves the request. Approved hours are worked out from your attendance for that day and paid through payroll.
                </p>
            </form>

            <x-slot:footer>
                <flux:button variant="ghost" x-on:click="$wire.set('showModal', false)">Cancel</flux:button>
                <flux:button type="submit" form="ot-request-form" variant="primary" wire:loading.attr="disabled" wire:target="submit">
                    <span wire:loading.remove wire:target="submit">Submit Request</span>
                    <span wire:loading wire:target="submit">Submitting…</span>
                </flux:button>
            </x-slot:footer>
        </x-pulse.modal>
    @endif

    {{-- Details & timeline --}}
    @if($viewing)
        <x-pulse.modal title="Overtime request" :subtitle="app(\App\Support\DateDisplay::class)->date($viewing->work_date)" close="$wire.closeDetails()" width="sm:max-w-md">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div><dt class="text-xs text-zinc-500">Requested window</dt><dd class="font-medium text-zinc-900 dark:text-white">@userTime($viewing->start_time) – @userTime($viewing->end_time)</dd></div>
                <div><dt class="text-xs text-zinc-500">Requested hours</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $hours($viewing->requested_hours) }}</dd></div>
                <div><dt class="text-xs text-zinc-500">Status</dt><dd><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset {{ $statusStyles[$viewing->status] ?? $statusStyles['cancelled'] }}">{{ $viewing->status }}</span></dd></div>
                <div><dt class="text-xs text-zinc-500">Actual approved hours</dt><dd class="font-medium text-zinc-900 dark:text-white">{{ $viewing->status === 'approved' && $viewing->overtimeRecord ? $hours($viewing->overtimeRecord->ot_hours) : '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-zinc-500">Reason</dt><dd class="text-zinc-700 [overflow-wrap:anywhere] dark:text-zinc-200">{{ $viewing->reason }}</dd></div>
            </dl>

            <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-zinc-500">Timeline</h3>
            <ol class="mt-3 space-y-4 border-l border-zinc-200 pl-4 dark:border-zinc-700" data-ot-timeline>
                <li class="relative">
                    <span class="absolute -left-[21px] top-1 size-2.5 rounded-full bg-orange-500"></span>
                    <p class="text-sm font-medium text-zinc-900 dark:text-white">Submitted</p>
                    <p class="text-xs text-zinc-500">@userDateTime($viewing->created_at)</p>
                </li>
                @if(in_array($viewing->status, ['approved', 'rejected'], true))
                    <li class="relative">
                        <span @class(['absolute -left-[21px] top-1 size-2.5 rounded-full', 'bg-emerald-500' => $viewing->status === 'approved', 'bg-rose-500' => $viewing->status === 'rejected'])></span>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ ucfirst($viewing->status) }} by {{ $viewing->reviewer?->name ?? 'an approver' }}</p>
                        <p class="text-xs text-zinc-500">@userDateTime($viewing->reviewed_at)</p>
                        @if($viewing->reviewer_comment)
                            <p class="mt-1 rounded-lg bg-zinc-50 p-2 text-xs text-zinc-600 [overflow-wrap:anywhere] dark:bg-zinc-800 dark:text-zinc-300">“{{ $viewing->reviewer_comment }}”</p>
                        @endif
                    </li>
                @elseif($viewing->status === 'cancelled')
                    <li class="relative">
                        <span class="absolute -left-[21px] top-1 size-2.5 rounded-full bg-zinc-400"></span>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Cancelled by you</p>
                        <p class="text-xs text-zinc-500">@userDateTime($viewing->updated_at)</p>
                    </li>
                @else
                    <li class="relative">
                        <span class="absolute -left-[21px] top-1 size-2.5 rounded-full border-2 border-amber-400 bg-white dark:bg-zinc-900"></span>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Waiting for your manager</p>
                    </li>
                @endif
            </ol>

            @if($viewing->isPending())
                <x-slot:footer>
                    <flux:button variant="danger" wire:click="cancel({{ $viewing->id }})" wire:confirm="Cancel this OT request?">Cancel request</flux:button>
                </x-slot:footer>
            @endif
        </x-pulse.modal>
    @endif
</flux:main>
