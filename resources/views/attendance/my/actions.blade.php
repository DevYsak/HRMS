{{-- Quick actions — only those this user can use. --}}
@php
    $routeAccess = app(\App\Services\Help\RouteAccess::class);
    $user = auth()->user();
    $row = 'group flex w-full items-center gap-3 rounded-xl border border-zinc-100 bg-white px-3 py-2.5 text-left text-sm font-medium text-zinc-700 transition hover:border-orange-200 hover:bg-orange-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400/50 dark:border-white/[0.06] dark:bg-white/[0.02] dark:text-slate-200 dark:hover:border-orange-400/30 dark:hover:bg-orange-500/[0.06]';
    $chev = 'ml-auto size-4 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-orange-500 dark:text-slate-600';
    $tile = 'flex size-8 shrink-0 items-center justify-center rounded-lg';
@endphp

<section class="{{ $ui['card'] }} min-w-0 p-5" aria-labelledby="actions-title" data-quick-actions>
    <div class="flex items-start gap-3">
        <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.bolt class="size-5" /></span>
        <div>
            <h2 id="actions-title" class="{{ $ui['title'] }}">Quick Actions</h2>
            <p class="{{ $ui['sub'] }} mt-0.5">Common attendance actions</p>
        </div>
    </div>
    <div class="mt-4 space-y-2">
        <button type="button" wire:click="openRegularisation('{{ today()->toDateString() }}')" class="{{ $row }}"><span class="{{ $tile }} bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300"><flux:icon.pencil-square class="size-4" /></span> Request Regularisation <flux:icon.chevron-right class="{{ $chev }}" /></button>
        @if($routeAccess->allows($user, 'wfh.my'))
            <a href="{{ route('wfh.my') }}" wire:navigate class="{{ $row }}"><span class="{{ $tile }} bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300"><flux:icon.home class="size-4" /></span> Work From Home <flux:icon.chevron-right class="{{ $chev }}" /></a>
        @endif
        <button type="button" @click="$flux.modal('my-requests').show()" class="{{ $row }}"><span class="{{ $tile }} bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><flux:icon.inbox-stack class="size-4" /></span> View My Requests <flux:icon.chevron-right class="{{ $chev }}" /></button>
        @if($routeAccess->allows($user, 'help.employee-guide'))
            <a href="{{ route('help.employee-guide') }}#attendance" wire:navigate class="{{ $row }}"><span class="{{ $tile }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.book-open class="size-4" /></span> Attendance Policy <flux:icon.chevron-right class="{{ $chev }}" /></a>
        @endif
        <button type="button" wire:click="exportHistory" class="{{ $row }}"><span class="{{ $tile }} bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300"><flux:icon.arrow-down-tray class="size-4" /></span> Download Attendance <flux:icon.chevron-right class="{{ $chev }}" /></button>
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
                    'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
                    'rejected' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
                    'cancelled' => 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-slate-400',
                    default => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
                };
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 text-sm dark:border-zinc-800">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="font-semibold text-zinc-900 dark:text-white">@userDate(\Illuminate\Support\Carbon::parse($myReg->work_date))</span>
                    <span class="tabular-nums text-zinc-600 dark:text-zinc-300">
                        @if($myReg->isLeave()) Leave
                        @elseif($myReg->regularisation_type === 'half_day') Half day · {{ ucfirst((string) $myReg->half_day_period) }}
                        @else @userTime($myReg->requested_check_in) → @userTime($myReg->requested_check_out)
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
