{{-- One pending regularisation ($req) with Review / Edit / Delete for an HR approver, "Awaiting HR" otherwise. --}}
<div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 px-3 py-2">
    <div class="flex min-w-0 items-center gap-3 text-xs">
        <span class="font-black text-zinc-900 dark:text-white">{{ $req->employee?->user?->name ?? '—' }}</span>
        <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($req->work_date)->format('d M Y') }}</span>
        <span class="font-mono font-bold text-amber-700">{{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} → {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}</span>
        <span class="hidden truncate italic text-zinc-400 md:inline">“{{ \Illuminate\Support\Str::limit($req->reason, 60) }}”</span>
    </div>
    @if(auth()->user()->canApproveRegularisations())
    <div class="flex shrink-0 items-center gap-1.5">
        <button wire:click="openReviewModal({{ $req->id }})" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.eye class="size-3" /> Review</button>
        @include('livewire.attendance.partials.regularisation-actions', ['reg' => $req])
    </div>
    @else
    <span class="inline-flex shrink-0 items-center rounded-lg bg-zinc-100 px-2.5 py-1 text-[10px] font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Awaiting HR</span>
    @endif
</div>
