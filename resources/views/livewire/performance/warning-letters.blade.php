<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header flex justify-between items-end">
        <div>
            <h1 class="pulse-page-title">Disciplinary Actions & Warnings</h1>
            <p class="pulse-page-subtitle">Manage employee warnings and disciplinary records</p>
        </div>
        <flux:button wire:click="openIssueModal" variant="primary" icon="plus">Issue Warning</flux:button>
    </div>

    <div class="pulse-card">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <div class="relative flex-1 max-w-xs">
                <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee..." class="w-full h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm focus:ring-2 focus:ring-brand-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
            </div>
            
            <flux:select wire:model.live="type" class="w-40 h-9 text-sm">
                <option value="">All Types</option>
                <option value="verbal">Verbal Warning</option>
                <option value="written">Written Warning</option>
                <option value="final">Final Warning</option>
                <option value="pip">PIP</option>
            </flux:select>
            
            <flux:select wire:model.live="status" class="w-40 h-9 text-sm">
                <option value="">All Statuses</option>
                <option value="issued">Issued</option>
                <option value="acknowledged">Acknowledged</option>
                <option value="closed">Closed</option>
                <option value="draft">Draft</option>
            </flux:select>
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/50 border-b border-zinc-100 dark:border-zinc-800">
                        <th class="py-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Type</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Reason</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Issue Date</th>
                        <th class="py-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                        <th class="py-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($warnings as $warning)
                        <tr class="hover:bg-zinc-50/30 transition-colors">
                            <td class="py-3 pl-6 pr-4">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $warning->employee->user->name ?? 'Unknown' }}</div>
                                <div class="text-[10px] text-zinc-500">{{ $warning->employee->jobTitle?->name ?? '—' }}</div>
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $warning->warningTypeColor() }}">{{ $warning->warningTypeLabel() }}</span>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                <div class="truncate max-w-[200px]" title="{{ $warning->reason }}">{{ $warning->reason }}</div>
                            </td>
                            <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ \Carbon\Carbon::parse($warning->issue_date)->format('M d, Y') }}
                            </td>
                            <td class="py-3 pr-4">
                                <span class="badge-{{ $warning->statusColor() }}">{{ strtoupper($warning->statusLabel()) }}</span>
                            </td>
                            <td class="py-3 pr-6 text-right space-x-1">
                                <flux:button wire:click="viewWarning({{ $warning->id }})" size="xs" variant="ghost" icon="eye">View</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500">No warnings found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        <div class="mt-4">
            {{ $warnings->links() }}
        </div>
    </div>

    {{-- Issue Warning Modal --}}
    @if($showIssueModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showIssueModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showIssueModal', false)"></div>
            <div class="relative w-full max-w-2xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-6">Issue Warning</flux:heading>
                
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

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Warning Type</flux:label>
                            <flux:select wire:model="warning_type">
                                <option value="verbal">Verbal Warning</option>
                                <option value="written">Written Warning</option>
                                <option value="final">Final Warning</option>
                                <option value="pip">Performance Improvement Plan (PIP)</option>
                            </flux:select>
                            <flux:error name="warning_type" />
                        </flux:field>
                        
                        <flux:field>
                            <flux:label>Issue Date</flux:label>
                            <flux:input type="date" wire:model="issue_date" />
                            <flux:error name="issue_date" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Reason</flux:label>
                        <flux:input wire:model="reason" placeholder="Brief reason for warning..." />
                        <flux:error name="reason" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Detailed Description</flux:label>
                        <flux:textarea wire:model="description" rows="4" placeholder="Provide detailed context, facts, and expected improvements..." />
                        <flux:error name="description" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Next Review Date (Optional)</flux:label>
                        <flux:input type="date" wire:model="next_review_date" />
                        <flux:error name="next_review_date" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$wire.set('showIssueModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors rounded-xl">Cancel</button>
                    <flux:button wire:click="issueWarning" variant="danger">Issue Warning</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- View / Manage Warning Modal --}}
    @if($showViewModal && $activeWarning)
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
                        <flux:heading size="lg">Warning Letter Details</flux:heading>
                        <flux:subheading>{{ $activeWarning->employee->user->name }} · {{ $activeWarning->employee->employee_id }}</flux:subheading>
                    </div>
                    <span class="badge-{{ $activeWarning->statusColor() }} px-2 py-1">{{ strtoupper($activeWarning->statusLabel()) }}</span>
                </div>

                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4 text-sm bg-zinc-50 dark:bg-zinc-900 p-4 rounded-xl">
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Warning Type</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeWarning->warningTypeLabel() }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Issue Date</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeWarning->issue_date)->format('M d, Y') }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Issued By</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeWarning->issuedBy?->name ?? 'System' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold text-zinc-400 uppercase block">Next Review Date</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $activeWarning->next_review_date ? \Carbon\Carbon::parse($activeWarning->next_review_date)->format('M d, Y') : 'N/A' }}</span>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Reason</span>
                            <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">{{ $activeWarning->reason }}</p>
                        </div>
                        @if($activeWarning->description)
                            <div>
                                <span class="text-xs font-bold text-zinc-500 uppercase block mb-1">Detailed Description</span>
                                <div class="bg-zinc-50 dark:bg-zinc-900 p-4 rounded-lg text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $activeWarning->description }}</div>
                            </div>
                        @endif
                    </div>

                    {{-- Documents --}}
                    <div class="bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl p-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold text-zinc-600 dark:text-zinc-400 uppercase tracking-wider">Documents</h4>
                            @if(auth()->user()->canManageEmployees())
                                <div class="flex items-center gap-2">
                                    <flux:button wire:click="generatePdf" size="xs" variant="ghost" icon="document-text">Generate PDF</flux:button>
                                    <flux:button wire:click="openDocUploadModal" size="xs" variant="ghost" icon="paper-clip">Upload</flux:button>
                                </div>
                            @endif
                        </div>
                        @forelse($activeWarning->documents as $document)
                            <div class="flex items-center justify-between text-sm py-1.5 border-b border-zinc-200 dark:border-zinc-700 last:border-0">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="truncate text-zinc-700 dark:text-zinc-300">{{ $document->title }}</span>
                                    <span class="shrink-0 text-xs text-zinc-400">v{{ $document->version }}</span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0 ml-3">
                                    <a href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document->id]) }}" class="text-xs text-blue-600 hover:underline">Download</a>
                                    @if(auth()->user()->canManageEmployees())
                                        <button wire:click="deleteDocument({{ $document->id }})" wire:confirm="Delete this document?" class="text-xs text-red-500 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">No documents attached yet. Generate a PDF or upload evidence files using the buttons above.</p>
                        @endforelse
                    </div>

                    {{-- Acknowledgement Info --}}
                    @if($activeWarning->isAcknowledged())
                        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 dark:bg-blue-900/20 dark:border-blue-800/30">
                            <h4 class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-2">Employee Acknowledgement</h4>
                            @php $ack = $activeWarning->acknowledgements->first(); @endphp
                            <p class="text-xs text-blue-800 dark:text-blue-300 mb-2">Acknowledged on {{ $ack?->acknowledged_at?->format('M d, Y H:i') }}</p>
                            @if($ack?->acknowledgement_text)
                                <div class="bg-white dark:bg-zinc-800 p-3 rounded-lg text-sm italic text-zinc-600 dark:text-zinc-400">
                                    "{{ $ack->acknowledgement_text }}"
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Close Info --}}
                    @if($activeWarning->status === 'closed')
                        <div class="bg-green-50 border border-green-100 rounded-xl p-4 dark:bg-green-900/20 dark:border-green-800/30">
                            <h4 class="text-xs font-bold text-green-600 uppercase tracking-wider mb-2">Warning Closed</h4>
                            <p class="text-xs text-green-800 dark:text-green-300 mb-2">Closed on {{ \Carbon\Carbon::parse($activeWarning->closed_at)->format('M d, Y H:i') }} by {{ $activeWarning->closedBy?->name ?? 'System' }}</p>
                            @if($activeWarning->hr_comments || $activeWarning->manager_comments)
                                <div class="bg-white dark:bg-zinc-800 p-3 rounded-lg text-sm italic text-zinc-600 dark:text-zinc-400">
                                    "{{ $activeWarning->hr_comments ?? $activeWarning->manager_comments }}"
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- Actions --}}
                    @if($activeWarning->status !== 'closed')
                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-6 mt-6">
                            @if(!$showEscalateForm && !$showCloseForm)
                                <div class="flex gap-3">
                                    @if($activeWarning->nextWarningType())
                                        <flux:button wire:click="$set('showEscalateForm', true)" variant="danger">Escalate Warning</flux:button>
                                    @endif
                                    <flux:button wire:click="$set('showCloseForm', true)" variant="ghost">Close Warning</flux:button>
                                </div>
                            @endif

                            @if($showEscalateForm)
                                <div class="bg-red-50 p-4 rounded-xl border border-red-100 dark:bg-red-900/10 dark:border-red-800/30 space-y-4">
                                    <h4 class="font-bold text-red-800 dark:text-red-400">Escalate to {{ ucfirst($activeWarning->nextWarningType()) }}</h4>
                                    <flux:field>
                                        <flux:label>Reason</flux:label>
                                        <flux:input wire:model="reason" placeholder="Reason for escalation..." />
                                    </flux:field>
                                    <flux:field>
                                        <flux:label>Additional Details</flux:label>
                                        <flux:textarea wire:model="description" rows="2" />
                                    </flux:field>
                                    <div class="flex justify-end gap-2">
                                        <flux:button wire:click="$set('showEscalateForm', false)" size="sm" variant="ghost">Cancel</flux:button>
                                        <flux:button wire:click="escalateWarning" size="sm" variant="danger">Confirm Escalation</flux:button>
                                    </div>
                                </div>
                            @endif

                            @if($showCloseForm)
                                <div class="bg-zinc-50 p-4 rounded-xl border border-zinc-200 dark:bg-zinc-900 dark:border-zinc-700 space-y-4">
                                    <h4 class="font-bold text-zinc-800 dark:text-zinc-200">Close Warning</h4>
                                    <flux:field>
                                        <flux:label>Closing Remarks (Mandatory)</flux:label>
                                        <flux:textarea wire:model="close_comment" rows="3" placeholder="Provide reason for closing this warning..." />
                                        <flux:error name="close_comment" />
                                    </flux:field>
                                    <div class="flex justify-end gap-2">
                                        <flux:button wire:click="$set('showCloseForm', false)" size="sm" variant="ghost">Cancel</flux:button>
                                        <flux:button wire:click="closeWarning" size="sm" variant="primary">Confirm Close</flux:button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Upload Document Modal --}}
    @if($showDocUploadModal && $activeWarning)
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showDocUploadModal', false)">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="$wire.set('showDocUploadModal', false)"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6">
                <flux:heading size="lg" class="mb-5">Upload Document</flux:heading>
                <p class="text-xs text-zinc-500 mb-5">Attach evidence, signed copies, or supporting files to this warning letter. Documents will be accessible to the employee via the Documents module.</p>

                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Document Title <flux:required /></flux:label>
                        <flux:input wire:model="doc_title" placeholder="e.g. Evidence Screenshot, Signed Copy" />
                        <flux:error name="doc_title" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Description</flux:label>
                        <flux:textarea wire:model="doc_description" rows="2" placeholder="Brief description (optional)" />
                        <flux:error name="doc_description" />
                    </flux:field>

                    <flux:field>
                        <flux:label>File <flux:required /></flux:label>
                        <input type="file" wire:model="doc_file"
                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-orange-50 file:px-3 file:py-1 file:text-sm file:font-medium file:text-orange-700 hover:file:bg-orange-100 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                        <p class="mt-1 text-xs text-zinc-400">Any file type — max 10 MB</p>
                        <flux:error name="doc_file" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <flux:button wire:click="$set('showDocUploadModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="uploadDocument" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="uploadDocument">Upload Document</span>
                        <span wire:loading wire:target="uploadDocument">Uploading…</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</flux:main>
