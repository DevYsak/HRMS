<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Review Cycles</h1>
            <p class="pulse-page-subtitle">Manage company performance review periods</p>
        </div>
        <flux:button wire:click="create" variant="primary" icon="plus">Create Cycle</flux:button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($cycles as $cycle)
            <div class="pulse-card relative overflow-hidden">
                <div class="absolute top-0 inset-x-0 h-1 bg-{{ $cycle->statusColor() === 'on_time' ? 'brand-500' : ($cycle->statusColor() === 'terminated' ? 'red-500' : 'zinc-300') }}"></div>
                
                <div class="flex justify-between items-start mb-4 mt-2">
                    <h3 class="font-bold text-lg text-zinc-900 dark:text-white">{{ $cycle->name }}</h3>
                    <flux:dropdown>
                        <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" />
                        <flux:menu>
                            <flux:menu.item wire:click="edit({{ $cycle->id }})" icon="pencil">Edit Cycle</flux:menu.item>
                            @if($cycle->status === 'draft')
                                <flux:menu.item wire:click="activate({{ $cycle->id }})" icon="play" class="text-brand-600">Activate Now</flux:menu.item>
                            @elseif($cycle->status === 'active')
                                <flux:menu.item wire:click="close({{ $cycle->id }})" icon="lock-closed" class="text-red-600">Close Cycle</flux:menu.item>
                            @endif
                        </flux:menu>
                    </flux:dropdown>
                </div>
                
                <div class="space-y-3 mb-6">
                    <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <flux:icon.calendar class="size-4" />
                        {{ $cycle->start_date->format('M d') }} - {{ $cycle->end_date->format('M d, Y') }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <flux:icon.users class="size-4" />
                        {{ $cycle->reviews_count }} total forms tracking
                    </div>
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
                No review cycles created yet.
            </div>
        @endforelse
    </div>

    {{-- Cycle Modal --}}
    <flux:modal wire:model="showModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Review Cycle' : 'New Performance Cycle' }}</flux:heading>
                <flux:subheading>This will automatically assign self-reviews and manager reviews to all active employees.</flux:subheading>
            </div>

            <form wire:submit="save" class="space-y-5">
                <flux:input wire:model="name" label="Cycle Name" placeholder="e.g. Q1 2026 Reviews" required />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>
                
                @if($editingId)
                    <flux:select wire:model="status" label="Status">
                        <option value="draft">Draft (Hidden)</option>
                        <option value="active">Active (Visible)</option>
                        <option value="closed">Closed</option>
                    </flux:select>
                @endif
                
                <flux:textarea wire:model="description" label="Internal Notes (Optional)" rows="2" />

                <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ $editingId ? 'Update Cycle' : 'Create & Assign Reviews' }}</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
