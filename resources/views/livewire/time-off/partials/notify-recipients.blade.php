{{-- People HR adds to the decision's email, besides the employee and the approvers already told (9 Nov 2026). --}}
<div class="space-y-2" data-notify-recipients>
    <p class="text-xs font-medium text-zinc-700 dark:text-zinc-200">Also notify <span class="font-normal text-zinc-500 dark:text-zinc-400">(optional — they get the decision in the app and by email)</span></p>
    <div class="flex flex-wrap gap-1.5">
        @foreach($this->recipientOptions as $person)
            <button type="button" wire:key="recipient-{{ $person['id'] }}"
                wire:click="{{ $person['selected'] ? 'removeRecipient' : 'addRecipient' }}({{ $person['id'] }})"
                data-recipient="{{ $person['id'] }}" data-selected="{{ $person['selected'] ? 'true' : 'false' }}"
                title="{{ $person['note'] }}"
                @class([
                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition',
                    'border-orange-500 bg-orange-500 text-white' => $person['selected'],
                    'border-zinc-200 text-zinc-700 hover:bg-zinc-50 dark:border-white/10 dark:text-zinc-200 dark:hover:bg-white/5' => ! $person['selected'],
                ])>
                @if($person['selected'])<flux:icon.check variant="micro" class="size-3" />@else<flux:icon.plus variant="micro" class="size-3" />@endif
                {{ $person['name'] }}
                @if($person['note'] && ! str_contains((string) $person['note'], '@'))<span class="opacity-75">· {{ $person['note'] }}</span>@endif
            </button>
        @endforeach
    </div>
    <flux:input size="sm" wire:model.live.debounce.300ms="recipientSearch" icon="magnifying-glass" placeholder="Add someone by name or email…" />
</div>
