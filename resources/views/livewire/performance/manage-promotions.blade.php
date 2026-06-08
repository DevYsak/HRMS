<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header flex justify-between items-end">
        <div>
            <h1 class="pulse-page-title">Promotions &amp; Rewards</h1>
            <p class="pulse-page-subtitle">Recommend and review promotions, raises, bonuses, and transfers</p>
        </div>
        <flux:button wire:click="openCreateModal" variant="primary" icon="plus">New Recommendation</flux:button>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <div class="relative flex-1 max-w-xs">
                <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee..." class="w-full h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm focus:ring-2 focus:ring-brand-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>

            <flux:select wire:model.live="type" class="w-44 h-9 text-sm">
                <option value="">All Types</option>
                <option value="promotion">Promotion</option>
                <option value="salary_increment">Salary Increment</option>
                <option value="bonus">Bonus</option>
                <option value="role_change">Role Change</option>
                <option value="dept_transfer">Department Transfer</option>
            </flux:select>

            <flux:select wire:model.live="status" class="w-44 h-9 text-sm">
                <option value="">All Statuses</option>
                <option value="pending_hr">Pending HR</option>
                <option value="pending_dept_head">Pending Dept Head</option>
                <option value="pending_super_admin">Pending Super Admin</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </flux:select>

            @if($search || $status || $type)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm">Clear</flux:button>
            @endif
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Type</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Recommended By</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Stage</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($recommendations as $recommendation)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $recommendation->employee->user->name ?? 'Unknown' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $recommendation->employee->jobTitle?->name ?? '—' }}</div>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-onboarding">{{ $recommendation->recommendationTypeLabel() }}</span>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">{{ $recommendation->recommendedBy?->name ?? '—' }}</td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ in_array($recommendation->status, ['approved', 'rejected'], true) ? '—' : strtoupper(str_replace('_', ' ', $recommendation->current_reviewer_stage)) }}
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $recommendation->statusColor() }}">{{ strtoupper($recommendation->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                <flux:button wire:click="viewRecommendation({{ $recommendation->id }})" size="xs" variant="ghost" icon="eye">View</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">No recommendations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $recommendations->links() }}
        </div>
    </div>

    {{-- Create Recommendation Modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showCreateModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showCreateModal', false)"></div>
            <div class="relative w-full max-w-2xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">New Recommendation</flux:heading>

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

                    <flux:field>
                        <flux:label>Recommendation Type</flux:label>
                        <flux:select wire:model="recommendation_type">
                            <option value="promotion">Promotion</option>
                            <option value="salary_increment">Salary Increment</option>
                            <option value="bonus">Bonus</option>
                            <option value="role_change">Role Change</option>
                            <option value="dept_transfer">Department Transfer</option>
                        </flux:select>
                        <flux:error name="recommendation_type" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Current Role</flux:label>
                            <flux:input wire:model="current_role" placeholder="e.g. Software Engineer" />
                            <flux:error name="current_role" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Proposed Role</flux:label>
                            <flux:input wire:model="proposed_role" placeholder="e.g. Senior Software Engineer" />
                            <flux:error name="proposed_role" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <flux:field>
                            <flux:label>Current Salary</flux:label>
                            <flux:input type="number" step="0.01" wire:model="current_salary" />
                            <flux:error name="current_salary" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Proposed Salary</flux:label>
                            <flux:input type="number" step="0.01" wire:model="proposed_salary" />
                            <flux:error name="proposed_salary" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Bonus Amount</flux:label>
                            <flux:input type="number" step="0.01" wire:model="bonus_amount" />
                            <flux:error name="bonus_amount" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Target Department (Optional)</flux:label>
                            <flux:select wire:model="target_department_id">
                                <option value="">None</option>
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="target_department_id" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Effective Date (Optional)</flux:label>
                            <flux:input type="date" wire:model="effective_date" />
                            <flux:error name="effective_date" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Justification</flux:label>
                        <flux:textarea wire:model="justification" rows="4" placeholder="Explain why this recommendation is warranted..." />
                        <flux:error name="justification" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showCreateModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="create" variant="primary">Submit for Review</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- View / Review Modal --}}
    @if($showViewModal && $activeRecommendation)
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
                        <flux:heading size="lg">{{ $activeRecommendation->recommendationTypeLabel() }}</flux:heading>
                        <flux:subheading>{{ $activeRecommendation->employee->user->name }} · {{ $activeRecommendation->employee->employee_id }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeRecommendation->statusColor() }} px-2 py-1">{{ strtoupper($activeRecommendation->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                        @if($activeRecommendation->current_role || $activeRecommendation->proposed_role)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Current &rarr; Proposed Role</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->current_role ?? '—' }} &rarr; {{ $activeRecommendation->proposed_role ?? '—' }}</span>
                            </div>
                        @endif
                        @if($activeRecommendation->current_salary || $activeRecommendation->proposed_salary)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Salary Change</span>
                                <span class="font-medium text-zinc-900 dark:text-white">
                                    {{ $activeRecommendation->current_salary ? number_format($activeRecommendation->current_salary, 2) : '—' }}
                                    &rarr;
                                    {{ $activeRecommendation->proposed_salary ? number_format($activeRecommendation->proposed_salary, 2) : '—' }}
                                    @if($activeRecommendation->incrementPercent())
                                        <span class="text-emerald-600 dark:text-emerald-400">(+{{ $activeRecommendation->incrementPercent() }}%)</span>
                                    @endif
                                </span>
                            </div>
                        @endif
                        @if($activeRecommendation->bonus_amount)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Bonus Amount</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ number_format($activeRecommendation->bonus_amount, 2) }}</span>
                            </div>
                        @endif
                        @if($activeRecommendation->targetDepartment)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Target Department</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->targetDepartment->name }}</span>
                            </div>
                        @endif
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Recommended By</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->recommendedBy?->name ?? '—' }}</span>
                        </div>
                        @if($activeRecommendation->effective_date)
                            <div>
                                <span class="text-[10px] font-bold text-zinc-400 uppercase block">Effective Date</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $activeRecommendation->effective_date->format('M d, Y') }}</span>
                            </div>
                        @endif
                    </div>

                    <div>
                        <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Justification</span>
                        <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeRecommendation->justification }}</div>
                    </div>

                    {{-- Stage comments trail --}}
                    @if($activeRecommendation->hr_comments || $activeRecommendation->dept_head_comments || $activeRecommendation->super_admin_comments)
                        <div class="space-y-3">
                            <span class="text-xs font-bold text-zinc-500 uppercase block">Review Trail</span>
                            @if($activeRecommendation->hr_comments)
                                <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 rounded-xl p-3">
                                    <p class="text-[10px] font-bold text-zinc-400 uppercase mb-1">HR</p>
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $activeRecommendation->hr_comments }}</p>
                                </div>
                            @endif
                            @if($activeRecommendation->dept_head_comments)
                                <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 rounded-xl p-3">
                                    <p class="text-[10px] font-bold text-zinc-400 uppercase mb-1">Department Head</p>
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $activeRecommendation->dept_head_comments }}</p>
                                </div>
                            @endif
                            @if($activeRecommendation->super_admin_comments)
                                <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-100 dark:border-zinc-800 rounded-xl p-3">
                                    <p class="text-[10px] font-bold text-zinc-400 uppercase mb-1">Super Admin</p>
                                    <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $activeRecommendation->super_admin_comments }}</p>
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Documents --}}
                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider">Documents</h4>
                            <flux:button wire:click="openDocUploadModal" size="xs" variant="ghost" icon="arrow-up-tray">Upload Document</flux:button>
                        </div>
                        @forelse($activeRecommendation->documents as $document)
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                                <span class="text-zinc-700 dark:text-zinc-300">{{ $document->title }} <span class="text-xs text-zinc-400">v{{ $document->version }}</span></span>
                                <div class="flex items-center gap-3">
                                    <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                                    <flux:button wire:click="deleteDocument({{ $document->id }})" wire:confirm="Delete this document?" size="xs" variant="ghost" icon="trash" class="text-red-500" />
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents attached yet. Use "Upload Document" to attach offer letters, approval memos, or supporting evidence.</p>
                        @endforelse
                    </div>

                    {{-- Review action --}}
                    @if($this->canActOnCurrentStage())
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 space-y-4 dark:bg-amber-900/10 dark:border-amber-800/30">
                            <h4 class="font-bold text-amber-800 dark:text-amber-400">Your Review — {{ strtoupper(str_replace('_', ' ', $activeRecommendation->current_reviewer_stage)) }} Stage</h4>
                            <flux:field>
                                <flux:label>Comments</flux:label>
                                <flux:textarea wire:model="reviewComments" rows="3" placeholder="Add your review comments..." />
                                <flux:error name="reviewComments" />
                            </flux:field>
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="reject" variant="danger">Reject</flux:button>
                                <flux:button wire:click="approve" variant="primary">Approve &amp; Advance</flux:button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Upload Document Modal --}}
    @if($showDocUploadModal && $activeRecommendation)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showDocUploadModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showDocUploadModal', false)"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Upload Document</flux:heading>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Title</flux:label>
                        <flux:input wire:model="doc_title" placeholder="e.g. Offer Letter, Approval Memo" />
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
