<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="pulse-page-title">My Goals</h1>
            <p class="pulse-page-subtitle">Track your personal and professional targets</p>
        </div>
        <flux:button wire:click="create" variant="primary" icon="plus">Add Goal</flux:button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {{-- Pending Goals (Kanban/List) --}}
        <div class="space-y-4">
            <h3 class="font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                <div class="size-2 rounded-full bg-amber-500"></div> Active Goals ({{ $pendingGoals->count() }})
            </h3>
            
            <div class="space-y-3">
                @forelse($pendingGoals as $goal)
                    <div class="bg-white rounded-xl border border-zinc-200 p-4 shadow-sm hover:border-brand-300 transition-all dark:bg-zinc-900 dark:border-zinc-800 group">
                        <div class="flex items-start gap-4">
                            <button wire:click="toggleComplete({{ $goal->id }})" class="mt-1 flex shrink-0 items-center justify-center size-6 rounded-full border-2 border-zinc-300 hover:border-brand-500 transition-colors">
                                <flux:icon.check class="size-4 opacity-0 text-brand-600 hover:opacity-50" />
                            </button>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-bold text-zinc-900 dark:text-white">{{ $goal->title }}</h4>
                                @if($goal->description)
                                    <p class="text-sm text-zinc-500 mt-1 line-clamp-2">{{ $goal->description }}</p>
                                @endif
                                @if($goal->due_date)
                                    <div class="flex items-center gap-1 mt-3 text-xs font-semibold {{ $goal->due_date->isPast() ? 'text-red-500' : 'text-zinc-400' }}">
                                        <flux:icon.calendar class="size-3" /> Due {{ $goal->due_date->format('M d, Y') }}
                                    </div>
                                @endif
                            </div>
                            <div class="opacity-0 group-hover:opacity-100 transition-opacity flex">
                                <flux:button wire:click="edit({{ $goal->id }})" variant="ghost" size="sm" icon="pencil" class="text-zinc-400" />
                                <flux:button wire:click="delete({{ $goal->id }})" variant="ghost" size="sm" icon="trash" class="text-red-400 hover:text-red-600" />
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-10 bg-zinc-50 rounded-xl border border-dashed border-zinc-200 dark:bg-zinc-900/50 dark:border-zinc-800">
                        <p class="text-sm text-zinc-500">No active goals. You're all caught up!</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Completed Goals --}}
        <div class="space-y-4">
            <h3 class="font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                <div class="size-2 rounded-full bg-green-500"></div> Completed
            </h3>
            
            <div class="space-y-3">
                @foreach($completedGoals as $goal)
                    <div class="bg-zinc-50 rounded-xl border border-zinc-100 p-4 opacity-75 dark:bg-zinc-900/50 dark:border-zinc-800/50">
                        <div class="flex items-start gap-4">
                            <button wire:click="toggleComplete({{ $goal->id }})" class="mt-1 flex shrink-0 items-center justify-center size-6 rounded-full border-2 border-green-500 bg-green-50 text-green-600 transition-colors">
                                <flux:icon.check class="size-4" />
                            </button>
                            <div class="flex-1 min-w-0">
                                <h4 class="font-medium text-zinc-600 line-through dark:text-zinc-400">{{ $goal->title }}</h4>
                                <div class="text-[10px] uppercase font-bold tracking-wider text-green-600 mt-2">
                                    Completed {{ $goal->completed_at->diffForHumans() }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Goal Form Modal --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Goal' : 'Add New Goal' }}</flux:heading>
            </div>
            
            <flux:input wire:model="title" label="Goal Title" placeholder="What do you want to achieve?" required />
            
            <flux:textarea wire:model="description" label="Description / Metrics" placeholder="How will you measure success?" rows="3" />
            
            <flux:input wire:model="due_date" type="date" label="Target Date (Optional)" />

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save Goal</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
