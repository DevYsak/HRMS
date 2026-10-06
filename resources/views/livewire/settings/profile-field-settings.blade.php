<flux:main class="space-y-6 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Profile Fields</flux:heading>
        <flux:subheading>Choose what employees must complete. Required fields count toward profile completion; Optional fields are shown but never lower the score; HR-only fields are hidden from employees and only HR can change them.</flux:subheading>
    </div>

    @php
        $options = [
            \App\Models\ProfileFieldSetting::REQUIRED => 'Required',
            \App\Models\ProfileFieldSetting::OPTIONAL => 'Optional',
            \App\Models\ProfileFieldSetting::HR_ONLY => 'HR-only',
        ];
        $tierNote = [
            \App\Services\Profile\ProfileFieldRegistry::TIER_EDITABLE => 'Employee edits directly',
            \App\Services\Profile\ProfileFieldRegistry::TIER_APPROVAL => 'Employee requests, HR approves',
        ];
    @endphp

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        @foreach($fields as $group => $rows)
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h3 class="mb-2 text-xs font-bold uppercase tracking-widest text-zinc-500">{{ \Illuminate\Support\Str::headline($group) }}</h3>
                <div class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($rows as $row)
                        <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="pf-{{ $row['key'] }}">
                            <div>
                                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $row['label'] }}</div>
                                <div class="text-[11px] text-zinc-400">{{ $tierNote[$row['tier']] ?? '' }}</div>
                            </div>
                            <select wire:change="setRequirement('{{ $row['key'] }}', $event.target.value)" aria-label="{{ $row['label'] }} requirement"
                                class="rounded-lg border border-zinc-200 bg-white py-1 pl-2 pr-7 text-xs font-semibold dark:border-zinc-700 dark:bg-zinc-900">
                                @foreach($options as $value => $label)
                                    <option value="{{ $value }}" @selected($row['requirement'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <h3 class="mb-1 text-xs font-bold uppercase tracking-widest text-zinc-500">KYC documents</h3>
            <p class="mb-2 text-[11px] text-zinc-400">Uploaded by the employee on My Profile and stored privately; only holders of View KYC Documents can open them.</p>
            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($kyc as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="pf-{{ $row['key'] }}">
                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $row['label'] }}</div>
                        <select wire:change="setRequirement('{{ $row['key'] }}', $event.target.value)" aria-label="{{ $row['label'] }} requirement"
                            class="rounded-lg border border-zinc-200 bg-white py-1 pl-2 pr-7 text-xs font-semibold dark:border-zinc-700 dark:bg-zinc-900">
                            @foreach($options as $value => $label)
                                <option value="{{ $value }}" @selected($row['requirement'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

</flux:main>
