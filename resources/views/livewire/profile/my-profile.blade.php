@php use App\Services\Profile\ProfileFieldRegistry as Registry; @endphp

<flux:main class="bg-zinc-50 p-4 space-y-5 sm:p-6 dark:bg-zinc-950">

    {{-- Hero --}}
    <x-profile.hero :employee="$employee" :completion="$completion" :can-edit-photo="true" />

    <div wire:loading wire:target="photo" class="text-xs font-semibold text-orange-500">Uploading photo…</div>
    @error('photo') <p class="text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-employee.kpi-card label="Attendance" :value="$kpis['attendance']" suffix="%" icon="calendar-days" tone="emerald" sub="This month" />
        <x-employee.kpi-card label="Leave balance" :value="$kpis['leave']" icon="sun" tone="blue" sub="Days available" />
        <x-employee.kpi-card label="Comp off" :value="$kpis['comp_off']" icon="clock" tone="violet" sub="Days earned" />
        <x-employee.kpi-card label="Attendance score" :value="$kpis['score'] ?? '—'" icon="sparkles" tone="orange" sub="Avg this month" />
        <x-employee.kpi-card label="Experience" :value="$kpis['tenure']" icon="briefcase" tone="zinc" sub="With the company" />
    </div>

    {{-- Pending banner — only when there is something to act on --}}
    @if($pending->isNotEmpty())
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-500/25 dark:bg-amber-500/10">
            <flux:icon.clock class="size-4 shrink-0 text-amber-500" />
            <p class="flex-1 text-sm text-amber-800 dark:text-amber-300">
                <span class="font-bold">{{ $pending->count() }}</span>
                {{ Str::plural('change', $pending->count()) }} awaiting HR review —
                {{ $pending->map(fn ($r) => $r->fieldLabel())->join(', ', ' and ') }}.
            </p>
            <flux:button wire:click="setTab('requests')" size="xs" variant="ghost">View</flux:button>
        </div>
    @endif

    {{-- Tabs — hand-rolled: flux:tabs is a Pro component and isn't installed --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="flex min-w-max gap-1 border-b border-zinc-200 dark:border-white/10">
            @foreach($this::TABS as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    @class([
                        'relative px-4 py-2.5 text-sm font-semibold transition-colors whitespace-nowrap',
                        'text-orange-600 dark:text-orange-400' => $activeTab === $key,
                        'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $activeTab !== $key,
                    ])
                >
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

    {{-- Panels --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">

            @if($activeTab === 'overview')
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" icon="user" />
                <x-profile.field-group group="contact" :employee="$employee" :pending="$pending" icon="phone" />

            @elseif($activeTab === 'personal')
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" icon="user" />
                <x-profile.field-group group="contact" :employee="$employee" :pending="$pending" icon="phone" />
                <x-profile.field-group group="financial" :employee="$employee" :pending="$pending" icon="banknotes"
                                       title="Bank &amp; statutory" />

            @elseif($activeTab === 'employment')
                <x-profile.field-group group="employment" :employee="$employee" :pending="$pending" icon="briefcase" />

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
                                        <span class="rounded-md px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide {{ $tone }}">
                                            {{ $request->status }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        <span class="line-through">{{ $request->old_value ?: 'empty' }}</span>
                                        <span class="mx-1 text-zinc-300">→</span>
                                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $request->new_value ?: 'empty' }}</span>
                                    </p>
                                    @if($request->reason)
                                        <p class="mt-1 text-xs italic text-zinc-400">“{{ $request->reason }}”</p>
                                    @endif
                                </div>

                                @if($request->isPending())
                                    <flux:button wire:click="withdrawRequest({{ $request->id }})"
                                                 wire:confirm="Withdraw this request?"
                                                 size="xs" variant="ghost" class="text-rose-600">Withdraw</flux:button>
                                @endif
                            </div>

                            <div class="mt-3">
                                <x-timeline :steps="$request->timelineSteps()" />
                            </div>
                        </div>
                    @empty
                        <div class="py-10 text-center">
                            <flux:icon.check-circle class="mx-auto size-8 text-zinc-200 dark:text-zinc-700" />
                            <p class="mt-2 text-sm text-zinc-500">No change requests yet.</p>
                            <p class="text-xs text-zinc-400">Fields marked “Request” open one for HR to review.</p>
                        </div>
                    @endforelse
                </x-employee.section-card>
            @endif
        </div>

        {{-- Context rail --}}
        <div class="space-y-5">
            @if($completion['missing'])
                <x-employee.section-card title="Finish your profile" icon="sparkles">
                    <p class="mb-3 text-xs text-zinc-500">
                        {{ count($completion['missing']) }} {{ Str::plural('field', count($completion['missing'])) }} still to fill in.
                    </p>
                    <div class="space-y-1.5">
                        @foreach(array_slice($completion['missing'], 0, 6) as $gap)
                            @if((Registry::get($gap['field'])['type'] ?? null) === 'image')
                                {{-- Media needs a real file picker, not a modal --}}
                                <label class="flex w-full cursor-pointer items-center justify-between rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                    <span class="text-zinc-700 dark:text-zinc-200">{{ $gap['label'] }}</span>
                                    <flux:icon.camera class="size-3.5 text-zinc-300" />
                                    <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                                </label>
                            @else
                                <button type="button"
                                        wire:click="{{ $gap['tier'] === Registry::TIER_EDITABLE ? "editField('{$gap['field']}')" : "requestField('{$gap['field']}')" }}"
                                        class="flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-zinc-50 dark:hover:bg-white/5">
                                    <span class="text-zinc-700 dark:text-zinc-200">{{ $gap['label'] }}</span>
                                    <flux:icon.arrow-right class="size-3.5 text-zinc-300" />
                                </button>
                            @endif
                        @endforeach
                    </div>
                </x-employee.section-card>
            @endif

            <x-employee.section-card title="Who can change what" icon="shield-check">
                <div class="space-y-2.5 text-xs">
                    <div class="flex gap-2">
                        <span class="mt-0.5 size-2 shrink-0 rounded-full bg-emerald-500"></span>
                        <p class="text-zinc-500"><b class="text-zinc-700 dark:text-zinc-200">You can edit</b> — saves straight away.</p>
                    </div>
                    <div class="flex gap-2">
                        <span class="mt-0.5 size-2 shrink-0 rounded-full bg-amber-500"></span>
                        <p class="text-zinc-500"><b class="text-zinc-700 dark:text-zinc-200">Needs HR approval</b> — your current value stays until it's approved.</p>
                    </div>
                    <div class="flex gap-2">
                        <span class="mt-0.5 size-2 shrink-0 rounded-full bg-zinc-400"></span>
                        <p class="text-zinc-500"><b class="text-zinc-700 dark:text-zinc-200">Managed by HR</b> — contact HR to change these.</p>
                    </div>
                </div>
            </x-employee.section-card>
        </div>
    </div>

    {{-- Edit modal (editable tier) --}}
    <flux:modal name="edit-field" class="max-w-md">
        @if($editingField)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Edit {{ Registry::label($editingField) }}</flux:heading>
                    <flux:subheading>This saves immediately.</flux:subheading>
                </div>

                @include('livewire.profile.partials.field-input', ['field' => $editingField])

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="closeFieldModal" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveField" variant="primary">Save</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Request modal (approval tier) --}}
    <flux:modal name="request-field" class="max-w-md">
        @if($editingField)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Request a change to {{ Registry::label($editingField) }}</flux:heading>
                    <flux:subheading>HR reviews this. Your current value stays until it's approved.</flux:subheading>
                </div>

                @include('livewire.profile.partials.field-input', ['field' => $editingField])

                <flux:textarea wire:model="requestReason" label="Reason (optional)" rows="2"
                               placeholder="e.g. moved house in June" />

                <div class="flex justify-end gap-2">
                    <flux:button x-on:click="$flux.modal('request-field').close()" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="submitRequest" variant="primary">Send to HR</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</flux:main>
