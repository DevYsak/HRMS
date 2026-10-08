{{-- One pending regularisation ($req) as a table row: Review for an HR approver, "Awaiting HR" otherwise. --}}
<tr>
    <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">
        {{ $req->employee->user->name }}
    </td>
    <td class="pulse-td">
        {{ \Carbon\Carbon::parse($req->work_date)->format('M d, Y') }}
    </td>
    <td class="pulse-td">
        {{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} - {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}
    </td>
    <td class="pulse-td text-zinc-500 truncate max-w-xs" title="{{ $req->reason }}">
        {{ $req->reason }}
    </td>
    <td class="pulse-td pr-6 text-right!">
        @if(auth()->user()->canApproveRegularisations())
            <flux:button wire:click="openReviewModal({{ $req->id }})" size="xs" variant="primary">Review</flux:button>
        @else
            <span class="inline-flex shrink-0 items-center rounded-lg bg-zinc-100 px-2.5 py-1 text-[10px] font-bold uppercase text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Awaiting HR</span>
        @endif
    </td>
</tr>
