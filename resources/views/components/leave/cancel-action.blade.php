{{-- The employee's cancel action for one leave request. Pending: withdraw it
     at once. Approved: never cancelled here — ask HR (the leave stays approved
     until HR decides); while that request is open, show it and allow taking it
     back. $parent: rendered inside a child component of My Time Off. --}}
@props(['request', 'parent' => false])
@php
    $call = fn (string $method) => ($parent ? '$parent.' : '').$method.'('.$request->id.')';
    $openCancellation = $request->status === 'approved'
        ? ($request->relationLoaded('pendingCancellation') ? $request->pendingCancellation : $request->pendingCancellation()->first())
        : null;
@endphp
@if(in_array($request->status, ['pending', 'pending_hr'], true))
    <flux:button size="xs" variant="ghost" wire:click="{{ $call('cancelRequest') }}" wire:confirm="Withdraw this leave request? It has not been approved yet.">Cancel</flux:button>
@elseif($request->status === 'approved')
    @if($openCancellation)
        <x-pulse.badge color="amber" data-cancellation-pending title="Sent {{ $openCancellation->created_at?->format('d M Y') }}: {{ $openCancellation->reason }}">Cancellation requested</x-pulse.badge>
        <flux:button size="xs" variant="ghost" wire:click="{{ $call('withdrawCancellationRequest') }}" wire:confirm="Withdraw your cancellation request? The leave stays approved.">Withdraw request</flux:button>
    @else
        <flux:button size="xs" variant="ghost" wire:click="{{ $call('cancelRequest') }}" data-request-cancellation>Request Cancellation</flux:button>
    @endif
@endif
