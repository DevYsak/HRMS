<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    {{-- Header --}}
    <div class="pulse-page-header">
        <div class="flex items-center gap-4">
            <flux:avatar :src="$employee->user->avatarUrl()" :initials="$employee->user->initials()" size="lg" />
            <div>
                <h1 class="pulse-page-title">{{ $employee->user->name }}'s {{ ucfirst($phase) }}</h1>
                <p class="pulse-page-subtitle">{{ $employee->jobTitle?->name ?? 'No designation' }} · Joined {{ $employee->joining_date?->format('M d, Y') ?? '—' }}</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <flux:button wire:click="$toggle('showTimeline')" variant="ghost" icon="clock" size="sm">
                {{ $showTimeline ? 'Hide Timeline' : 'Timeline' }}
            </flux:button>
            <flux:button wire:click="$set('showAddModal', true)" variant="primary" icon="plus">Add Task</flux:button>
        </div>
    </div>

    {{-- Completion banner --}}
    @if($progress === 100 && $total > 0)
        <flux:callout variant="success" icon="check-badge">
            <flux:callout.heading>Onboarding Complete!</flux:callout.heading>
            <flux:callout.text>
                All {{ $total }} onboarding tasks have been completed.
                @if($timeline->last()?->completed_at)
                    Last task completed on {{ $timeline->last()->completed_at->format('M d, Y') }}.
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Timeline (toggled) --}}
    @if($showTimeline)
        <div class="pulse-card space-y-1">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4">Onboarding Timeline</h3>
            @forelse($timeline as $milestone)
                <div class="flex items-start gap-3 py-2 border-b border-zinc-100 dark:border-zinc-800 last:border-0" wire:key="tl-{{ $milestone->id }}">
                    <flux:icon.check-circle class="size-4 text-green-500 mt-0.5 shrink-0" />
                    <div class="flex-1">
                        <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ $milestone->title }}</p>
                        <p class="text-xs text-zinc-400">
                            {{ $milestone->completed_at?->format('M d, Y H:i') }}
                            @if($milestone->completedBy)
                                · by {{ $milestone->completedBy->name }}
                            @endif
                        </p>
                    </div>
                    <flux:badge color="zinc" size="sm">{{ $milestone->owner_role ?? 'general' }}</flux:badge>
                </div>
            @empty
                <p class="text-sm text-zinc-400 py-4 text-center">No milestones completed yet.</p>
            @endforelse
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        {{-- Sidebar --}}
        <div class="lg:col-span-1 space-y-6">
            {{-- Progress Card --}}
            <div class="pulse-card">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-tight">Progress</h3>
                    <span class="text-xl font-bold {{ $progress === 100 ? 'text-green-600' : 'text-brand-600' }}">{{ $progress }}%</span>
                </div>
                <div class="w-full bg-zinc-100 rounded-full h-2 dark:bg-zinc-800">
                    <div class="h-2 rounded-full transition-all duration-500 {{ $progress === 100 ? 'bg-green-500' : 'bg-brand-600' }}"
                         style="width: {{ $progress }}%"></div>
                </div>
                <div class="flex justify-between mt-4 text-xs text-zinc-500">
                    <span>{{ $completed }} of {{ $total }} completed</span>
                    @if($allTasks->where('status', 'overdue')->count() > 0)
                        <flux:badge color="red" size="sm">{{ $allTasks->where('status', 'overdue')->count() }} overdue</flux:badge>
                    @endif
                </div>
            </div>

            {{-- Status Filter --}}
            <div class="pulse-card">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-tight mb-3">Filter by Status</h3>
                <div class="space-y-1">
                    @foreach([
                        'all'         => ['label' => 'All Tasks',    'color' => 'zinc'],
                        'pending'     => ['label' => 'Pending',      'color' => 'zinc'],
                        'in_progress' => ['label' => 'In Progress',  'color' => 'blue'],
                        'completed'   => ['label' => 'Completed',    'color' => 'green'],
                        'overdue'     => ['label' => 'Overdue',      'color' => 'red'],
                        'blocked'     => ['label' => 'Blocked',      'color' => 'orange'],
                    ] as $key => $meta)
                        @php $count = $key === 'all' ? $allTasks->count() : $allTasks->where('status', $key)->count(); @endphp
                        <button
                            wire:click="$set('statusFilter', '{{ $key }}')"
                            class="w-full flex justify-between items-center px-2 py-1.5 rounded text-xs font-medium transition-colors
                                {{ $statusFilter === $key ? 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                        >
                            <span>{{ $meta['label'] }}</span>
                            <flux:badge color="{{ $meta['color'] }}" size="sm">{{ $count }}</flux:badge>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- By Owner --}}
            <div class="pulse-card">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-tight mb-3">By Owner</h3>
                <div class="space-y-2">
                    @foreach(['hr', 'manager', 'employee', 'it', 'finance'] as $role)
                        @php
                            $roleTasks = $allTasks->where('owner_role', $role);
                            $roleTotal = $roleTasks->count();
                            $roleDone  = $roleTasks->where('is_completed', true)->count();
                        @endphp
                        @if($roleTotal > 0)
                            <div class="flex justify-between items-center text-xs">
                                <span class="font-medium text-zinc-600 dark:text-zinc-400 capitalize">{{ $role }}</span>
                                <span class="{{ $roleDone === $roleTotal ? 'text-green-600' : 'text-zinc-400' }} font-mono">
                                    {{ $roleDone }}/{{ $roleTotal }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Task List --}}
        <div class="lg:col-span-3 space-y-4">
            @forelse($tasks->groupBy('category') as $category => $categoryTasks)
                <div class="space-y-3">
                    <h3 class="text-xs font-bold text-zinc-400 uppercase tracking-widest pl-2">{{ str_replace('_', ' ', $category) }}</h3>
                    <div class="space-y-2">
                        @foreach($categoryTasks as $task)
                            @php $badge = $task->statusBadge(); @endphp
                            <div class="pulse-card group transition-all
                                {{ $task->status === 'blocked' ? 'border-orange-200 bg-orange-50/30 dark:border-orange-900 dark:bg-orange-950/20' : '' }}
                                {{ $task->status === 'overdue' ? 'border-red-200 dark:border-red-900' : '' }}
                                {{ $task->is_completed ? 'opacity-70' : 'hover:border-brand-300' }}"
                                 wire:key="task-{{ $task->id }}">
                                <div class="flex items-start gap-4">
                                    <div class="pt-1">
                                        <flux:checkbox wire:click="toggleComplete({{ $task->id }})" :checked="$task->is_completed" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between flex-wrap gap-2">
                                            <h4 class="text-sm font-bold {{ $task->is_completed ? 'line-through text-zinc-400' : 'text-zinc-900 dark:text-white' }}">
                                                {{ $task->title }}
                                            </h4>
                                            <div class="flex items-center gap-2 shrink-0">
                                                {{-- Status badge --}}
                                                <flux:badge
                                                    color="{{ $badge['color'] }}"
                                                    size="sm"
                                                    icon="{{ $badge['icon'] }}"
                                                >{{ $badge['label'] }}</flux:badge>

                                                {{-- Owner badge --}}
                                                @if($task->owner_role)
                                                    <span class="text-[10px] font-bold text-zinc-400 uppercase bg-zinc-50 px-2 py-0.5 rounded border border-zinc-100 dark:bg-zinc-900 dark:border-zinc-800">
                                                        {{ $task->owner_role }}
                                                    </span>
                                                @endif

                                                {{-- Auto-trigger indicator --}}
                                                @if($task->auto_trigger)
                                                    <flux:tooltip :content="'Auto: ' . $task->auto_trigger">
                                                        <flux:icon.bolt class="size-3.5 text-blue-400" />
                                                    </flux:tooltip>
                                                @endif

                                                {{-- Actions menu --}}
                                                <flux:dropdown>
                                                    <flux:button variant="ghost" icon="ellipsis-horizontal" size="xs" />
                                                    <flux:menu>
                                                        @unless($task->is_completed)
                                                            <flux:menu.item wire:click="updateStatus({{ $task->id }}, 'in_progress')">Mark In Progress</flux:menu.item>
                                                            <flux:menu.item wire:click="updateStatus({{ $task->id }}, 'blocked')">Mark Blocked</flux:menu.item>
                                                        @endunless
                                                        @if($task->status === 'blocked')
                                                            <flux:menu.item wire:click="updateStatus({{ $task->id }}, 'pending')">Unblock</flux:menu.item>
                                                        @endif
                                                        <flux:menu.separator />
                                                        <flux:menu.item wire:click="deleteTask({{ $task->id }})" variant="danger">Remove Task</flux:menu.item>
                                                    </flux:menu>
                                                </flux:dropdown>
                                            </div>
                                        </div>

                                        @if($task->description)
                                            <p class="text-xs text-zinc-500 mt-1 line-clamp-2">{{ $task->description }}</p>
                                        @endif

                                        @if($task->status === 'blocked' && $task->blocked_reason)
                                            <div class="mt-2 flex items-center gap-1 text-xs text-orange-600 dark:text-orange-400">
                                                <flux:icon.x-circle class="size-3.5" />
                                                <span>Blocked: {{ $task->blocked_reason }}</span>
                                            </div>
                                        @endif

                                        @if($task->is_completed && $task->completed_at)
                                            <div class="mt-2 flex items-center gap-2 text-[10px] text-zinc-400 italic">
                                                <flux:icon.check-circle class="size-3 text-green-500" />
                                                <span>Completed {{ $task->completed_at->format('M d, Y') }}
                                                    @if($task->completedBy) by {{ $task->completedBy->name }}@endif
                                                </span>
                                            </div>
                                        @elseif($task->due_date)
                                            <div class="mt-2 text-[10px] {{ $task->due_date->isPast() && !$task->is_completed ? 'text-red-500 font-bold' : 'text-zinc-400' }}">
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
                    @if($statusFilter !== 'all')
                        No tasks with status "{{ $statusFilter }}" found.
                        <flux:button wire:click="$set('statusFilter', 'all')" variant="ghost" size="sm" class="mt-2">Clear Filter</flux:button>
                    @else
                        No tasks found for this phase.
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Add Task Modal --}}
    <flux:modal wire:model="showAddModal" class="md:max-w-lg">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">Add {{ ucfirst($phase) }} Task</flux:heading>

            <flux:input wire:model="newTitle" label="Task Title" placeholder="e.g. Asset Handover" />

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Category</flux:label>
                    <flux:select wire:model="newCategory">
                        <option value="general">General</option>
                        <option value="hr">HR</option>
                        <option value="it_setup">IT Setup</option>
                        <option value="finance">Finance</option>
                        <option value="documentation">Documentation</option>
                    </flux:select>
                </flux:field>
                <flux:field>
                    <flux:label>Responsible Role</flux:label>
                    <flux:select wire:model="newOwnerRole">
                        <option value="hr">HR Admin</option>
                        <option value="manager">Manager</option>
                        <option value="employee">Employee</option>
                        <option value="it">IT Team</option>
                        <option value="finance">Finance</option>
                    </flux:select>
                </flux:field>
            </div>

            <flux:textarea wire:model="newDescription" label="Instructions (Optional)" rows="2" />
            <flux:input type="date" wire:model="newDueDate" label="Due Date" />

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="$set('showAddModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="addTask" variant="primary">Add Task</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Block Task Modal --}}
    <flux:modal wire:model="showBlockModal" class="md:max-w-md">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">Mark Task as Blocked</flux:heading>

            <flux:field>
                <flux:label>Reason for blocking <flux:badge as="span" color="red" size="sm">Required</flux:badge></flux:label>
                <flux:textarea wire:model="blockReason" rows="3" placeholder="Describe what is blocking this task..." />
                <flux:error name="blockReason" />
            </flux:field>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="$set('showBlockModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="setBlocked" variant="primary" class="bg-orange-500 hover:bg-orange-600">Mark Blocked</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
