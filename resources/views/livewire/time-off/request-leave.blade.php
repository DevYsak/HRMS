@php
    use App\Services\Leave\LeaveCategory;

    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $date = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    $categoryIcon = [LeaveCategory::EARNED => 'sparkles', LeaveCategory::SPECIAL => 'heart', LeaveCategory::COMP_OFF => 'arrow-path-rounded-square', LeaveCategory::UNPAID => 'banknotes'];
    $categoryTone = [
        LeaveCategory::EARNED => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        LeaveCategory::SPECIAL => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        LeaveCategory::COMP_OFF => 'bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400',
        LeaveCategory::UNPAID => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
    ];
    $steps = [1 => 'Category', 2 => 'Details', 3 => 'Review'];
    $eval = $this->evaluation;
    $selected = $this->selected;
    $label = 'mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-200';
    $input = 'w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-white/10 dark:bg-zinc-900 dark:text-white';
@endphp

<div>
@if($open)
<div data-app-modal data-request-leave role="dialog" aria-modal="true" aria-labelledby="request-leave-title"
    class="fixed inset-0 z-50 flex items-end justify-center sm:items-stretch sm:justify-end"
    x-data="{ opener: document.activeElement, destroy() { if (this.opener && document.contains(this.opener)) this.opener.focus() } }"
    x-trap="true"
    x-on:keydown.escape.window.prevent="$wire.close()">

    <div class="absolute inset-0 bg-zinc-950/50 backdrop-blur-[2px]" wire:click="close" aria-hidden="true"></div>

    <div class="relative flex h-[100dvh] w-full flex-col bg-white shadow-2xl ring-1 ring-black/5 sm:max-w-[30rem] sm:rounded-l-2xl dark:bg-zinc-900 dark:ring-white/10">
        {{-- Header --}}
        <header class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <div class="min-w-0">
                <h2 id="request-leave-title" class="text-base font-bold text-zinc-900 dark:text-white">Request Leave</h2>
                <p class="mt-0.5 text-sm text-zinc-500">Submit a new leave application for review.</p>
            </div>
            <button type="button" wire:click="close" aria-label="Close"
                class="-mr-1 shrink-0 rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                <flux:icon.x-mark class="size-5" />
            </button>
        </header>

        {{-- Stepper --}}
        @if($step < 4)
            <ol class="flex shrink-0 items-center gap-2 border-b border-zinc-100 px-5 py-3 text-sm dark:border-zinc-800" aria-label="Progress">
                @foreach($steps as $n => $name)
                    <li class="flex flex-1 items-center gap-2 {{ $loop->last ? 'flex-none' : '' }}" @if($step === $n) aria-current="step" @endif>
                        <span @class([
                            'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                            'bg-orange-500 text-white' => $step >= $n,
                            'border border-zinc-300 text-zinc-500 dark:border-zinc-600' => $step < $n,
                        ])>
                            @if($step > $n)<flux:icon.check class="size-3.5" />@else{{ $n }}@endif
                        </span>
                        <span @class(['whitespace-nowrap font-medium', 'text-orange-600 dark:text-orange-400' => $step >= $n, 'text-zinc-500' => $step < $n])>{{ $name }}</span>
                        @unless($loop->last)<span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></span>@endunless
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Body --}}
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5" data-modal-body>

            @error('request')
                <div class="mb-4 flex gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300" role="alert">
                    <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0" /> <span>{{ $message }}</span>
                </div>
            @enderror

            {{-- STEP 1 — main category --}}
            @if($step === 1)
                <fieldset>
                    <legend class="{{ $label }}">Select leave category <span class="text-rose-500">*</span></legend>
                    <div class="space-y-3">
                        @forelse($this->choices as $key => $choice)
                            <label wire:key="cat-{{ $key }}" @class([
                                'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition',
                                'border-orange-300 bg-orange-50/60 ring-1 ring-orange-200 dark:border-orange-500/40 dark:bg-orange-500/10 dark:ring-orange-500/20' => $category === $key,
                                'border-zinc-200 hover:border-zinc-300 dark:border-white/10 dark:hover:border-white/20' => $category !== $key,
                            ]) data-category="{{ $key }}">
                                <input type="radio" name="leave-category" value="{{ $key }}" class="mt-1 accent-orange-500"
                                    wire:click="chooseCategory('{{ $key }}')" @checked($category === $key)>
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $categoryTone[$key] }}">
                                    <flux:icon :name="$categoryIcon[$key]" class="size-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $choice['label'] }}@if($key === LeaveCategory::EARNED) <span class="font-normal text-zinc-500">(Paid Leave)</span>@endif</span>
                                        <span class="text-xs font-medium text-zinc-500">
                                            {{ $key === LeaveCategory::UNPAID ? 'No balance required' : ($choice['available'] === null ? 'As per policy' : $fmt($choice['available']).' days available') }}
                                        </span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">{{ $choice['description'] }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="rounded-xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-white/10">No leave is set up for you yet. Contact HR.</p>
                        @endforelse
                    </div>
                    @error('category')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                </fieldset>
            @endif

            {{-- STEP 2 — balance, reason or type, and details --}}
            @if($step === 2)
                @php $choice = $this->choices[$category] ?? null; $types = $choice['types'] ?? []; @endphp
                <div class="space-y-5">
                    @if($category === LeaveCategory::EARNED && $choice)
                        {{-- The one Earned Leave balance every reason draws on. --}}
                        @php $pool = $choice['balance']; @endphp
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/5" data-earned-available>
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave available</p>
                                <p class="text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{{ $pool['unlimited'] ? 'As per policy' : $fmt($pool['available']).' days' }}</p>
                            </div>
                            <dl class="mt-2 grid grid-cols-3 gap-x-3 text-xs">
                                <div><dt class="text-zinc-500">Accrued</dt><dd class="font-semibold tabular-nums">{{ $fmt($pool['credits']) }}</dd></div>
                                <div><dt class="text-zinc-500">Used</dt><dd class="font-semibold tabular-nums">{{ $fmt($pool['used']) }}</dd></div>
                                <div><dt class="text-zinc-500">Pending</dt><dd class="font-semibold tabular-nums">{{ $fmt($pool['pending']) }}</dd></div>
                            </dl>
                        </div>

                        @if(count($types) > 1)
                            <fieldset>
                                <legend class="{{ $label }}">Reason / leave type <span class="text-rose-500">*</span></legend>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup">
                                    @foreach($types as $type)
                                        <label wire:key="reason-{{ $type['id'] }}" @class([
                                            'flex cursor-pointer items-center gap-2.5 rounded-xl border px-3 py-2.5 text-sm transition',
                                            'border-orange-300 bg-orange-50/60 dark:border-orange-500/40 dark:bg-orange-500/10' => $leaveTypeId === $type['id'],
                                            'border-zinc-200 hover:border-zinc-300 dark:border-white/10' => $leaveTypeId !== $type['id'],
                                        ]) data-reason="{{ $type['code'] }}">
                                            <input type="radio" name="leave-reason" value="{{ $type['id'] }}" wire:model.live="leaveTypeId" class="accent-orange-500">
                                            <span class="font-medium text-zinc-900 dark:text-white">{{ $type['name'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-1.5 text-xs text-zinc-500">Every reason uses your Earned Leave balance.</p>
                                @error('leaveTypeId')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </fieldset>
                        @endif
                    @elseif(count($types) > 1)
                        {{-- Special Leave / Comp Off: each type has its own balance. --}}
                        <fieldset>
                            <legend class="{{ $label }}">{{ LeaveCategory::label($category) }} type <span class="text-rose-500">*</span></legend>
                            <div class="space-y-2" role="radiogroup">
                                @foreach($types as $type)
                                    @php $blockedType = $type['state'] === 'not_available'; @endphp
                                    <label wire:key="type-{{ $type['id'] }}" @class([
                                        'flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 transition',
                                        'border-orange-300 bg-orange-50/60 dark:border-orange-500/40 dark:bg-orange-500/10' => $leaveTypeId === $type['id'],
                                        'border-zinc-200 hover:border-zinc-300 dark:border-white/10' => $leaveTypeId !== $type['id'],
                                    ]) data-subtype="{{ $type['code'] }}">
                                        <input type="radio" name="leave-subtype" value="{{ $type['id'] }}" wire:model.live="leaveTypeId" class="accent-orange-500">
                                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $type['name'] }}</span>
                                        <span @class(['shrink-0 text-xs font-semibold', 'text-rose-600 dark:text-rose-400' => $blockedType, 'text-emerald-600 dark:text-emerald-400' => ! $blockedType])>
                                            {{ match ($type['state']) { 'not_available' => 'Not available', 'unlimited' => 'As per policy', 'no_balance' => 'No balance required', default => $fmt($type['available']).' days available' } }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            @error('leaveTypeId')<p class="mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </fieldset>
                    @endif

                    @if($selected && ! in_array($category, [LeaveCategory::EARNED, LeaveCategory::UNPAID], true))
                        <div class="rounded-xl border border-sky-100 bg-sky-50/60 p-4 dark:border-sky-500/20 dark:bg-sky-500/5" data-selected-balance="{{ $selected['code'] }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $selected['name'] }}</p>
                                <p class="text-sm font-bold text-sky-700 dark:text-sky-300">{{ $selected['unlimited'] ? 'As per policy' : $fmt($selected['available']).' days available' }}</p>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">Used {{ $fmt($selected['used']) }} · Pending {{ $fmt($selected['pending']) }}</p>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label for="rl-start" class="{{ $label }}">Start date <span class="text-rose-500">*</span></label>
                            <input id="rl-start" type="date" wire:model.live="startDate" class="{{ $input }}">
                            @error('startDate')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="rl-end" class="{{ $label }}">End date <span class="text-rose-500">*</span></label>
                            <input id="rl-end" type="date" wire:model.live="endDate" @disabled($isHalfDay) min="{{ $startDate }}" class="{{ $input }} disabled:opacity-60">
                            @error('endDate')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    @if($eval)
                        <div class="rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-white/5" data-day-breakdown>
                            <div class="flex items-center justify-between">
                                <span class="text-zinc-600 dark:text-zinc-300">Leave charged</span>
                                <span class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($eval['charged_days']) }} {{ \Illuminate\Support\Str::plural('day', $eval['charged_days']) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $eval['calendar_days'] }} calendar {{ \Illuminate\Support\Str::plural('day', $eval['calendar_days']) }}
                                @if($eval['weekly_off_days']) · {{ $eval['weekly_off_days'] }} weekly off @endif
                                @if($eval['holiday_days']) · {{ $eval['holiday_days'] }} {{ \Illuminate\Support\Str::plural('holiday', $eval['holiday_days']) }} ({{ implode(', ', $eval['holidays']) }}) @endif
                                @if($eval['shutdown_days']) · {{ $eval['shutdown_days'] }} company shutdown @endif
                            </p>
                        </div>
                    @endif

                    @if($eval['allow_half_day'] ?? false)
                        <div class="rounded-xl border border-zinc-200 px-3 py-2.5 dark:border-white/10">
                            <label class="flex items-center gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                <input type="checkbox" wire:model.live="isHalfDay" class="rounded accent-orange-500"> Half-day leave <span class="font-normal text-zinc-500">(deducts 0.5 days)</span>
                            </label>
                            @if($isHalfDay)
                                <div class="mt-2 flex gap-4 pl-6 text-sm">
                                    <label class="flex items-center gap-1.5"><input type="radio" wire:model.live="halfDayPeriod" value="first_half" class="accent-orange-500"> First half</label>
                                    <label class="flex items-center gap-1.5"><input type="radio" wire:model.live="halfDayPeriod" value="second_half" class="accent-orange-500"> Second half</label>
                                </div>
                            @endif
                        </div>
                    @endif

                    <div>
                        <label for="rl-reason" class="{{ $label }}">Reason <span class="text-rose-500">*</span></label>
                        <textarea id="rl-reason" wire:model.blur="reason" rows="3" class="{{ $input }}" placeholder="Why do you need this time off?"></textarea>
                        @error('reason')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="rl-remarks" class="{{ $label }}">Additional remarks <span class="font-normal text-zinc-400">(optional)</span></label>
                        <textarea id="rl-remarks" wire:model.blur="remarks" rows="2" class="{{ $input }}" placeholder="Anything else for your approver"></textarea>
                    </div>

                    <div>
                        <p class="{{ $label }}">
                            Attachment
                            <span class="font-normal {{ ($eval['attachment_required'] ?? false) ? 'text-rose-600' : 'text-zinc-400' }}">
                                ({{ ($eval['attachment_required'] ?? false) ? 'Required' : ($eval['attachment_rule'] ?? 'Optional') }})
                            </span>
                        </p>
                        @if($attachment)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-white/10" data-attachment-selected>
                                <span class="flex min-w-0 items-center gap-2"><flux:icon.paper-clip class="size-4 shrink-0 text-zinc-400" /><span class="truncate">{{ $attachment->getClientOriginalName() }}</span></span>
                                <button type="button" wire:click="removeAttachment" class="shrink-0 text-xs font-semibold text-rose-600 hover:underline">Remove</button>
                            </div>
                        @else
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-zinc-300 px-3 py-2.5 text-sm text-zinc-600 hover:border-orange-300 dark:border-white/15 dark:text-zinc-300">
                                <flux:icon.arrow-up-tray class="size-4 text-orange-500" /> Choose file
                                <input type="file" wire:model="attachment" accept=".pdf,.jpg,.jpeg,.png" class="sr-only">
                            </label>
                        @endif
                        <div wire:loading wire:target="attachment" class="mt-1 text-xs text-orange-600">Uploading…</div>
                        <p class="mt-1 text-xs text-zinc-400">PDF, JPG or PNG — max 5 MB</p>
                        @error('attachment')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>

                    {{-- Leave Assistant: this subtype only. --}}
                    @if($eval && $eval['charged_days'] > 0)
                        <div class="rounded-xl border border-orange-100 bg-orange-50/50 p-4 text-sm dark:border-orange-500/20 dark:bg-orange-500/5" data-leave-assistant>
                            <p class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400"><flux:icon.sparkles class="size-3.5" /> Leave Assistant</p>
                            <ul class="space-y-1 text-zinc-700 dark:text-zinc-300">
                                @if($eval['uses_balance'])
                                    <li>You have {{ $fmt(max(0, $eval['available'])) }} days {{ $eval['balance_type'] }} available.</li>
                                    <li>This request uses {{ $fmt($eval['charged_days']) }} {{ \Illuminate\Support\Str::plural('day', $eval['charged_days']) }}.</li>
                                    @if(! $eval['blocked'])<li>After approval, {{ $fmt($eval['remaining_after']) }} {{ \Illuminate\Support\Str::plural('day', $eval['remaining_after']) }} will remain.</li>@endif
                                @elseif($eval['category'] === LeaveCategory::UNPAID)
                                    <li>Unpaid leave needs no balance. {{ $fmt($eval['unpaid_days']) }} {{ \Illuminate\Support\Str::plural('day', $eval['unpaid_days']) }} will be deducted from your pay.</li>
                                @endif
                                @foreach($eval['warnings'] as $warning)<li class="text-amber-700 dark:text-amber-300">{{ $warning }}</li>@endforeach
                                @foreach($eval['errors'] as $error)<li class="text-rose-600 dark:text-rose-400">{{ $error }}</li>@endforeach
                                <li>Attachment: {{ $eval['attachment_required'] ? 'required' : 'not required' }}.</li>
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            {{-- STEP 3 — review --}}
            @if($step === 3 && $eval)
                <div class="space-y-4" data-review>
                    <div class="rounded-xl border border-zinc-200 dark:border-white/10">
                        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Leave details</h3>
                            <button type="button" wire:click="editStep(2)" class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 hover:underline"><flux:icon.pencil-square class="size-3.5" /> Edit</button>
                        </div>
                        <dl class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                            @foreach([
                                'Category' => LeaveCategory::label($category).($category === LeaveCategory::EARNED ? ' (Paid Leave)' : ''),
                                ($category === LeaveCategory::EARNED ? 'Reason type' : 'Leave type') => $eval['leave_type'],
                                'From' => $date($startDate),
                                'To' => $date($isHalfDay ? $startDate : $endDate),
                                'Working days' => $fmt($eval['charged_days']).($isHalfDay ? ' ('.($halfDayPeriod === 'first_half' ? 'first' : 'second').' half)' : ''),
                                'Reason' => $reason,
                                'Attachment' => $attachment?->getClientOriginalName() ?? 'None',
                            ] as $k => $v)
                                <div class="flex gap-4 px-4 py-2"><dt class="w-28 shrink-0 text-zinc-500">{{ $k }}</dt><dd class="min-w-0 flex-1 break-words text-zinc-900 dark:text-white">{{ $v }}</dd></div>
                            @endforeach
                        </dl>
                    </div>

                    @if($eval['uses_balance'])
                        <div class="rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm dark:border-orange-500/20 dark:bg-orange-500/5" data-balance-impact>
                            <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Balance impact (after approval)</h3>
                            <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-300">{{ $eval['balance_type'] }} available before</span><span class="tabular-nums">{{ $fmt($eval['available']) }} days</span></div>
                            <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-300">Requested</span><span class="tabular-nums text-rose-600">−{{ $fmt($eval['paid_days']) }} days</span></div>
                            @if($eval['lwp_days'] > 0)
                                <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-300">Leave Without Pay</span><span class="tabular-nums text-amber-700 dark:text-amber-300">{{ $fmt($eval['lwp_days']) }} days</span></div>
                            @endif
                            <div class="mt-1 flex justify-between border-t border-orange-100 pt-1 font-semibold dark:border-orange-500/20"><span>Remaining after approval</span><span class="tabular-nums">{{ $fmt($eval['remaining_after']) }} days</span></div>
                        </div>
                    @endif

                    <div class="rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-white/5" data-approval-path>
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Approval path</p>
                        <p class="mt-1 text-zinc-900 dark:text-white">
                            {{ collect($eval['approval_steps'])->map(fn ($s) => ['manager' => 'Reporting Manager', 'hr' => 'HR'][$s] ?? ucfirst($s))->implode(' → ') }}
                        </p>
                    </div>
                </div>
            @endif

            {{-- STEP 4 — submitted --}}
            @if($step === 4 && $this->submitted)
                @php $r = $this->submitted; @endphp
                <div class="py-6 text-center" data-submitted>
                    <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                        <flux:icon.check class="size-8" />
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-zinc-900 dark:text-white">Leave Request Submitted</h3>
                    <p class="mt-1 text-sm text-zinc-500">Your leave application has been submitted for review.</p>
                </div>
                <dl class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-white/10">
                    @foreach([
                        'Request ID' => 'LR-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT),
                        'Leave type' => $r->leaveType?->name,
                        'Dates' => $date($r->start_date?->toDateString()).($r->start_date?->ne($r->end_date) ? ' – '.$date($r->end_date?->toDateString()) : ''),
                        'Total days' => $fmt($r->days),
                    ] as $k => $v)
                        <div class="flex gap-4 px-4 py-2"><dt class="w-28 shrink-0 text-zinc-500">{{ $k }}</dt><dd class="text-zinc-900 dark:text-white">{{ $v }}</dd></div>
                    @endforeach
                    <div class="flex gap-4 px-4 py-2"><dt class="w-28 shrink-0 text-zinc-500">Status</dt><dd><span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Pending Approval</span></dd></div>
                </dl>
                <p class="mt-4 flex gap-2 rounded-xl bg-sky-50 px-3 py-2.5 text-sm text-sky-800 dark:bg-sky-500/10 dark:text-sky-200"><flux:icon.information-circle class="mt-0.5 size-4 shrink-0" /> You will be notified once your leave request is reviewed.</p>
            @endif
        </div>

        {{-- Footer: always reachable --}}
        <footer class="flex shrink-0 flex-col-reverse gap-2 border-t border-zinc-100 bg-zinc-50/80 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:items-center sm:justify-end dark:border-zinc-800 dark:bg-zinc-900/80">
            @if($step === 4)
                <flux:button wire:click="close" variant="ghost">Close</flux:button>
                <flux:button wire:click="viewMyRequests" variant="primary">View My Requests</flux:button>
            @else
                @if($step === 1)
                    <flux:button wire:click="close" variant="ghost">Cancel</flux:button>
                @else
                    <flux:button wire:click="back" variant="ghost">Back</flux:button>
                @endif
                @if($step < 3)
                    <flux:button wire:click="next" variant="primary" icon-trailing="arrow-right" wire:loading.attr="disabled" wire:target="next,attachment">Next</flux:button>
                @else
                    <flux:button wire:click="submit" variant="primary" wire:loading.attr="disabled" wire:target="submit">Submit Request</flux:button>
                @endif
            @endif
        </footer>
    </div>
</div>
@endif
</div>
