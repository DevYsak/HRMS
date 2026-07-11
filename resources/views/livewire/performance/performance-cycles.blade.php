<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Performance Cycles</h1>
            <p class="pulse-page-subtitle">Template-driven review periods — activate a cycle to assign reviews &amp; KPIs</p>
        </div>
        <flux:button wire:click="create" variant="primary" icon="plus">New Cycle</flux:button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($cycles as $cycle)
            <div class="pulse-card relative overflow-hidden">
                <div class="absolute top-0 inset-x-0 h-1 bg-{{ $cycle->statusColor() === 'on_time' ? 'brand-500' : ($cycle->statusColor() === 'terminated' ? 'red-500' : 'zinc-300') }}"></div>

                <div class="flex justify-between items-start mb-1 mt-2">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-white">{{ $cycle->name }}</h3>
                    <flux:dropdown>
                        <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                        <flux:menu>
                            @if($cycle->status === 'draft')
                                <flux:menu.item wire:click="edit({{ $cycle->id }})" icon="pencil">Edit Cycle</flux:menu.item>
                                <flux:menu.item wire:click="activate({{ $cycle->id }})" icon="play" class="text-brand-600">Activate Now</flux:menu.item>
                            @elseif(! in_array($cycle->status, ['completed', 'locked']))
                                <flux:menu.item wire:click="complete({{ $cycle->id }})" icon="check-circle" class="text-red-600">Mark Completed</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
                <div class="mb-4 text-[11px] font-semibold uppercase tracking-widest text-zinc-400">{{ $cycle->template?->name ?? 'No template' }}</div>

                <div class="space-y-3 mb-6">
                    <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <flux:icon.calendar class="size-4" />
                        {{ $cycle->start_date->format('M d') }} – {{ $cycle->end_date->format('M d, Y') }}
                        <span class="ml-auto rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-[10px] font-bold uppercase text-zinc-500">{{ str_replace('_', ' ', $cycle->cycle_type) }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <flux:icon.users class="size-4" />
                        {{ $cycle->reviews_count }} {{ \Illuminate\Support\Str::plural('review', $cycle->reviews_count) }} assigned
                    </div>
                    @if($cycle->self_review_deadline)
                        <div class="flex items-center gap-2 text-xs text-zinc-400">
                            <flux:icon.clock class="size-3.5" />
                            Self-review due {{ $cycle->self_review_deadline->format('M d, Y') }}
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <span class="badge-{{ $cycle->statusColor() }}">{{ strtoupper($cycle->statusLabel()) }}</span>
                    @if($cycle->status === 'draft')
                        <span class="text-[10px] uppercase font-bold tracking-widest text-zinc-400">Not Visible</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="col-span-full pulse-card py-12 text-center text-zinc-500">
                No performance cycles yet. Create one from a template to start a review period.
            </div>
        @endforelse
    </div>

    {{-- Create / edit --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Performance Cycle' : 'New Performance Cycle' }}</flux:heading>
                <flux:subheading>Cycles start as drafts. Activating assigns reviews and KPIs to all applicable employees.</flux:subheading>
            </div>

            <x-clean-select model="templateId" label="Template" :required="true" placeholder="Select a template…" :live="false"
                :options="$templates->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()" />

            <flux:input wire:model="name" label="Cycle Name" placeholder="e.g. Q2 2026-27 Reviews" required />

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="startDate" type="date" label="Start Date" required />
                <flux:input wire:model="endDate" type="date" label="End Date" required />
            </div>

            <x-clean-select model="cycleType" label="Cycle Type" :live="false"
                :options="[
                    ['value' => 'monthly', 'label' => 'Monthly'],
                    ['value' => 'quarterly', 'label' => 'Quarterly'],
                    ['value' => 'half_yearly', 'label' => 'Half-Yearly'],
                    ['value' => 'annual', 'label' => 'Annual'],
                    ['value' => 'custom', 'label' => 'Custom'],
                ]" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:input wire:model="selfReviewDeadline" type="date" label="Self-review by" />
                <flux:input wire:model="managerReviewDeadline" type="date" label="Manager by" />
                <flux:input wire:model="hrReviewDeadline" type="date" label="HR by" />
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                <flux:button type="button" wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">{{ $editingId ? 'Save changes' : 'Create draft cycle' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
