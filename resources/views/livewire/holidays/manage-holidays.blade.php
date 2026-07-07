<flux:main class="min-h-screen bg-[#FFF8F3] dark:bg-white/5 p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Holiday Management</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Settings</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Holidays</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 p-1 shadow-sm">
            <button wire:click="setYear({{ $year - 1 }})" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-left class="size-4" /></button>
            <span class="min-w-[3rem] px-1 text-center text-xs font-black text-zinc-700 dark:text-zinc-200">{{ $year }}</span>
            <button wire:click="setYear({{ $year + 1 }})" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-right class="size-4" /></button>
        </div>
        <a href="{{ route('settings.holiday-pay') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.banknotes class="size-4 text-orange-500" /> Pay Policy</a>
        <button wire:click="exportCsv" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export</button>
        <button wire:click="openCreate" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-300/40 transition hover:shadow-xl"><flux:icon.plus class="size-4" /> Add Holiday</button>
    </div>
</div>

{{-- ═══════════════ STATS + FILTERS ═══════════════ --}}
<div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
    @foreach([['Total Holidays', $stats['total'], 'calendar-days', '#F97316'], ['Paid', $stats['paid'], 'banknotes', '#10b981'], ['Optional', $stats['optional'], 'hand-raised', '#0ea5e9'], ['Upcoming', $stats['upcoming'], 'clock', '#8b5cf6']] as [$label, $value, $icon, $color])
        <div class="flex items-center gap-3 rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
            <div><div class="text-xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div><div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div></div>
        </div>
    @endforeach
</div>

<div class="mb-4 flex flex-wrap items-center gap-2 rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-3 shadow-sm">
    {{-- View toggle --}}
    <div class="flex items-center gap-1 rounded-xl bg-orange-50/60 p-1">
        @foreach(['calendar' => 'Month', 'list' => 'List', 'year' => 'Year'] as $v => $vl)
            <button wire:click="$set('view', '{{ $v }}')" class="rounded-lg px-3 py-1.5 text-xs font-bold transition {{ $view === $v ? 'bg-orange-500 text-white shadow' : 'text-zinc-500 dark:text-zinc-400 hover:text-orange-500' }}">{{ $vl }}</button>
        @endforeach
    </div>
    <div class="ml-auto flex flex-wrap items-center gap-2">
        <x-clean-select model="filterType" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All types']], collect($types)->map(fn ($t) => ['value' => $t['value'], 'label' => $t['label']])->all())" />
        <x-clean-select model="filterOffice" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All branches']], collect($offices)->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->all())" />
        <x-clean-select model="filterStatus" :live="true"
            :options="[['value' => 'active', 'label' => 'Active'], ['value' => 'archived', 'label' => 'Archived'], ['value' => 'all', 'label' => 'All']]" />
    </div>
</div>

{{-- Legend --}}
<div class="mb-3 flex flex-wrap items-center gap-3 text-[10px] font-bold">
    @foreach($types as $t)
        <span class="inline-flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"><span class="size-2.5 rounded-full" style="background: {{ $t['color'] }}"></span>{{ $t['label'] }}</span>
    @endforeach
</div>

{{-- ═══════════════ CALENDAR (MONTH) ═══════════════ --}}
@if($view === 'calendar')
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $monthLabel }}</div>
            <div class="flex items-center gap-1">
                <button wire:click="previousMonth" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-left class="size-4" /></button>
                <button wire:click="nextMonth" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-right class="size-4" /></button>
            </div>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase tracking-wider text-zinc-400">
            @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d)<div class="py-1.5">{{ $d }}</div>@endforeach
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1">
            @foreach($calendarDays as $day)
                <div wire:click="openCreate('{{ $day['date'] }}')"
                    class="group min-h-[76px] cursor-pointer rounded-xl border p-1.5 transition hover:border-orange-300 hover:shadow-sm {{ $day['inMonth'] ? 'border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900' : 'border-transparent bg-zinc-50/40' }} {{ $day['isToday'] ? 'ring-2 ring-orange-300' : '' }}">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold {{ $day['inMonth'] ? ($day['isWeekend'] ? 'text-zinc-400' : 'text-zinc-700 dark:text-zinc-200') : 'text-zinc-300' }}">{{ $day['day'] }}</span>
                        @if($day['inMonth'])<flux:icon.plus class="size-3 text-zinc-200 opacity-0 transition group-hover:opacity-100" />@endif
                    </div>
                    <div class="mt-0.5 space-y-0.5">
                        @foreach($day['holidays'] as $h)
                            <button wire:click.stop="showDetail({{ $h['id'] }})" class="block w-full truncate rounded px-1 py-0.5 text-left text-[9px] font-bold text-white" style="background: {{ $h['color'] }}" title="{{ $h['name'] }} · {{ $h['type'] }}">{{ $h['name'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ═══════════════ LIST ═══════════════ --}}
@if($view === 'list')
    <div class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 shadow-sm">
        @if($holidays->count() > 0)
            <table class="w-full text-left text-xs">
                <thead class="border-b border-orange-100/70 bg-orange-50/40">
                    <tr class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">
                        <th class="px-5 py-2.5">Holiday</th><th class="py-2.5">Date</th><th class="py-2.5">Type</th><th class="py-2.5">Scope</th><th class="py-2.5">Flags</th><th class="px-5 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50">
                    @foreach($holidays as $h)
                        <tr class="transition hover:bg-orange-50/40">
                            <td class="px-5 py-2.5">
                                <button wire:click="showDetail({{ $h->id }})" class="flex items-center gap-2 text-left">
                                    <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $h->displayColor() }}"></span>
                                    <span class="font-black text-zinc-900 dark:text-white {{ $h->is_active ? '' : 'line-through opacity-50' }}">{{ $h->name }}</span>
                                </button>
                            </td>
                            <td class="py-2.5"><div class="font-bold text-zinc-700 dark:text-zinc-200">{{ $h->date->format('d M Y') }}</div><div class="text-[9px] text-zinc-400">{{ $h->date->format('l') }}</div></td>
                            <td class="py-2.5"><span class="rounded-full px-2 py-0.5 text-[9px] font-bold text-white" style="background: {{ $h->displayColor() }}">{{ $h->typeLabel() }}</span></td>
                            <td class="py-2.5 text-zinc-500 dark:text-zinc-400">{{ $h->office?->name ?? ($h->department?->name ?? 'Company-wide') }}</td>
                            <td class="py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @if($h->is_paid)<span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[8px] font-bold text-emerald-600">Paid</span>@endif
                                    @if($h->is_optional)<span class="rounded bg-sky-50 px-1.5 py-0.5 text-[8px] font-bold text-sky-600">Optional</span>@endif
                                    @if($h->is_recurring)<span class="rounded bg-violet-50 px-1.5 py-0.5 text-[8px] font-bold text-violet-600">Yearly</span>@endif
                                    @unless($h->is_active)<span class="rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-[8px] font-bold text-zinc-500 dark:text-zinc-400">Archived</span>@endunless
                                </div>
                            </td>
                            <td class="px-5 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    <button wire:click="openEdit({{ $h->id }})" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500" title="Edit"><flux:icon.pencil-square class="size-4" /></button>
                                    <button wire:click="duplicate({{ $h->id }})" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500" title="Duplicate to next year"><flux:icon.document-duplicate class="size-4" /></button>
                                    <button wire:click="toggleArchive({{ $h->id }})" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-amber-50 hover:text-amber-500" title="{{ $h->is_active ? 'Archive' : 'Restore' }}"><flux:icon :icon="$h->is_active ? 'archive-box' : 'archive-box-arrow-down'" class="size-4" /></button>
                                    <button wire:click="delete({{ $h->id }})" wire:confirm="Delete this holiday permanently?" class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-rose-50 hover:text-rose-500" title="Delete"><flux:icon.trash class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-14 text-center text-sm text-zinc-400"><flux:icon.calendar-days class="mx-auto mb-2 size-9 opacity-30" /> No holidays for {{ $year }}. <button wire:click="openCreate" class="font-bold text-orange-500 hover:underline">Add one</button>.</div>
        @endif
    </div>
@endif

{{-- ═══════════════ YEAR (12 mini-months) ═══════════════ --}}
@if($view === 'year')
    @php $byMonth = $holidays->groupBy(fn ($h) => (int) $h->date->format('n')); @endphp
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @for($m = 1; $m <= 12; $m++)
            @php $mh = $byMonth[$m] ?? collect(); @endphp
            <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                <div class="mb-2 flex items-center justify-between">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ \Carbon\Carbon::create($year, $m, 1)->format('F') }}</div>
                    <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">{{ $mh->count() }}</span>
                </div>
                @forelse($mh->sortBy('date') as $h)
                    <button wire:click="showDetail({{ $h->id }})" class="mb-1 flex w-full items-center gap-2 rounded-lg px-2 py-1 text-left text-xs transition hover:bg-orange-50/60">
                        <span class="w-8 shrink-0 font-black tabular-nums text-zinc-400">{{ $h->date->format('d') }}</span>
                        <span class="size-2 shrink-0 rounded-full" style="background: {{ $h->displayColor() }}"></span>
                        <span class="truncate font-semibold text-zinc-700 dark:text-zinc-200">{{ $h->name }}</span>
                    </button>
                @empty
                    <p class="py-2 text-center text-[10px] text-zinc-300">No holidays</p>
                @endforelse
            </div>
        @endfor
    </div>
@endif

{{-- ═══════════════ CREATE / EDIT MODAL ═══════════════ --}}
@if($showForm)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('showForm', false)">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showForm', false)"></div>
        <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">{{ $editingId ? 'Edit Holiday' : 'Add Holiday' }}</flux:heading>
                <button @click="$wire.set('showForm', false)" class="text-zinc-400 hover:text-zinc-600 dark:text-zinc-300"><flux:icon.x-mark class="size-5" /></button>
            </div>
            <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><flux:input wire:model="form.name" label="Holiday Name" placeholder="e.g. Diwali" /></div>
                    <flux:input wire:model="form.date" type="date" label="Date" />
                    <div>
                        <x-clean-select model="form.holiday_type" label="Type" :live="false"
                            :options="collect($types)->map(fn ($t) => ['value' => $t['value'], 'label' => $t['label']])->all()" />
                    </div>
                    <flux:input wire:model="form.category" label="Category" placeholder="Optional" />
                    <div>
                        <flux:label>Color</flux:label>
                        <input type="color" wire:model="form.color" class="mt-1 h-9 w-full cursor-pointer rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-1">
                    </div>
                    <div>
                        <x-clean-select model="form.office_id" label="Branch / Office" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'All branches']], collect($offices)->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->all())" />
                    </div>
                    <div>
                        <x-clean-select model="form.department_id" label="Department" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'All departments']], collect($departments)->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
                    </div>
                    <div class="col-span-2"><flux:textarea wire:model="form.description" label="Description" rows="2" placeholder="Optional notes" /></div>
                </div>
                <div class="flex flex-wrap gap-4 rounded-xl bg-orange-50/40 px-3 py-2.5">
                    <label class="flex items-center gap-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300"><input type="checkbox" wire:model="form.is_paid" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400"> Paid Holiday</label>
                    <label class="flex items-center gap-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300"><input type="checkbox" wire:model="form.is_optional" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400"> Optional</label>
                    <label class="flex items-center gap-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300"><input type="checkbox" wire:model="form.is_recurring" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400"> Recurring Yearly</label>
                </div>
                @error('form.name')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
                @error('form.date')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button @click="$wire.set('showForm', false)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-50 dark:bg-zinc-800/50">Cancel</button>
                <flux:button wire:click="save" variant="primary">{{ $editingId ? 'Save Changes' : 'Create Holiday' }}</flux:button>
            </div>
        </div>
    </div>
@endif

{{-- ═══════════════ DETAILS POPUP ═══════════════ --}}
@if($detail)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" x-data x-on:keydown.escape.window="$wire.set('detail', null)">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('detail', null)"></div>
        <div class="relative w-full max-w-sm overflow-hidden rounded-2xl bg-white dark:bg-zinc-900 shadow-xl ring ring-black/5">
            <div class="p-5 text-white" style="background: {{ $detail['color'] }}">
                <div class="text-[10px] font-bold uppercase tracking-widest opacity-80">{{ $detail['type'] }}</div>
                <div class="text-lg font-black">{{ $detail['name'] }}</div>
                <div class="text-sm opacity-90">{{ $detail['date'] }}</div>
            </div>
            <div class="space-y-2 p-5 text-xs">
                @if($detail['description'])<p class="text-zinc-600 dark:text-zinc-300">{{ $detail['description'] }}</p>@endif
                <div class="flex items-center justify-between"><span class="text-zinc-400">Scope</span><span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $detail['scope'] }}</span></div>
                <div class="flex items-center justify-between"><span class="text-zinc-400">Country</span><span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $detail['country'] }}</span></div>
                @if($detail['category'])<div class="flex items-center justify-between"><span class="text-zinc-400">Category</span><span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $detail['category'] }}</span></div>@endif
                <div class="flex flex-wrap gap-1.5 pt-1">
                    @if($detail['is_paid'])<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-600">Paid</span>@endif
                    @if($detail['is_optional'])<span class="rounded-full bg-sky-50 px-2 py-0.5 text-[9px] font-bold text-sky-600">Optional</span>@endif
                    @if($detail['is_recurring'])<span class="rounded-full bg-violet-50 px-2 py-0.5 text-[9px] font-bold text-violet-600">Recurring Yearly</span>@endif
                    @unless($detail['is_active'])<span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-[9px] font-bold text-zinc-500 dark:text-zinc-400">Archived</span>@endunless
                </div>
                @if($detail['creator'])<div class="border-t border-zinc-100 dark:border-zinc-800 pt-2 text-[10px] text-zinc-400">Added by {{ $detail['creator'] }}</div>@endif
            </div>
            <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 px-5 py-3">
                <button @click="$wire.set('detail', null)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-50 dark:bg-zinc-800/50">Close</button>
                <flux:button wire:click="openEdit({{ $detail['id'] }})" variant="primary">Edit</flux:button>
            </div>
        </div>
    </div>
@endif

</flux:main>
