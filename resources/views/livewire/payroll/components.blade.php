<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Salary Components</h1>
            <p class="pulse-page-subtitle">Define and manage earning and deduction structure</p>
        </div>
        <flux:button wire:click="create" variant="primary" icon="plus">Add Component</flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Earnings Section --}}
        <div class="space-y-4">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                <flux:icon.plus-circle class="size-4 text-green-500" /> Earnings
            </h3>
            <div class="pulse-card divide-y divide-zinc-50 dark:divide-zinc-800/60 p-0 overflow-hidden">
                @foreach($earnings as $comp)
                    <div class="p-4 flex items-center justify-between hover:bg-zinc-50/50 transition-colors dark:hover:bg-zinc-900/40">
                        <div class="flex items-center gap-4">
                            <div class="size-10 rounded-xl bg-green-50 text-green-600 flex items-center justify-center dark:bg-green-900/20">
                                <flux:icon.banknotes class="size-5" />
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $comp->name }}</div>
                                <div class="text-xs text-zinc-500">{{ match($comp->calculation_type ?? 'fixed') { 'percentage' => $comp->percentage_value.'% of '.str_replace('_',' ',$comp->percentage_basis ?? 'basic'), 'formula' => 'Formula: '.$comp->formula_expression, default => 'Fixed amount' } }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="font-bold text-zinc-900 dark:text-white">{{ number_format($comp->default_amount, 2) }}</div>
                                <div class="text-[10px] text-zinc-400 uppercase font-bold tracking-widest">Default</div>
                            </div>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item wire:click="edit({{ $comp->id }})" icon="pencil">Edit</flux:menu.item>
                                    <flux:menu.item wire:click="toggleActive({{ $comp->id }})" icon="{{ $comp->is_active ? 'eye-slash' : 'eye' }}">
                                        {{ $comp->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Deductions Section --}}
        <div class="space-y-4">
            <h3 class="text-sm font-bold text-zinc-400 uppercase tracking-wider flex items-center gap-2">
                <flux:icon.minus-circle class="size-4 text-red-500" /> Deductions
            </h3>
            <div class="pulse-card divide-y divide-zinc-50 dark:divide-zinc-800/60 p-0 overflow-hidden">
                @foreach($deductions as $comp)
                    <div class="p-4 flex items-center justify-between hover:bg-zinc-50/50 transition-colors dark:hover:bg-zinc-900/40">
                        <div class="flex items-center gap-4">
                            <div class="size-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center dark:bg-red-900/20">
                                <flux:icon.shield-check class="size-5" />
                            </div>
                            <div>
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $comp->name }}</div>
                                <div class="text-xs text-zinc-500">{{ match($comp->calculation_type ?? 'fixed') { 'percentage' => $comp->percentage_value.'% of '.str_replace('_',' ',$comp->percentage_basis ?? 'basic'), 'formula' => 'Formula: '.$comp->formula_expression, default => 'Fixed amount' } }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <div class="font-bold text-zinc-900 dark:text-white">{{ number_format($comp->default_amount, 2) }}</div>
                                <div class="text-[10px] text-zinc-400 uppercase font-bold tracking-widest">Default</div>
                            </div>
                            <flux:dropdown>
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                                <flux:menu>
                                    <flux:menu.item wire:click="edit({{ $comp->id }})" icon="pencil">Edit</flux:menu.item>
                                    <flux:menu.item wire:click="toggleActive({{ $comp->id }})" icon="{{ $comp->is_active ? 'eye-slash' : 'eye' }}">
                                        {{ $comp->is_active ? 'Deactivate' : 'Activate' }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Management Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showModal', false)"></div>
            <div class="relative w-full max-w-xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-6">
                <button type="button" @click="$wire.set('showModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div>
                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $editingId ? 'Edit Component' : 'New Salary Component' }}</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">Define how this earning or deduction behaves.</p>
                </div>
                <form wire:submit="save" class="space-y-5 max-h-[70vh] overflow-y-auto pr-1">
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="form.name" label="Component Name" placeholder="e.g. Basic Salary, Tax" required />
                        <flux:input wire:model="form.code" label="Code" placeholder="e.g. BASIC" description="Referenced by other components' formulas / percentage-of" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="form.type" label="Type">
                            <option value="earning">Earning (+)</option>
                            <option value="deduction">Deduction (-)</option>
                        </flux:select>
                        <flux:select wire:model="form.component_type" label="Component Type">
                            <option value="earning">Earning</option>
                            <option value="deduction">Deduction</option>
                            <option value="employer_contribution">Employer Contribution</option>
                        </flux:select>
                    </div>

                    <flux:select wire:model.live="form.calculation_type" label="Calculation Type">
                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage Of</option>
                        <option value="formula">Formula Based</option>
                    </flux:select>

                    @if($form['calculation_type'] === 'fixed')
                        <flux:input wire:model="form.default_amount" type="number" step="0.01" label="Default Amount" />
                    @elseif($form['calculation_type'] === 'percentage')
                        <div class="grid grid-cols-2 gap-4">
                            <flux:input wire:model="form.percentage_value" type="number" step="0.01" label="Percentage (%)" />
                            <flux:select wire:model.live="form.percentage_basis" label="Of">
                                <option value="basic">Basic Salary</option>
                                <option value="gross">Gross (running total)</option>
                                <option value="ctc">CTC</option>
                                <option value="component">Another Component</option>
                            </flux:select>
                        </div>
                        @if($form['percentage_basis'] === 'component')
                            <flux:select wire:model="form.percentage_of_component_id" label="Which component?">
                                <option value="">Select a component</option>
                                @foreach($availableComponents as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                @endforeach
                            </flux:select>
                        @endif
                    @else
                        <flux:textarea wire:model.live.debounce.400ms="form.formula_expression" label="Formula"
                            placeholder="e.g. BASIC * 0.4 + HRA" rows="2"
                            description="Arithmetic only (+ - * / and parentheses). Reference other components by their Code, in uppercase." />
                        @if($formulaError)
                            <p class="text-xs font-semibold text-rose-600">{{ $formulaError }}</p>
                        @elseif($formulaPreview !== null)
                            <p class="text-xs font-semibold text-emerald-600">Preview (using each component's default amount): {{ number_format($formulaPreview, 2) }}</p>
                        @endif
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="form.display_order" type="number" label="Display Order" description="Lower shows first" />
                    </div>

                    <div class="pt-2 space-y-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                        <flux:switch wire:model="form.is_taxable" label="Taxable" description="Included in income-tax computation" />
                        <flux:switch wire:model="form.is_pf_applicable" label="PF Applicable" description="Counts toward the Provident Fund wage" />
                        <flux:switch wire:model="form.is_esi_applicable" label="ESI Applicable" description="Counts toward the ESI wage" />
                        <flux:switch wire:model="form.is_active" label="Enabled" />
                    </div>
                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" @click="$wire.set('showModal', false)"
                            class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">
                            Cancel
                        </button>
                        <flux:button type="submit" variant="primary">{{ $editingId ? 'Update' : 'Create' }} Component</flux:button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</flux:main>
