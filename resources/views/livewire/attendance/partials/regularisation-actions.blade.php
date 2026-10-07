{{-- Edit / Delete buttons for one regularisation ($reg), or a lock with the reason. --}}
@php
    $regManager = app(\App\Services\Attendance\RegularisationManager::class);
    $regCanEdit = $regManager->canEdit(auth()->user(), $reg);
    $regCanDelete = $regManager->canDelete(auth()->user(), $reg);
@endphp
<div class="flex shrink-0 items-center gap-1.5" data-reg-actions="{{ $reg->id }}">
    @if($regCanEdit)
        <button type="button" wire:click="openRegularisationEdit({{ $reg->id }})" class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-[10px] font-bold text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"><flux:icon.pencil-square class="size-3" /> Edit</button>
    @endif
    @if($regCanDelete)
        <button type="button" wire:click="openRegularisationDelete({{ $reg->id }})" class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-[10px] font-bold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:bg-zinc-900 dark:hover:bg-rose-500/10"><flux:icon.trash class="size-3" /> Delete</button>
    @endif
    @if(! $regCanEdit && ! $regCanDelete)
        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-zinc-400" title="{{ $regManager->lockReason(auth()->user(), $reg) }}"><flux:icon.lock-closed class="size-3" /> Locked</span>
    @endif
</div>
