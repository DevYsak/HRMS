{{-- Evidence on the open record ($evidenceOf). Files are on the private disk; downloads go through an authorised route. --}}
<section class="space-y-2" data-evidence>
    <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Evidence</h4>
    @forelse($evidenceOf->evidence as $ev)
        <div class="flex items-start gap-2 rounded-lg border border-zinc-100 p-2.5 text-xs dark:border-white/10">
            <flux:icon :name="match (true) { str_starts_with($ev->kind, 'screenshot') => 'photo', $ev->kind === 'pdf' => 'document', $ev->kind === 'log' => 'command-line', default => 'chat-bubble-left' }" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
            <div class="min-w-0 flex-1">
                <p class="font-medium text-zinc-700 dark:text-zinc-200">{{ \App\Models\QaEvidence::KINDS[$ev->kind] ?? $ev->kind }}
                    @if($ev->path)
                        · <a href="{{ route('qa-audit.evidence', $ev) }}" class="text-orange-600 hover:underline">{{ $ev->original_name ?? 'Download' }}</a>
                    @endif
                </p>
                @if($ev->note)<p class="break-words text-zinc-600 dark:text-zinc-300">{{ $ev->note }}</p>@endif
                <p class="text-zinc-400">{{ $ev->uploader?->name ?? 'System' }} · {{ $ev->created_at->format('d M Y H:i') }}</p>
            </div>
        </div>
    @empty
        <p class="text-xs text-zinc-500">No evidence attached.</p>
    @endforelse

    @if($canManage)
        <form wire:submit="addEvidence" class="space-y-2 rounded-lg bg-zinc-50 p-3 dark:bg-white/5">
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <select wire:model="evidenceForm.kind" class="{{ $inputClass }}" aria-label="Evidence type">
                    @foreach(\App\Models\QaEvidence::KINDS as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
                <input type="file" wire:model="evidenceFile" accept=".png,.jpg,.jpeg,.webp,.pdf,.txt,.log" class="block w-full min-w-0 text-xs text-zinc-600 file:mr-2 file:rounded-md file:border-0 file:bg-white file:px-2 file:py-1.5 file:text-xs file:font-medium dark:text-zinc-300 dark:file:bg-zinc-800" aria-label="Evidence file">
            </div>
            <textarea wire:model="evidenceForm.note" rows="2" placeholder="Note (required without a file)" class="{{ $inputClass }} h-auto py-2"></textarea>
            @error('evidenceFile')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('evidenceForm.note')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <flux:button type="submit" size="xs" icon="paper-clip">Attach</flux:button>
        </form>
    @endif
</section>
