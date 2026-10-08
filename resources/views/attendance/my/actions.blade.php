{{-- Quick actions — only those this user can use. --}}
@php
    $routeAccess = app(\App\Services\Help\RouteAccess::class);
    $user = auth()->user();
    $btn = 'inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 transition hover:border-orange-300 hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200';
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="actions-title" data-quick-actions>
    <h2 id="actions-title" class="mb-4 flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.bolt class="size-6 text-orange-500" /> Quick Actions</h2>
    <div class="flex flex-wrap gap-2">
        <button type="button" wire:click="openRegularisation('{{ today()->toDateString() }}')" class="{{ $btn }}"><flux:icon.pencil-square class="size-4 text-orange-500" /> Request Regularisation</button>
        @if($routeAccess->allows($user, 'wfh.my'))
            <a href="{{ route('wfh.my') }}" wire:navigate class="{{ $btn }}"><flux:icon.home class="size-4 text-orange-500" /> Work From Home</a>
        @endif
        <button type="button" @click="$flux.modal('my-requests').show()" class="{{ $btn }}"><flux:icon.inbox-stack class="size-4 text-orange-500" /> View My Requests</button>
        @if($routeAccess->allows($user, 'help.employee-guide'))
            <a href="{{ route('help.employee-guide') }}#attendance" wire:navigate class="{{ $btn }}"><flux:icon.book-open class="size-4 text-orange-500" /> Attendance Policy</a>
        @endif
        <button type="button" wire:click="exportLog" class="{{ $btn }}"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Download Attendance</button>
    </div>
</section>

{{-- My regularisation requests (pending ones can be edited or deleted). --}}
<flux:modal name="my-requests" class="max-w-2xl">
    <div class="space-y-4" data-my-regularisations>
        <div>
            <flux:heading size="lg">My regularisations</flux:heading>
            <flux:subheading>Pending requests can be edited or deleted. Decided ones are locked — ask HR.</flux:subheading>
        </div>
        @forelse($this->myRegularisations as $myReg)
            @php
                $chip = match ($myReg->status) {
                    'approved' => 'bg-emerald-50 text-emerald-700',
                    'rejected' => 'bg-rose-50 text-rose-700',
                    'cancelled' => 'bg-zinc-100 text-zinc-500',
                    default => 'bg-amber-50 text-amber-700',
                };
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($myReg->work_date)->format('d M Y') }}</span>
                    <span class="tabular-nums text-zinc-600 dark:text-zinc-300">
                        @if($myReg->isLeave()) Leave
                        @elseif($myReg->regularisation_type === 'half_day') Half day · {{ ucfirst((string) $myReg->half_day_period) }}
                        @else {{ $myReg->requested_check_in ? \Illuminate\Support\Carbon::parse($myReg->requested_check_in)->format('H:i') : '—' }} → {{ $myReg->requested_check_out ? \Illuminate\Support\Carbon::parse($myReg->requested_check_out)->format('H:i') : '—' }}
                        @endif
                    </span>
                    <span class="max-w-[16rem] truncate text-xs italic text-zinc-400">“{{ $myReg->reason }}”</span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize {{ $chip }}">{{ $myReg->status }}</span>
                </div>
                @include('livewire.attendance.partials.regularisation-actions', ['reg' => $myReg])
            </div>
        @empty
            <p class="text-sm text-zinc-500">You have no regularisation requests.</p>
        @endforelse
    </div>
</flux:modal>
