{{-- Edit / Delete a regularisation (App\Livewire\Concerns\ManagesRegularisations).
     Deleting — or changing an approved request — always confirms first. --}}
@if($showManageRegModal && ($managed = $this->managedRegularisation()))
    @php
        $isApproved = $managed->status === 'approved';
        $isPunch = ! $managed->isLeave() && $managed->regularisation_type !== 'half_day';
        $isDelete = $manageRegMode === 'delete';
        $statusChip = match ($managed->status) {
            'approved' => 'bg-emerald-100 text-emerald-700',
            'rejected' => 'bg-rose-100 text-rose-600',
            default => 'bg-amber-100 text-amber-700',
        };
    @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.closeRegularisationManage()" data-reg-manage-modal>
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.closeRegularisationManage()"></div>
        <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl ring ring-black/5 dark:bg-zinc-900" role="dialog" aria-modal="true">
            <button type="button" @click="$wire.closeRegularisationManage()" class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-300" aria-label="Close">
                <flux:icon.x-mark class="size-5" />
            </button>

            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ $isDelete ? 'Delete regularisation' : 'Edit regularisation' }}</flux:heading>
                    <flux:subheading>{{ $managed->employee?->user?->name ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($managed->work_date)->format('d M Y') }}</flux:subheading>
                </div>

                <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/70 px-3 py-2 text-xs dark:border-zinc-800 dark:bg-zinc-800/40">
                    <span class="font-mono font-bold text-zinc-700 dark:text-zinc-200">
                        @if($managed->isLeave()) Leave regularisation
                        @elseif($managed->regularisation_type === 'half_day') Half day · {{ ucfirst((string) $managed->half_day_period) }} half
                        @else {{ $managed->requested_check_in ? \Illuminate\Support\Carbon::parse($managed->requested_check_in)->format('H:i') : '—' }} → {{ $managed->requested_check_out ? \Illuminate\Support\Carbon::parse($managed->requested_check_out)->format('H:i') : '—' }}
                        @endif
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $statusChip }}">{{ $managed->status }}</span>
                </div>

                @if($isApproved)
                    <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                        <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
                        <p>
                            This request is approved and already changed attendance.
                            {{ $isDelete
                                ? 'Deleting it reverts that correction: the punches it added are removed and the day is rebuilt from the genuine biometric punches.'
                                : 'Saving reverts the current correction, rebuilds the day from the genuine biometric punches, then applies the corrected times.' }}
                            Raw device punches are never changed. The change is recorded in the activity log.
                        </p>
                    </div>
                @elseif($isDelete)
                    <p class="text-sm text-zinc-600 dark:text-zinc-300">The request will be removed. It has not changed attendance, so nothing else is affected.</p>
                @endif

                @unless($isDelete)
                    @if($isPunch)
                        <div class="grid grid-cols-2 gap-3">
                            <flux:input type="time" wire:model="manageRegCheckIn" label="Check in" />
                            <flux:input type="time" wire:model="manageRegCheckOut" label="Check out" />
                        </div>
                    @elseif($managed->regularisation_type === 'half_day' && ! $managed->isLeave())
                        <flux:select wire:model="manageRegHalfDay" label="Half">
                            <flux:select.option value="first">First half</flux:select.option>
                            <flux:select.option value="second">Second half</flux:select.option>
                        </flux:select>
                    @endif
                    <flux:textarea wire:model="manageRegRequestReason" label="Request reason" rows="2" />
                @endunless

                <flux:textarea wire:model="manageRegReason" :label="$isDelete ? 'Why are you deleting it?' : 'Why are you changing it?'" rows="2" placeholder="Recorded in the activity log" />

                @if($isApproved)
                    <flux:checkbox wire:model="manageRegConfirm" :label="$isDelete ? 'I understand this reverts the approved correction.' : 'I understand this re-applies the approved correction.'" />
                    @error('manageRegConfirm')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                @endif

                <div class="flex justify-end gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <button type="button" @click="$wire.closeRegularisationManage()" class="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-800/50">Cancel</button>
                    @if($isDelete)
                        <flux:button wire:click="confirmRegularisationDelete" variant="danger" icon="trash">{{ $isApproved ? 'Revert & delete' : 'Delete' }}</flux:button>
                    @else
                        <flux:button wire:click="saveRegularisationEdit" variant="primary" icon="check">Save changes</flux:button>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
