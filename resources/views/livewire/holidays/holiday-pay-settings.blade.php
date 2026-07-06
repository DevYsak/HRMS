<flux:main class="min-h-screen bg-[#FFF8F3] p-4 md:p-6">

<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900">Holiday Pay Policy</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Settings</span><flux:icon.chevron-right class="size-3" /><span>Holidays</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Pay Policy</span>
        </div>
    </div>
    <a href="{{ route('settings.holidays') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white px-3 py-2 text-xs font-bold text-zinc-600 shadow-sm transition hover:bg-orange-50"><flux:icon.calendar-days class="size-4 text-orange-500" /> Holiday Calendar</a>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">

    {{-- Allowed pay types --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-6">
        <div class="mb-1 text-sm font-black text-zinc-900">Allowed Pay Types</div>
        <p class="mb-3 text-[11px] text-zinc-400">Employees can only choose from the pay types enabled here when requesting to work a holiday.</p>
        <div class="space-y-2">
            @foreach($payTypeLabels as $val => $label)
                <label class="flex items-center gap-2.5 rounded-xl border border-zinc-100 px-3 py-2 text-sm font-semibold text-zinc-700 transition hover:bg-orange-50/40">
                    <input type="checkbox" wire:model="enabledTypes" value="{{ $val }}" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    {{ $label }}
                </label>
            @endforeach
        </div>
        @error('enabledTypes')<p class="mt-2 text-xs text-rose-500">{{ $message }}</p>@enderror

        <div class="mt-4">
            <x-clean-select model="defaultPayType" label="Default Pay Type" :live="false"
                :options="collect($payTypeLabels)->map(fn ($label, $val) => ['value' => $val, 'label' => $label])->values()->all()" />
            @error('defaultPayType')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
        </div>
    </div>

    {{-- Calculation rules --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white p-5 shadow-sm lg:col-span-6">
        <div class="mb-3 text-sm font-black text-zinc-900">Calculation Rules</div>
        <div class="space-y-3">
            <div>
                <flux:label>Double Pay Multiplier</flux:label>
                <div class="mt-1 flex items-center gap-2">
                    <input type="number" step="0.25" min="1" max="5" wire:model="doublePayMultiplier" class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0">
                    <span class="text-xs font-bold text-zinc-400">× hourly rate</span>
                </div>
                @error('doublePayMultiplier')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <flux:label>OT Rate Per Hour (override)</flux:label>
                <input type="number" step="1" min="0" wire:model="otRatePerHour" placeholder="Leave blank to use the standard OT rate" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0">
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <flux:label>Comp Off Days</flux:label>
                    <input type="number" step="0.5" min="0" max="5" wire:model="compOffDays" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0">
                </div>
                <div>
                    <flux:label>Extra Leave Days</flux:label>
                    <input type="number" step="0.5" min="0" max="5" wire:model="extraLeaveDays" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0">
                </div>
                <div>
                    <flux:label>Half Day Credit</flux:label>
                    <input type="number" step="0.25" min="0" max="5" wire:model="halfDayCompOffDays" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0">
                </div>
            </div>
            <label class="flex items-center gap-2.5 rounded-xl bg-orange-50/40 px-3 py-2.5 text-xs font-semibold text-zinc-600">
                <input type="checkbox" wire:model="autoApproveAfterManager" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                Auto-approve holiday work once the manager approves (skip a separate HR step)
            </label>
            <div>
                <flux:label>Custom Company Policy Notes</flux:label>
                <textarea wire:model="policyNotes" rows="3" placeholder="Optional free-text notes shown to HR reviewers" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 flex justify-end">
    <flux:button wire:click="save" variant="primary">Save Policy</flux:button>
</div>

</flux:main>
