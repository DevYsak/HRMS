@php use App\Services\Profile\ProfileFieldRegistry as Registry; @endphp

<flux:main class="bg-zinc-50 p-4 space-y-5 sm:p-6 dark:bg-zinc-950">

    {{-- Breadcrumb back to the directory --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $employee->user?->name ?? 'Profile' }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:button :href="route('employees.edit', $employee)" wire:navigate size="sm" variant="ghost" icon="adjustments-horizontal">
            Full record
        </flux:button>
    </div>

    {{-- Same hero as the employee's own page --}}
    <x-profile.hero :employee="$employee" :completion="$completion" />

    {{-- Same KPI row --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-employee.kpi-card label="Attendance" :value="$kpis['attendance']" suffix="%" icon="calendar-days" tone="emerald" sub="This month" />
        <x-employee.kpi-card label="Leave balance" :value="$kpis['leave']" icon="sun" tone="blue" sub="Days available" />
        <x-employee.kpi-card label="Comp off" :value="$kpis['comp_off']" icon="clock" tone="violet" sub="Days earned" />
        <x-employee.kpi-card label="Attendance score" :value="$kpis['score'] ?? '—'" icon="sparkles" tone="orange" sub="Avg this month" />
        <x-employee.kpi-card label="Experience" :value="$kpis['tenure']" icon="briefcase" tone="zinc" sub="With the company" />
    </div>

    {{-- Requests awaiting this reviewer --}}
    @if($pending->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/25 dark:bg-amber-500/10">
            <flux:icon.inbox-arrow-down class="size-4 shrink-0 text-amber-500" />
            <p class="flex-1 text-sm text-amber-800 dark:text-amber-300">
                <span class="font-bold">{{ $pending->count() }}</span>
                {{ Str::plural('change request', $pending->count()) }} from this employee needs a decision.
            </p>
            <flux:button wire:click="setTab('requests')" size="xs" variant="primary">Review</flux:button>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="flex min-w-max gap-1 border-b border-zinc-200 dark:border-white/10">
            @foreach($this::TABS as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    @class([
                        'relative px-4 py-2.5 text-sm font-semibold transition-colors whitespace-nowrap',
                        'text-orange-600 dark:text-orange-400' => $activeTab === $key,
                        'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== $key,
                    ])>
                    {{ $label }}
                    @if($key === 'requests' && $pending->isNotEmpty())
                        <span class="ml-1 inline-flex size-4 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">{{ $pending->count() }}</span>
                    @endif
                    @if($activeTab === $key)
                        <span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500"></span>
                    @endif
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">

            {{-- as-hr unlocks every registered field; the components are otherwise identical --}}
            @if($activeTab === 'overview')
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" :as-hr="true" icon="user" />
                <x-profile.field-group group="employment" :employee="$employee" :pending="$pending" :as-hr="true" icon="briefcase" />

            @elseif($activeTab === 'personal')
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" :as-hr="true" icon="user" />
                <x-profile.field-group group="contact" :employee="$employee" :pending="$pending" :as-hr="true" icon="phone" />

            @elseif($activeTab === 'employment')
                <x-profile.field-group group="employment" :employee="$employee" :pending="$pending" :as-hr="true" icon="briefcase" />

            @elseif($activeTab === 'financial')
                <x-profile.field-group group="financial" :employee="$employee" :pending="$pending" :as-hr="true"
                                       icon="banknotes" title="Bank &amp; statutory" />

            @elseif($activeTab === 'requests')
                <x-employee.section-card title="Change requests" icon="arrow-path">
                    @forelse($requests as $request)
                        <div class="border-b border-zinc-100 py-4 last:border-0 dark:border-white/5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $request->fieldLabel() }}</span>
                                        @php
                                            $tone = match($request->status) {
                                                'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                                                'rejected' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
                                                'cancelled' => 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400',
                                                default => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                                            };
                                        @endphp
                                        <span class="rounded-md px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide {{ $tone }}">{{ $request->status }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        <span class="line-through">{{ $request->old_value ?: 'empty' }}</span>
                                        <span class="mx-1 text-zinc-300">→</span>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $request->new_value ?: 'empty' }}</span>
                                    </p>
                                    <p class="mt-1 text-[11px] text-zinc-400">
                                        Raised by {{ $request->requestedBy?->name }} · {{ $request->created_at->diffForHumans() }}
                                    </p>
                                    @if($request->reason)
                                        <p class="mt-1 text-xs italic text-zinc-400">“{{ $request->reason }}”</p>
                                    @endif
                                </div>

                                @if($request->isPending())
                                    @can('approve_profile_changes')
                                        <flux:button wire:click="openReview({{ $request->id }})" size="xs" variant="primary">Review</flux:button>
                                    @endcan
                                @endif
                            </div>

                            <div class="mt-3">
                                <x-timeline :steps="$request->timelineSteps()" />
                            </div>
                        </div>
                    @empty
                        <div class="py-10 text-center">
                            <flux:icon.check-circle class="mx-auto size-8 text-zinc-200 dark:text-zinc-700" />
                            <p class="mt-2 text-sm text-zinc-500">No change requests from this employee.</p>
                        </div>
                    @endforelse
                </x-employee.section-card>
            @endif
        </div>

        {{-- Context rail --}}
        <div class="space-y-5">
            <x-employee.section-card title="Data quality" icon="shield-exclamation">
                @php $flags = $employee->dataFlags(); @endphp
                @if($flags)
                    <div class="space-y-2">
                        @foreach($flags as $flag)
                            <div class="flex items-start gap-2 text-xs">
                                <flux:icon.exclamation-triangle class="mt-0.5 size-3.5 shrink-0 text-amber-500" />
                                <span class="text-zinc-600 dark:text-zinc-300">{{ $flag }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-zinc-400">No outstanding data issues.</p>
                @endif

                @if($completion['missing'])
                    <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-white/5">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
                            {{ count($completion['missing']) }} {{ Str::plural('field', count($completion['missing'])) }} empty
                        </p>
                        <div class="space-y-1">
                            @foreach(array_slice($completion['missing'], 0, 6) as $gap)
                                <button type="button" wire:click="editField('{{ $gap['field'] }}')"
                                        class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                    <span class="text-zinc-700 dark:text-zinc-200">{{ $gap['label'] }}</span>
                                    <flux:icon.pencil class="size-3 text-zinc-300" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-employee.section-card>

            <x-employee.section-card title="You are editing as HR" icon="key">
                <p class="text-xs leading-relaxed text-zinc-500">
                    Every field here is writable, including those the employee sees as locked.
                    Each change is recorded in the audit trail against your name.
                </p>
            </x-employee.section-card>
        </div>
    </div>

    {{-- HR edit modal --}}
    <flux:modal name="hr-edit-field" class="max-w-md">
        @if($editingField)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Edit {{ Registry::label($editingField) }}</flux:heading>
                    <flux:subheading>
                        @if(Registry::isLocked($editingField))
                            Locked for the employee — you are changing it as HR.
                        @else
                            This saves immediately.
                        @endif
                    </flux:subheading>
                </div>

                @include('livewire.profile.partials.field-input', ['field' => $editingField])

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="closeFieldModal" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveField" variant="primary">Save</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Review modal --}}
    <flux:modal name="review-request" class="max-w-md">
        @if($reviewing)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Review {{ $reviewing->fieldLabel() }}</flux:heading>
                    <flux:subheading>Raised by {{ $reviewing->requestedBy?->name }}.</flux:subheading>
                </div>

                <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-3 text-sm dark:border-white/5 dark:bg-white/5">
                    <div class="flex items-center gap-2">
                        <span class="text-zinc-400 line-through">{{ $reviewing->old_value ?: 'empty' }}</span>
                        <flux:icon.arrow-right class="size-3.5 text-zinc-300" />
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $reviewing->new_value ?: 'empty' }}</span>
                    </div>
                    @if($reviewing->reason)
                        <p class="mt-2 text-xs italic text-zinc-500">“{{ $reviewing->reason }}”</p>
                    @endif
                </div>

                <flux:textarea wire:model="reviewComment" label="Comment" rows="2"
                               placeholder="Required when rejecting, so the employee knows why" />

                <div class="flex justify-end gap-2">
                    <flux:button x-on:click="$flux.modal('review-request').close()" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="rejectRequest" variant="danger">Reject</flux:button>
                    <flux:button wire:click="approveRequest" variant="primary">Approve</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</flux:main>
