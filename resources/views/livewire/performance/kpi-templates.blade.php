<flux:main class="bg-zinc-50 p-6 dark:bg-zinc-950 min-h-screen">

    {{-- Header --}}
    <div class="pulse-page-header mb-6">
        <div>
            <h1 class="pulse-page-title">KPI Templates</h1>
            <p class="pulse-page-subtitle">Build and manage KPI frameworks for roles, departments, and designations</p>
        </div>
        <flux:button wire:click="newTemplate" variant="primary" icon="plus">New Template</flux:button>
    </div>

    <div class="flex gap-6 items-start">

        {{-- ── Sidebar: Template List ─────────────────────────────────────── --}}
        <div class="w-72 shrink-0 space-y-2">
            @forelse($templates as $tpl)
                <div
                    wire:click="selectTemplate({{ $tpl->id }})"
                    @class([
                        'group cursor-pointer rounded-xl border p-4 transition-all',
                        'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40 ring-1 ring-indigo-500' => $selectedTemplateId === $tpl->id,
                        'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-300 hover:shadow-sm' => $selectedTemplateId !== $tpl->id,
                    ])
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-semibold text-sm text-zinc-900 dark:text-white truncate">{{ $tpl->name }}</div>
                            <div class="text-xs text-zinc-400 font-mono mt-0.5">{{ $tpl->code }}</div>
                        </div>
                        @if($tpl->is_active)
                            <span class="shrink-0 px-1.5 py-0.5 text-[10px] font-bold rounded bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400">ACTIVE</span>
                        @else
                            <span class="shrink-0 px-1.5 py-0.5 text-[10px] font-bold rounded bg-zinc-100 text-zinc-500 dark:bg-zinc-800">INACTIVE</span>
                        @endif
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-[10px] text-zinc-400">
                        <span class="flex items-center gap-1">
                            <flux:icon.squares-2x2 class="size-3" />{{ $tpl->categories_count }} categories
                        </span>
                        <span class="flex items-center gap-1">
                            <flux:icon.list-bullet class="size-3" />{{ $tpl->components_count }} components
                        </span>
                        <span class="flex items-center gap-1">
                            <flux:icon.arrow-path class="size-3" />{{ Str::title(str_replace('_', ' ', $tpl->cycle_type)) }}
                        </span>
                    </div>
                    {{-- Actions --}}
                    <div class="mt-3 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                        <flux:button size="xs" variant="ghost" icon="rocket-launch" wire:click.stop="openLaunchCycle({{ $tpl->id }})">Launch Cycle</flux:button>
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click.stop="confirmDelete({{ $tpl->id }})" class="text-red-500 hover:text-red-600">Delete</flux:button>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-6 text-center">
                    <flux:icon.clipboard-document-list class="size-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                    <p class="text-sm text-zinc-400">No templates yet.</p>
                    <p class="text-xs text-zinc-400 mt-1">Click "New Template" to get started.</p>
                </div>
            @endforelse
        </div>

        {{-- ── Detail Panel ───────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0">
            @if($showForm || $selectedTemplateId !== null || count($categories) > 0 || $name !== '')

            <div class="pulse-card space-y-6">

                {{-- Template meta --}}
                <div>
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white mb-4">
                        {{ $selectedTemplateId ? 'Edit Template' : 'New Template' }}
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Template Name <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="name" placeholder="e.g. Developer KPI Template" />
                            <flux:error name="name" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Code <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="code" placeholder="e.g. DEV_KPI" class="font-mono" />
                            <flux:error name="code" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Applies To</flux:label>
                            <flux:select wire:model.live="applies_to_type">
                                <flux:select.option value="global">Global (All Employees)</flux:select.option>
                                <flux:select.option value="department">Department</flux:select.option>
                                <flux:select.option value="designation">Designation</flux:select.option>
                                <flux:select.option value="role">Role</flux:select.option>
                                <flux:select.option value="employment_type">Employment Type</flux:select.option>
                                <flux:select.option value="branch">Branch / Office</flux:select.option>
                            </flux:select>
                        </flux:field>
                        @if($applies_to_type === 'department')
                            <flux:field>
                                <flux:label>Department</flux:label>
                                <flux:select wire:model="applies_to_id">
                                    <flux:select.option value="">— Select Department —</flux:select.option>
                                    @foreach($departments as $dept)
                                        <flux:select.option value="{{ $dept->id }}">{{ $dept->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                        @endif
                        <flux:field>
                            <flux:label>Review Cycle</flux:label>
                            <flux:select wire:model="cycle_type">
                                <flux:select.option value="monthly">Monthly</flux:select.option>
                                <flux:select.option value="quarterly">Quarterly</flux:select.option>
                                <flux:select.option value="half_yearly">Half Yearly</flux:select.option>
                                <flux:select.option value="annual">Annual</flux:select.option>
                                <flux:select.option value="custom">Custom</flux:select.option>
                            </flux:select>
                        </flux:field>
                        <div class="flex items-center gap-3 pt-6">
                            <flux:checkbox wire:model="is_active" id="is_active" />
                            <label for="is_active" class="text-sm font-medium text-zinc-700 dark:text-zinc-300 cursor-pointer">Active Template</label>
                        </div>
                    </div>
                    <flux:field class="mt-4">
                        <flux:label>Description</flux:label>
                        <flux:textarea wire:model="description" rows="2" placeholder="Optional description…" />
                    </flux:field>
                </div>

                <hr class="border-zinc-100 dark:border-zinc-800" />

                {{-- Categories & Components builder --}}
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="font-bold text-zinc-900 dark:text-white">Categories & Components</h4>
                            <p class="text-xs text-zinc-400 mt-0.5">Component weights must total exactly 100%</p>
                        </div>
                        <div class="flex items-center gap-3">
                            {{-- Weight meter --}}
                            @php $w = $totalWeight; @endphp
                            <div @class([
                                'text-sm font-bold tabular-nums',
                                'text-green-600' => abs($w - 100) < 0.01,
                                'text-amber-500' => $w > 0 && abs($w - 100) >= 0.01 && $w < 100,
                                'text-red-500' => $w > 100,
                                'text-zinc-400' => $w == 0,
                            ])>
                                {{ $w }}% / 100%
                            </div>
                            <flux:button type="button" wire:click="addCategory" size="sm" icon="plus">Add Category</flux:button>
                        </div>
                    </div>

                    @error('categories')
                        <div class="mb-3 p-3 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-sm text-red-600 dark:text-red-400">
                            {{ $message }}
                        </div>
                    @enderror

                    @if(count($categories) === 0)
                        <div class="rounded-xl border-2 border-dashed border-zinc-200 dark:border-zinc-700 p-8 text-center">
                            <flux:icon.squares-2x2 class="size-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <p class="text-sm text-zinc-400">No categories yet. Click "Add Category" to build your KPI framework.</p>
                        </div>
                    @endif

                    <div class="space-y-4">
                        @foreach($categories as $ci => $cat)
                            <div wire:key="category-{{ $ci }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 overflow-hidden">
                                {{-- Category header --}}
                                <div class="flex items-center gap-3 px-4 py-3 border-b border-zinc-200 dark:border-zinc-700">
                                    <input
                                        type="color"
                                        wire:model="categories.{{ $ci }}.color"
                                        class="size-7 rounded cursor-pointer border border-zinc-200 dark:border-zinc-700"
                                    />
                                    <input
                                        wire:model="categories.{{ $ci }}.name"
                                        placeholder="Category name (e.g. Technical)"
                                        class="flex-1 bg-transparent text-sm font-semibold text-zinc-900 dark:text-white outline-none placeholder:text-zinc-400"
                                    />
                                    @error('categories.'.$ci.'.name')
                                        <span class="text-xs text-red-500">{{ $message }}</span>
                                    @enderror
                                    <input
                                        wire:model="categories.{{ $ci }}.code"
                                        placeholder="Code"
                                        class="w-20 bg-transparent text-xs font-mono text-zinc-500 outline-none placeholder:text-zinc-300 dark:placeholder:text-zinc-600"
                                    />
                                    <flux:button type="button" wire:click="addComponent({{ $ci }})" size="xs" variant="ghost" icon="plus">Add Component</flux:button>
                                    <button type="button" wire:click="removeCategory({{ $ci }})" class="text-zinc-400 hover:text-red-500 transition-colors">
                                        <flux:icon.trash class="size-4" />
                                    </button>
                                </div>

                                {{-- Components --}}
                                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse($cat['components'] ?? [] as $ki => $comp)
                                        <div wire:key="component-{{ $ci }}-{{ $ki }}" class="grid grid-cols-12 gap-3 px-4 py-3 items-center">
                                            {{-- Name --}}
                                            <div class="col-span-4">
                                                <input
                                                    wire:model="categories.{{ $ci }}.components.{{ $ki }}.name"
                                                    placeholder="Component name"
                                                    class="w-full bg-transparent text-sm text-zinc-900 dark:text-white outline-none placeholder:text-zinc-400"
                                                />
                                                @error('categories.'.$ci.'.components.'.$ki.'.name')
                                                    <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            {{-- Scoring type --}}
                                            <div class="col-span-3">
                                                <select
                                                    wire:model="categories.{{ $ci }}.components.{{ $ki }}.scoring_type"
                                                    class="w-full text-xs bg-zinc-100 dark:bg-zinc-800 border-0 rounded-lg px-2 py-1.5 text-zinc-700 dark:text-zinc-300 outline-none"
                                                >
                                                    <option value="manual">Manual</option>
                                                    <option value="auto_attendance">Auto (Attendance)</option>
                                                    <option value="auto_punctuality">Auto (Punctuality)</option>
                                                    <option value="auto_leave">Auto (Leave)</option>
                                                    <option value="auto_productivity">Auto (Productivity)</option>
                                                    <option value="auto_shift_compliance">Auto (Shift)</option>
                                                </select>
                                            </div>
                                            {{-- Weight % --}}
                                            <div class="col-span-2 flex items-center gap-1">
                                                <input
                                                    type="number"
                                                    wire:model.live="categories.{{ $ci }}.components.{{ $ki }}.weight_percent"
                                                    min="0" max="100" step="0.5"
                                                    class="w-full text-xs text-right bg-zinc-100 dark:bg-zinc-800 border-0 rounded-lg px-2 py-1.5 text-zinc-900 dark:text-white outline-none"
                                                />
                                                <span class="text-xs text-zinc-400 shrink-0">%</span>
                                                @error('categories.'.$ci.'.components.'.$ki.'.weight_percent')
                                                    <p class="text-xs text-red-500">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            {{-- Max score --}}
                                            <div class="col-span-2 flex items-center gap-1">
                                                <input
                                                    type="number"
                                                    wire:model="categories.{{ $ci }}.components.{{ $ki }}.max_score"
                                                    min="1" max="100"
                                                    class="w-full text-xs text-right bg-zinc-100 dark:bg-zinc-800 border-0 rounded-lg px-2 py-1.5 text-zinc-900 dark:text-white outline-none"
                                                />
                                                <span class="text-xs text-zinc-400 shrink-0">max</span>
                                            </div>
                                            {{-- Remove --}}
                                            <div class="col-span-1 flex justify-end">
                                                <button type="button" wire:click="removeComponent({{ $ci }}, {{ $ki }})" class="text-zinc-300 hover:text-red-500 transition-colors">
                                                    <flux:icon.x-mark class="size-4" />
                                                </button>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="px-4 py-3 text-xs text-zinc-400 italic">
                                            No components yet — click "Add Component" above.
                                        </div>
                                    @endforelse
                                </div>

                                {{-- Category weight subtotal --}}
                                @php
                                    $catWeight = collect($cat['components'] ?? [])->sum(fn ($c) => (float)($c['weight_percent'] ?? 0));
                                @endphp
                                <div class="px-4 py-2 bg-white dark:bg-zinc-950 flex justify-end text-xs text-zinc-400 border-t border-zinc-100 dark:border-zinc-800">
                                    Category total: <span class="ml-1 font-semibold text-zinc-600 dark:text-zinc-300">{{ $catWeight }}%</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Save button --}}
                <div class="flex justify-end gap-3 pt-2">
                    @if($selectedTemplateId)
                        <flux:button wire:click="openLaunchCycle({{ $selectedTemplateId }})" variant="ghost" icon="rocket-launch">Launch Cycle</flux:button>
                    @endif
                    <flux:button type="button" wire:click="cancelForm" variant="ghost">Cancel</flux:button>
                    <flux:button type="button" wire:click="save" wire:target="save" variant="primary" wire:loading.attr="disabled" icon="check">
                        <span wire:loading.remove>{{ $selectedTemplateId ? 'Update Template' : 'Save Template' }}</span>
                        <span wire:loading>Saving…</span>
                    </flux:button>
                </div>
            </div>

            @else

            {{-- Empty state --}}
            <div class="pulse-card flex flex-col items-center justify-center py-20 text-center">
                <div class="size-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 flex items-center justify-center mb-4">
                    <flux:icon.chart-bar class="size-8 text-indigo-500" />
                </div>
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-1">Select or Create a Template</h3>
                <p class="text-sm text-zinc-400 max-w-sm">
                    KPI templates define the scoring framework for your employees. Select one from the left or create a new one.
                </p>
                <flux:button wire:click="newTemplate" variant="primary" icon="plus" class="mt-6">New Template</flux:button>
            </div>

            @endif
        </div>
    </div>

    {{-- ── Launch Cycle Modal ──────────────────────────────────────────────── --}}
    <flux:modal wire:model="showLaunchModal" class="max-w-lg">
        <div class="space-y-6 p-6">
            <div>
                <flux:heading size="lg">Launch Performance Cycle</flux:heading>
                <flux:subheading>This will activate KPI tracking for all applicable employees.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Cycle Name <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="cycleName" placeholder="e.g. Q2 Apr–Jun 2026" />
                    <flux:error name="cycleName" />
                </flux:field>
                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Start Date <span class="text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="cycleStartDate" />
                        <flux:error name="cycleStartDate" />
                    </flux:field>
                    <flux:field>
                        <flux:label>End Date <span class="text-red-500">*</span></flux:label>
                        <flux:input type="date" wire:model="cycleEndDate" />
                        <flux:error name="cycleEndDate" />
                    </flux:field>
                </div>
                <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">Review Deadlines (optional)</p>
                <div class="grid grid-cols-3 gap-3">
                    <flux:field>
                        <flux:label>Self Review</flux:label>
                        <flux:input type="date" wire:model="cycleSelfDeadline" />
                    </flux:field>
                    <flux:field>
                        <flux:label>Manager Review</flux:label>
                        <flux:input type="date" wire:model="cycleManagerDeadline" />
                    </flux:field>
                    <flux:field>
                        <flux:label>HR Review</flux:label>
                        <flux:input type="date" wire:model="cycleHrDeadline" />
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="$set('showLaunchModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="launchCycle" variant="primary" icon="rocket-launch">Launch Cycle</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ── Delete Confirm Modal ────────────────────────────────────────────── --}}
    <flux:modal wire:model="showDeleteConfirm" class="max-w-sm">
        <div class="space-y-4 p-6">
            <div>
                <flux:heading size="lg">Delete Template?</flux:heading>
                <flux:subheading>This cannot be undone. Templates with active cycles cannot be deleted.</flux:subheading>
            </div>
            @error('delete')
                <p class="text-sm text-red-500">{{ $message }}</p>
            @enderror
            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="deleteTemplate" variant="danger">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
