<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header flex justify-between items-start">
        <div>
            <h1 class="pulse-page-title">Document Management</h1>
            <p class="pulse-page-subtitle">Manage company policies and employee documents.</p>
        </div>
        @if(auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin())
            <flux:button wire:click="$set('showUploadModal', true)" variant="primary" icon="arrow-up-tray">Upload Document</flux:button>
        @endif
    </div>

    <div class="pulse-card">
        <div class="flex gap-4 mb-6">
            <flux:input wire:model.live="filterSearch" placeholder="Search documents..." icon="magnifying-glass" class="w-1/3" />
            <flux:select wire:model.live="filterCategory" class="w-1/4">
                <option value="">All Categories</option>
                <option value="policy">Policy</option>
                <option value="contract">Contract</option>
                <option value="payslip">Payslip</option>
                <option value="other">Other</option>
            </flux:select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse($documents as $doc)
                <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 bg-white dark:bg-zinc-900 relative group flex flex-col justify-between">
                    <div>
                        <div class="flex justify-between items-start mb-2">
                            <h3 class="font-bold text-zinc-900 dark:text-white truncate" title="{{ $doc->title }}">{{ $doc->title }}</h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300">
                                v{{ $doc->version }}
                            </span>
                        </div>
                        <p class="text-sm text-zinc-500 mb-4 line-clamp-2">{{ $doc->description ?? 'No description.' }}</p>
                        
                        <div class="text-xs text-zinc-400 space-y-1 mb-4">
                            <div>Uploaded: {{ $doc->created_at->format('M d, Y') }}</div>
                            @if($doc->expires_at)
                                <div class="{{ $doc->isExpired() ? 'text-red-500 font-bold' : ($doc->expiringSoon() ? 'text-amber-500 font-bold' : '') }}">
                                    Expires: {{ $doc->expires_at->format('M d, Y') }}
                                </div>
                            @endif
                            <div>Size: {{ number_format($doc->file_size / 1024 / 1024, 2) }} MB</div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <flux:button href="{{ URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $doc->id]) }}" size="sm" variant="ghost" icon="arrow-down-tray">Download</flux:button>
                        
                        <div class="flex gap-2">
                            @if($doc->requires_acknowledgement && !in_array($doc->id, $acknowledgedIds))
                                <flux:button wire:click="acknowledge({{ $doc->id }})" size="sm" variant="primary">Acknowledge</flux:button>
                            @elseif($doc->requires_acknowledgement && in_array($doc->id, $acknowledgedIds))
                                <span class="inline-flex items-center text-xs font-bold text-green-600 gap-1"><flux:icon.check-circle class="size-4" /> Acknowledged</span>
                            @endif

                            @if(auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin())
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-vertical" />
                                    <flux:menu>
                                        <flux:menu.item wire:click="$set('parentId', {{ $doc->id }}); $set('showUploadModal', true)" icon="arrow-path">Upload New Version</flux:menu.item>
                                        <flux:menu.item wire:click="delete({{ $doc->id }})" wire:confirm="Are you sure you want to delete this document?" icon="trash" class="text-red-600 hover:text-red-700">Delete</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-span-full py-12 text-center text-zinc-500">
                    <flux:icon.document-text class="size-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-700" />
                    No documents found.
                </div>
            @endforelse
        </div>

        <div class="mt-6">
            {{ $documents->links() }}
        </div>
    </div>

    {{-- Upload Modal --}}
    @if(auth()->user()->isHrAdmin() || auth()->user()->isSuperAdmin())
        <flux:modal wire:model="showUploadModal" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $parentId ? 'Upload New Version' : 'Upload Document' }}</h2>
                    <p class="text-sm text-zinc-500">Only PDF and images allowed. Max 10MB.</p>
                </div>

                <form wire:submit.prevent="upload" class="space-y-4">
                    <flux:input wire:model="title" label="Title" required />
                    
                    <flux:textarea wire:model="description" label="Description" rows="2" />
                    
                    <flux:input type="file" wire:model="file" label="File" accept=".pdf,.png,.jpg,.jpeg" required />
                    
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="category" label="Category">
                            <option value="policy">Policy</option>
                            <option value="contract">Contract</option>
                            <option value="payslip">Payslip</option>
                            <option value="other">Other</option>
                        </flux:select>

                        <flux:select wire:model="visibility" label="Visibility">
                            <option value="all">Company-wide</option>
                            <option value="restricted">Restricted / Private</option>
                        </flux:select>
                    </div>

                    <flux:input type="date" wire:model="expiresAt" label="Expiry Date (Optional)" />

                    <flux:checkbox wire:model="requiresAck" label="Requires Acknowledgement" />

                    <div class="flex justify-end gap-2 pt-4">
                        <flux:button wire:click="$set('showUploadModal', false)" variant="ghost">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Upload</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</flux:main>
