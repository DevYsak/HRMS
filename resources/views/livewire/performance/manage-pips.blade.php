<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header flex justify-between items-end">
        <div>
            <h1 class="pulse-page-title">Performance Improvement Plans</h1>
            <p class="pulse-page-subtitle">Create and track employee improvement plans</p>
        </div>
        <flux:button wire:click="openCreateModal" variant="primary" icon="plus">Draft New Plan</flux:button>
    </div>

    {{-- Summary Widgets --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-zinc-900 border border-amber-200 dark:border-amber-800/40 rounded-xl p-4 flex items-center gap-4">
            <div class="flex-shrink-0 size-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                <flux:icon.clock class="size-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $dueThisWeek }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Due This Week</p>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 border border-red-200 dark:border-red-800/40 rounded-xl p-4 flex items-center gap-4">
            <div class="flex-shrink-0 size-10 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                <flux:icon.exclamation-triangle class="size-5 text-red-600 dark:text-red-400" />
            </div>
            <div>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $overdueReviews }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Overdue Reviews</p>
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 border border-green-200 dark:border-green-800/40 rounded-xl p-4 flex items-center gap-4">
            <div class="flex-shrink-0 size-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                <flux:icon.check-circle class="size-5 text-green-600 dark:text-green-400" />
            </div>
            <div>
                <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $completedReviews }}</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Completed This Month</p>
            </div>
        </div>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <div class="relative flex-1 max-w-xs">
                <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee..." class="w-full h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm focus:ring-2 focus:ring-brand-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <flux:select wire:model.live="status" class="w-44 h-9 text-sm">
                <option value="">All Statuses</option>
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="under_review">Under Review</option>
                <option value="extended">Extended</option>
                <option value="successful">Successful</option>
                <option value="failed">Failed</option>
                <option value="escalated">Escalated</option>
            </flux:select>

            @if($search || $status)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm">Clear</flux:button>
            @endif
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Period</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Manager</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Progress</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($records as $record)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $record->employee->user->name ?? 'Unknown' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $record->employee->jobTitle?->name ?? '—' }}</div>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $record->start_date->format('M d, Y') }} &mdash; {{ $record->end_date->format('M d, Y') }}
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">{{ $record->manager?->name ?? '—' }}</td>
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-2 w-32">
                                    <div class="flex-1 h-1.5 bg-zinc-200 dark:bg-zinc-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-blue-500 rounded-full" style="width: {{ $record->overallProgress() }}%"></div>
                                    </div>
                                    <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400">{{ $record->overallProgress() }}%</span>
                                </div>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $record->statusColor() }}">{{ strtoupper($record->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                <flux:button wire:click="viewRecord({{ $record->id }})" size="xs" variant="ghost" icon="eye">View</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">No improvement plans found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $records->links() }}
        </div>
    </div>

    {{-- Create PIP Modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showCreateModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showCreateModal', false)"></div>
            <div class="relative w-full max-w-2xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Draft Improvement Plan</flux:heading>

                <div class="space-y-4 max-h-[70vh] overflow-y-auto px-1">
                    <flux:field>
                        <flux:label>Employee</flux:label>
                        <flux:select wire:model="employee_id">
                            <option value="">Select Employee</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->user->name }} ({{ $emp->employee_id }})</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="employee_id" />
                    </flux:field>

                    <div class="grid grid-cols-3 gap-4">
                        <flux:field>
                            <flux:label>Start Date</flux:label>
                            <flux:input type="date" wire:model="start_date" />
                            <flux:error name="start_date" />
                        </flux:field>

                        <flux:field>
                            <flux:label>End Date</flux:label>
                            <flux:input type="date" wire:model="end_date" />
                            <flux:error name="end_date" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Review Period (days)</flux:label>
                            <flux:input type="number" wire:model="review_period_days" min="1" max="365" />
                            <flux:error name="review_period_days" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Action Plan</flux:label>
                        <flux:textarea wire:model="action_plan" rows="3" placeholder="Outline the steps the employee should take to improve..." />
                        <flux:error name="action_plan" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Success Criteria</flux:label>
                        <flux:textarea wire:model="success_criteria" rows="3" placeholder="Define what success looks like at the end of this plan..." />
                        <flux:error name="success_criteria" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showCreateModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="create" variant="primary">Save Draft</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- View / Manage PIP Modal --}}
    @if($showViewModal && $activeRecord)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showViewModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showViewModal', false)"></div>
            <div class="relative w-full max-w-3xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showViewModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div class="flex items-start justify-between mb-6">
                    <div>
                        <flux:heading size="lg">Improvement Plan Details</flux:heading>
                        <flux:subheading>{{ $activeRecord->employee->user->name }} · {{ $activeRecord->employee->employee_id }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeRecord->statusColor() }} px-2 py-1">{{ strtoupper($activeRecord->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Period</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecord->start_date->format('M d, Y') }} &mdash; {{ $activeRecord->end_date->format('M d, Y') }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Manager</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecord->manager?->name ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">HR Reviewer</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecord->hrReviewer?->name ?? 'Not yet assigned' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Overall Progress</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecord->overallProgress() }}%</span>
                        </div>
                    </div>

                    @if($activeRecord->action_plan)
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Action Plan</span>
                            <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecord->action_plan }}</div>
                        </div>
                    @endif

                    @if($activeRecord->success_criteria)
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Success Criteria</span>
                            <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecord->success_criteria }}</div>
                        </div>
                    @endif

                    @if($activeRecord->employee_comments)
                        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 dark:bg-blue-900/20 dark:border-blue-800/30">
                            <h4 class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-2">Employee Comments</h4>
                            <div class="bg-white dark:bg-zinc-800 p-3 rounded-lg text-sm italic text-zinc-600 dark:text-zinc-400">"{{ $activeRecord->employee_comments }}"</div>
                        </div>
                    @endif

                    {{-- Goals --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-zinc-500 uppercase">Milestones &amp; Goals</span>
                            @if(in_array($activeRecord->status, ['draft', 'active'], true))
                                <flux:button wire:click="openGoalModal" size="xs" variant="ghost" icon="plus">Add Goal</flux:button>
                            @endif
                        </div>

                        @forelse($activeRecord->goals as $goal)
                            <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 rounded-xl p-4 mb-3">
                                <div class="flex items-start justify-between mb-2">
                                    <div>
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $goal->title }}</p>
                                        @if($goal->description)
                                            <p class="text-xs text-zinc-500 mt-1">{{ $goal->description }}</p>
                                        @endif
                                    </div>
                                    <span class="badge-{{ $goal->statusColor() }} px-2 py-0.5 text-[10px]">{{ strtoupper(str_replace('_', ' ', $goal->status)) }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1 h-1.5 bg-zinc-200 dark:bg-zinc-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-blue-500 rounded-full" style="width: {{ $goal->progress_percent }}%"></div>
                                    </div>
                                    <span class="text-xs font-bold text-zinc-600 dark:text-zinc-400">{{ $goal->progress_percent }}%</span>
                                    <flux:button wire:click="openProgressModal({{ $goal->id }})" size="xs" variant="ghost">Update</flux:button>
                                </div>
                                @if($goal->target_date)
                                    <p class="text-[10px] text-zinc-400 mt-2">Target: {{ $goal->target_date->format('M d, Y') }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-zinc-500">No goals added yet.</p>
                        @endforelse
                    </div>

                    {{-- Documents --}}
                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider">Documents</h4>
                            <flux:button wire:click="openDocUploadModal" size="xs" variant="ghost" icon="arrow-up-tray">Upload Document</flux:button>
                        </div>
                        @forelse($activeRecord->documents as $document)
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                                <div class="flex items-center gap-3">
                                    <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                                    <flux:button wire:click="deleteDocument({{ $document->id }})" wire:confirm="Delete this document?" size="xs" variant="ghost" icon="trash" class="text-red-500" />
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents attached yet. Use "Upload Document" to attach action plans, evidence, or outcome letters.</p>
                        @endforelse
                    </div>

                    @if($activeRecord->outcome)
                        <div class="bg-zinc-50 border border-zinc-200 rounded-xl p-4 dark:bg-zinc-900 dark:border-zinc-700">
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Outcome</span>
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ ucfirst($activeRecord->outcome) }} &mdash; {{ $activeRecord->outcome_date?->format('M d, Y') }}</p>
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="border-t border-zinc-200 dark:border-zinc-700 pt-6 mt-6 flex gap-3">
                        @if($activeRecord->status === 'draft')
                            <flux:button wire:click="activate" variant="primary">Activate Plan</flux:button>
                        @endif
                        @if(in_array($activeRecord->status, ['active', 'under_review'], true))
                            <flux:button wire:click="openOutcomeModal" variant="danger">Record Outcome</flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Add Goal Modal --}}
    @if($showGoalModal && $activeRecord)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showGoalModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showGoalModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Add Goal</flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Title</flux:label>
                        <flux:input wire:model="goal_title" placeholder="e.g. Improve code review turnaround time" />
                        <flux:error name="goal_title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Description</flux:label>
                        <flux:textarea wire:model="goal_description" rows="2" />
                        <flux:error name="goal_description" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Target Date</flux:label>
                            <flux:input type="date" wire:model="goal_target_date" />
                            <flux:error name="goal_target_date" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Weightage (%)</flux:label>
                            <flux:input type="number" wire:model="goal_weightage" min="0" max="100" />
                            <flux:error name="goal_weightage" />
                        </flux:field>
                    </div>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showGoalModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="addGoal" variant="primary">Add Goal</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Update Progress Modal --}}
    @if($showProgressModal)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showProgressModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showProgressModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Update Goal Progress</flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Progress (%)</flux:label>
                        <flux:input type="number" wire:model="progress_percent" min="0" max="100" />
                        <flux:error name="progress_percent" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="progress_notes" rows="3" placeholder="Notes on the employee's progress..." />
                        <flux:error name="progress_notes" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showProgressModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="updateProgress" variant="primary">Save Progress</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Record Outcome Modal --}}
    @if($showOutcomeModal && $activeRecord)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showOutcomeModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showOutcomeModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Record Plan Outcome</flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Outcome</flux:label>
                        <flux:select wire:model="outcome">
                            <option value="">Select an outcome</option>
                            <option value="successful">Successful — improvement achieved</option>
                            <option value="extended">Extended — needs more time</option>
                            <option value="failed">Failed — no improvement</option>
                            <option value="escalated">Escalated — further action required</option>
                        </flux:select>
                        <flux:error name="outcome" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showOutcomeModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="recordOutcome" variant="danger">Confirm Outcome</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Upload Document Modal --}}
    @if($showDocUploadModal && $activeRecord)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showDocUploadModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showDocUploadModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Upload Document</flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Title</flux:label>
                        <flux:input wire:model="doc_title" placeholder="e.g. Action Plan, Progress Evidence, Outcome Letter" />
                        <flux:error name="doc_title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Description</flux:label>
                        <flux:textarea wire:model="doc_description" rows="2" />
                        <flux:error name="doc_description" />
                    </flux:field>

                    <flux:field>
                        <flux:label>File</flux:label>
                        <input type="file" wire:model="doc_file" class="block w-full text-sm text-zinc-600 dark:text-zinc-300 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-zinc-100 dark:file:bg-zinc-700 file:text-zinc-700 dark:file:text-zinc-200" />
                        <flux:error name="doc_file" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showDocUploadModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="uploadDocument" variant="primary">Upload</flux:button>
                </div>
            </div>
        </div>
    @endif
</flux:main>
