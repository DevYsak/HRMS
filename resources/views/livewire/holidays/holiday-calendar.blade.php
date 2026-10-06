<flux:main class="space-y-5 p-4 md:p-6">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl">Holiday Calendar</flux:heading>
            <flux:subheading>Your holidays ({{ $calendar }} calendar) and the Mandatory December shutdown. Weekly offs are Saturday and Sunday.</flux:subheading>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900">
                @foreach(['month' => 'Month', 'year' => 'Year'] as $v => $label)
                    <button type="button" wire:click="$set('view', '{{ $v }}')" @class(['rounded-lg px-3 py-1 text-xs font-bold', 'bg-orange-500 text-white' => $view === $v, 'text-zinc-500 hover:text-orange-600' => $view !== $v])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-900">
                <button type="button" wire:click="previous" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-white/5" aria-label="Previous"><flux:icon.chevron-left class="size-4" /></button>
                <span class="min-w-[7rem] text-center text-xs font-black text-zinc-700 dark:text-zinc-200">{{ $view === 'year' ? $cursor->format('Y') : $cursor->format('F Y') }}</span>
                <button type="button" wire:click="next" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-white/5" aria-label="Next"><flux:icon.chevron-right class="size-4" /></button>
            </div>
            <select wire:change="setYear($event.target.value)" aria-label="Year" class="rounded-xl border border-zinc-200 bg-white py-1.5 pl-2 pr-7 text-xs font-semibold dark:border-zinc-700 dark:bg-zinc-900">
                @foreach(range(now()->year + 1, now()->year - 4) as $y)
                    <option value="{{ $y }}" @selected($cursor->year === $y)>{{ $y }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap gap-3 text-[11px] text-zinc-500">
        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-orange-500"></span> Holiday</span>
        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-sky-500"></span> Optional holiday</span>
        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm border border-dashed border-rose-400 bg-rose-50"></span> MDL shutdown</span>
        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-zinc-100 dark:bg-white/10"></span> Weekly off</span>
    </div>

    @if($view === 'month')
        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="grid min-w-[560px] grid-cols-7 gap-1.5">
                @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow)
                    <div class="pb-1 text-center text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $dow }}</div>
                @endforeach
                @foreach($grid as $cell)
                    <div wire:key="hc-{{ $cell['date'] }}" @class([
                        'min-h-[4.5rem] rounded-xl border p-1.5 text-xs',
                        'border-dashed border-rose-300 bg-rose-50/70 dark:border-rose-500/40 dark:bg-rose-500/10' => $cell['mdl'],
                        'border-zinc-100 bg-zinc-50 dark:border-white/5 dark:bg-white/5' => ! $cell['mdl'] && $cell['weekly_off'],
                        'border-zinc-100 bg-white dark:border-white/5 dark:bg-zinc-900' => ! $cell['mdl'] && ! $cell['weekly_off'],
                        'opacity-40' => ! $cell['in_month'],
                        'ring-2 ring-orange-400' => $cell['today'],
                    ])>
                        <div class="font-bold text-zinc-600 dark:text-zinc-300">{{ $cell['day'] }}</div>
                        @foreach($cell['holidays'] as $h)
                            <div class="mt-0.5 truncate rounded px-1 text-[10px] font-semibold text-white" style="background: {{ $h->is_optional ? '#0EA5E9' : $h->displayColor() }}" title="{{ $h->name }}{{ $h->is_optional ? ' (optional)' : '' }}">{{ $h->name }}</div>
                        @endforeach
                        @if($cell['mdl'])
                            <div class="mt-0.5 truncate text-[10px] font-semibold text-rose-700 dark:text-rose-300" title="{{ $cell['mdl']->description }}">MDL · {{ $cell['mdl']->description ?: 'Shutdown' }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach(range(1, 12) as $m)
                @php $key = $cursor->copy()->setDate($cursor->year, $m, 1)->format('Y-m'); @endphp
                <div wire:key="hy-{{ $key }}" class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="mb-1 text-xs font-black text-zinc-800 dark:text-zinc-100">{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m', $key)->format('F') }}</div>
                    @forelse(($byMonth[$key] ?? collect()) as $h)
                        <div class="flex items-center justify-between gap-2 py-0.5 text-xs">
                            <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $h->name }}@if($h->is_optional) <span class="text-[10px] text-sky-600">(optional)</span>@endif</span>
                            <span class="shrink-0 tabular-nums text-zinc-400">{{ $h->date->format('D d') }}</span>
                        </div>
                    @empty
                        @if(empty($mdlByMonth[$key]))
                            <div class="text-[11px] italic text-zinc-400">No holidays</div>
                        @endif
                    @endforelse
                    @foreach(($mdlByMonth[$key] ?? collect()) as $d)
                        <div class="flex items-center justify-between gap-2 py-0.5 text-xs text-rose-700 dark:text-rose-300">
                            <span>MDL · {{ $d->description ?: 'Shutdown' }}</span>
                            <span class="tabular-nums">{{ \Illuminate\Support\Carbon::parse($d->date)->format('D d') }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    @if($upcoming->isNotEmpty())
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-widest text-zinc-500">Coming up</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($upcoming as $h)
                    <span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">{{ $h->name }} · {{ $h->date->format('D d M') }}</span>
                @endforeach
            </div>
        </div>
    @endif
</flux:main>
