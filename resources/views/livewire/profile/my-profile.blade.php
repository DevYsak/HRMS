@php use App\Services\Profile\ProfileFieldRegistry as Registry; @endphp

<flux:main class="bg-zinc-50 p-4 space-y-5 sm:p-6 dark:bg-zinc-950">

    {{-- Hero --}}
    <x-profile.hero :employee="$employee" :completion="$completion" :can-edit-photo="$this->canEditPhoto()" />

    <div wire:loading wire:target="photo" class="text-xs font-semibold text-orange-500">Uploading photo…</div>
    @error('photo') <p class="text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-employee.kpi-card label="Attendance" :value="$kpis['attendance'] ?? '—'" :suffix="$kpis['attendance'] === null ? '' : '%'" icon="calendar-days" tone="emerald" sub="This month" />
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
    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">

            @if($activeTab === 'overview')
                {{-- Overview: who you are, how to reach you, your job and how complete
                     your profile is. Bank, statutory IDs and pay stay on Personal. --}}
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" icon="user" />
                <x-profile.field-group group="contact" :employee="$employee" :pending="$pending" icon="phone" />
                <x-profile.field-group group="employment" :employee="$employee" :pending="$pending" icon="briefcase" />
                <x-employee.section-card title="Profile completeness" icon="check-badge" data-profile-completeness>
                    <div class="flex flex-wrap items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $completion['percent'] }}% complete</span>
                                <span class="text-xs text-zinc-500">{{ count($completion['missing']) }} required {{ Str::plural('item', count($completion['missing'])) }} left</span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $completion['percent'] }}" aria-label="Profile completeness">
                                <div class="h-full rounded-full bg-orange-500" style="width: {{ $completion['percent'] }}%"></div>
                            </div>
                        </div>
                        @if(count($completion['missing']) > 0)
                            <flux:button size="sm" variant="primary" wire:click="setTab('personal')">Complete profile</flux:button>
                        @endif
                    </div>
                </x-employee.section-card>

            @elseif($activeTab === 'personal')
                <x-profile.field-group group="identity" :employee="$employee" :pending="$pending" icon="user" />
                <x-profile.field-group group="contact" :employee="$employee" :pending="$pending" icon="phone" />
                <x-profile.field-group group="financial" :employee="$employee" :pending="$pending" icon="banknotes"
                                       :title="'Bank & statutory'" />

                {{-- KYC documents: private, visible only to you and HR (View KYC Documents). --}}
                <div id="kyc-documents" class="scroll-mt-20">
                    <x-employee.section-card title="KYC documents" icon="identification">
                        <p class="mb-3 text-xs text-zinc-500">Proof documents are stored privately: only you and HR can open them.</p>
                        <div class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($kyc as $item)
                                <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="kyc-{{ $item['type'] }}">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5 text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                            {{ $item['label'] }}
                                            @if($item['requirement'] === \App\Models\ProfileFieldSetting::REQUIRED && ! $item['document'])
                                                <span class="rounded bg-rose-50 px-1 text-[9px] font-bold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">Required</span>
                                            @endif
                                        </div>
                                        @if($item['document'])
                                            <a href="{{ $item['url'] }}" target="_blank" class="text-xs font-semibold text-orange-600 hover:underline">{{ $item['document']->file_name }}</a>
                                            <span class="text-[11px] text-zinc-400">· @userDate($item['document']->created_at)</span>
                                        @else
                                            <span class="text-xs italic text-zinc-400">Not uploaded</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if($canUploadKyc)
                            <form wire:submit="uploadKyc" class="mt-3 flex flex-wrap items-end gap-2 border-t border-zinc-100 pt-3 dark:border-white/5">
                                <select wire:model="kycType" aria-label="Document type" class="w-full max-w-full rounded-lg border sm:w-auto border-zinc-200 bg-white py-1.5 pl-2 pr-7 text-xs dark:border-zinc-700 dark:bg-zinc-900">
                                    <option value="">Choose a document…</option>
                                    @foreach($kyc as $item)
                                        <option value="{{ $item['type'] }}">{{ $item['label'] }}</option>
                                    @endforeach
                                </select>
                                <input type="file" wire:model="kycFile" accept=".pdf,.jpg,.jpeg,.png" class="min-w-0 flex-1 text-xs">
                                <flux:button type="submit" size="sm" variant="primary" wire:loading.attr="disabled" wire:target="kycFile,uploadKyc">Upload</flux:button>
                            </form>
                            @error('kycType')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                            @error('kycFile')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                        @endif
                    </x-employee.section-card>
                </div>

            @elseif($activeTab === 'employment')
                <x-profile.field-group group="employment" :employee="$employee" :pending="$pending" icon="briefcase" :columns="2" />

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

        {{-- Context rail: content height, never stretched to the main column --}}
        <div class="space-y-5 self-start">
            @if($completion['missing'])
                @php $missingCount = count($completion['missing']); @endphp
                <x-employee.section-card title="Finish your profile" icon="sparkles" data-finish-profile>
                    <div class="-mt-2 mb-3">
                        <p class="text-xs text-zinc-500">{{ $completion['percent'] }}% complete · {{ $missingCount }} required {{ Str::plural('item', $missingCount) }} left</p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" aria-hidden="true">
                            <div class="h-full rounded-full bg-orange-500" style="width: {{ $completion['percent'] }}%"></div>
                        </div>
                    </div>
                    <ul class="-mx-2 divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach(array_slice($completion['missing'], 0, 6) as $gap)
                            @php
                                $isKyc = str_starts_with($gap['field'], 'kyc:');
                                $isImage = ! $isKyc && (Registry::get($gap['field'])['type'] ?? null) === 'image';
                                $rowIcon = $isKyc ? 'document-arrow-up' : ($isImage ? 'camera' : 'pencil-square');
                                $rowClass = 'flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left text-[13px] transition hover:bg-zinc-50 dark:hover:bg-white/5';
                            @endphp
                            <li wire:key="gap-{{ $gap['field'] }}">
                                @if($isKyc)
                                    <button type="button" wire:click="$set('activeTab', 'personal')" x-on:click="$nextTick(() => setTimeout(() => document.getElementById('kyc-documents')?.scrollIntoView({ behavior: 'smooth' }), 150))" class="{{ $rowClass }}">
                                @elseif($isImage)
                                    {{-- Media needs a real file picker, not a modal --}}
                                    <label class="{{ $rowClass }} cursor-pointer">
                                        <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                                @else
                                    <button type="button"
                                            wire:click="{{ $gap['tier'] === Registry::TIER_EDITABLE ? "editField('{$gap['field']}')" : "requestField('{$gap['field']}')" }}"
                                            class="{{ $rowClass }}">
                                @endif
                                        <flux:icon :name="$rowIcon" class="size-4 shrink-0 text-orange-500" />
                                        <span class="min-w-0 flex-1 truncate text-zinc-700 dark:text-zinc-200">{{ $gap['label'] }}</span>
                                        <flux:icon.chevron-right class="size-3.5 shrink-0 text-zinc-300" />
                                @if($isImage)
                                    </label>
                                @else
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if($missingCount > 6)
                        <p class="mt-2 text-[11px] text-zinc-400">and {{ $missingCount - 6 }} more on the Personal tab.</p>
                    @endif
                    @if(! empty($completion['submitted']))
                        <p class="mt-3 text-[11px] text-zinc-400">Sent to HR, awaiting approval: {{ collect($completion['submitted'])->pluck('label')->implode(', ') }}.</p>
                    @endif
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
    {{-- @close: X, Esc and backdrop clear the field and its errors too. --}}
    <flux:modal name="edit-field" class="max-w-md" @close="closeFieldModal">
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
    <flux:modal name="request-field" class="max-w-md" @close="closeRequestModal">
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
                    <flux:button wire:click="closeRequestModal" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="submitRequest" variant="primary">Send to HR</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</flux:main>
