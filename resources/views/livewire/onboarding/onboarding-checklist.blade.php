<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div class="flex items-center gap-4">
            <flux:avatar :src="$employee->user->avatarUrl()" :initials="$employee->user->initials()" size="lg" />
            <div>
                <h1 class="pulse-page-title">{{ $employee->user->name }}'s {{ ucfirst($phase) }}</h1>
                <p class="pulse-page-subtitle">{{ $employee->jobTitle?->name ?? 'No designation' }} ”¢ Joined {{ $employee->joining_date?->format('M d, Y') ?? '—' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <flux:button wire:click="$set('showAddModal', true)" variant="primary" icon="plus">Add Task</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div class="lg:col-span-1 space-y-6">
            {{-- Progress Card --}}
            <div class="pulse-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-tight">Progress</h3>
                    <span class="text-xl font-bold text-brand-600">{{ $progress }}%</span>
                </div>
                <div class="w-full bg-zinc-100 rounded-full h-2 dark:bg-zinc-800">
                    <div class="bg-brand-600 h-2 rounded-full transition-all duration-500" style="width: {{ $progress }}%"></div>
                </div>
                <div class="flex justify-between mt-4 text-xs text-zinc-500">
                    <span>{{ $completed }} of {{ $total }} completed</span>
                </div>
            </div>

            {{-- Categories Summary --}}
            <div class="pulse-card">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-tight mb-4">By Owner</h3>
                <div class="space-y-3">
                    @foreach(['hr', 'manager', 'employee', 'it', 'finance'] as $role)
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 capitalize">{{ $role }}</span>
                            <span class="badge-onboarding text-[10px]">{{ $tasks->where('owner_role', $role)->count() }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="lg:col-span-3 space-y-4">
            @forelse($tasks->groupBy('category') as $category => $categoryTasks)
                <div class="space-y-3">
                    <h3 class="text-xs font-bold text-zinc-400 uppercase tracking-widest pl-2">{{ str_replace('_', ' ', $category) }}</h3>
                    
                    <div class="space-y-2">
                        @foreach($categoryTasks as $task)
                            <div class="pulse-card group hover:border-brand-300 transition-all {{ $task->is_completed ? 'opacity-75' : '' }}">
                                <div class="flex items-start gap-4">
                                    <div class="pt-1">
                                        <flux:checkbox wire:click="toggleComplete({{ $task->id }})" :checked="$task->is_completed" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <h4 class="text-sm font-bold {{ $task->is_completed ? 'line-through text-zinc-400' : 'text-zinc-900 dark:text-white' }}">
                                                {{ $task->title }}
                                            </h4>
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] font-bold text-zinc-400 uppercase bg-zinc-50 px-2 py-0.5 rounded border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800">
                                                    {{ $task->owner_role }}
                                                </span>
                                                <flux:dropdown>
                                                    <flux:button variant="ghost" icon="ellipsis-horizontal" size="xs" />
                                                    <flux:menu>
                                                        <flux:menu.item wire:click="deleteTask({{ $task->id }})" variant="danger">Remove Task</flux:menu.item>
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        </div>
                                        <p class="text-xs text-zinc-500 mt-1 line-clamp-1">{{ $task->description }}</p>
                                        
                                        @if($task->is_completed)
                                            <div class="mt-2 flex items-center gap-2 text-[10px] text-zinc-400 italic">
                                                <flux:icon.check-circle class="size-3 text-green-500" />
                                                <span>Completed on {{ $task->completed_at->format('M d, Y') }}</span>
                                            </div>
                                        @elseif($task->due_date)
                                            <div class="mt-2 text-[10px] {{ $task->due_date->isPast() ? 'text-red-500 font-bold' : 'text-zinc-400' }}">
                                                Due: {{ $task->due_date->format('M d, Y') }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="pulse-card py-12 text-center text-zinc-500">
                    No tasks found for this phase.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Add Task Modal --}}
    @if($showAddModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showAddModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showAddModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showAddModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Add {{ ucfirst($phase) }} Task</flux:heading>
                <flux:subheading>Assign a new requirement for the employee</flux:subheading>
            </div>

            <form wire:submit="addTask" class="space-y-4">
                <flux:input wire:model="newTitle" label="Task Title" placeholder="e.g. Asset Handover" />
                
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="newCategory" label="Category">
                        <option value="general">General</option>
                        <option value="hr">HR</option>
                        <option value="it_setup">IT Setup</option>
                        <option value="finance">Finance</option>
                        <option value="documentation">Documentation</option>
                    </flux:select>
                    
                    <flux:select wire:model="newOwnerRole" label="Responsible Role">
                        <option value="hr">HR Admin</option>
                        <option value="manager">Reporting Manager</option>
                        <option value="employee">Employee (Self)</option>
                        <option value="it">IT Team</option>
                        <option value="finance">Finance Team</option>
                    </flux:select>
                </div>

                <flux:textarea wire:model="newDescription" label="Instructions (Optional)" rows="2" />
                
                <flux:input type="date" wire:model="newDueDate" label="Due Date" />

                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" @click="$wire.set('showAddModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                    <flux:button type="submit" variant="primary">Add Task</flux:button>
                </div>
            </form>
        </div>
    
            </div>
        </div>
    @endif
</flux:main>
