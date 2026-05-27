<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-zinc-500">{{ $documents->count() }} document(s) attached to this employee</p>
        <flux:button wire:click="openUpload" variant="primary" icon="arrow-up-tray" size="sm">Upload Document</flux:button>
    </div>

    @if($documents->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 py-14 text-center dark:border-zinc-700">
            <flux:icon.folder-open class="size-10 text-zinc-300 mb-3 dark:text-zinc-600" />
            <p class="text-sm font-medium text-zinc-500">No documents yet</p>
            <p class="mt-1 text-xs text-zinc-400">Upload contracts, forms, or other files for this employee.</p>
        </div>
    @else
        <div class="divide-y divide-zinc-100 rounded-xl border border-zinc-100 dark:divide-zinc-800 dark:border-zinc-800">
            @foreach($documents as $doc)
                <div class="flex items-center gap-4 p-4">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-950/30">
                        <flux:icon.document-text class="size-5 text-indigo-500" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white truncate">{{ $doc->title }}</span>
                            <span class="shrink-0 rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $doc->category }}</span>
                            @if($doc->isExpired())
                                <span class="shrink-0 rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-red-600 dark:bg-red-950/40 dark:text-red-400">Expired</span>
                            @elseif($doc->expiringSoon())
                                <span class="shrink-0 rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">Expiring soon</span>
                            @endif
                        </div>
                        <div class="mt-0.5 flex items-center gap-3 text-xs text-zinc-400">
                            <span>{{ $doc->file_name }}</span>
                            <span>{{ number_format($doc->file_size / 1024, 1) }} KB</span>
                            <span>Uploaded {{ $doc->created_at->format('d M Y') }} by {{ $doc->uploader->name }}</span>
                            @if($doc->expires_at)
                                <span>Expires {{ $doc->expires_at->format('d M Y') }}</span>
                            @endif
                        </div>
                    </div>
                    <flux:button
                        wire:click="delete({{ $doc->id }})"
                        wire:confirm="Delete this document? This cannot be undone."
                        variant="ghost"
                        size="sm"
                        icon="trash"
                        class="text-zinc-400 hover:text-red-500"
                    />
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload Modal --}}
    <flux:modal name="doc-upload-modal" wire:model.self="showUploadModal" class="w-full max-w-lg">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">Upload Document</flux:heading>
                <flux:subheading>Attach a file to {{ $employee->user->name }}'s profile</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="title" label="Document Title" placeholder="e.g. Employment Contract" required />
                <flux:textarea wire:model="description" label="Description" placeholder="Optional notes…" rows="2" />
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="category" label="Category">
                        <option value="other">Other</option>
                        <option value="contract">Contract</option>
                        <option value="form">Form</option>
                        <option value="policy">Policy</option>
                        <option value="notice">Notice</option>
                    </flux:select>
                    <flux:input wire:model="expires_at" type="date" label="Expires On (optional)" />
                </div>
                <flux:field>
                    <flux:label>File <span class="text-red-500">*</span></flux:label>
                    <flux:input wire:model="file" type="file" class="mt-1" />
                    <flux:error name="file" />
                </flux:field>
            </div>

            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button
                    wire:click="upload"
                    wire:loading.attr="disabled"
                    wire:target="upload,file"
                    variant="primary"
                    icon="arrow-up-tray"
                >
                    <span wire:loading.remove wire:target="upload">Upload</span>
                    <span wire:loading wire:target="upload">Uploading…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
