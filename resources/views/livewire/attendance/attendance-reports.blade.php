<flux:main class="min-h-screen bg-[#FFF8F3] dark:bg-white/5 p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Attendance Reports</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Reports</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('reports.attendance-report-csv', $this->exportParams) }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.table-cells class="size-4 text-emerald-500" /> CSV / Excel</a>
        <a href="{{ route('reports.attendance-report-pdf', $this->exportParams) }}" target="_blank"
           class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.document-arrow-down class="size-4 text-rose-500" /> PDF</a>
        <button onclick="window.print()"
           class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50 print:hidden"><flux:icon.printer class="size-4 text-zinc-500 dark:text-zinc-400" /> Print</button>
    </div>
</div>

{{-- ═══════════════ REPORT TYPE PICKER ═══════════════ --}}
<div class="mb-4 flex flex-wrap gap-2 print:hidden">
    @foreach($types as $key => $label)
        <button wire:click="$set('type', '{{ $key }}')"
            class="rounded-xl border px-3 py-1.5 text-xs font-bold transition {{ $type === $key ? 'border-orange-400 bg-orange-500 text-white shadow' : 'border-orange-100 bg-white dark:bg-zinc-900 text-zinc-500 dark:text-zinc-400 hover:bg-orange-50' }}">{{ $label }}</button>
    @endforeach
</div>

{{-- ═══════════════ FILTERS ═══════════════ --}}
<div class="mb-4 rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm print:hidden">
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-7">
        <div>
            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-zinc-400">From</label>
            <input type="date" wire:model.live="from" class="w-full rounded-lg border border-orange-100 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300 focus:border-orange-400 focus:ring-0">
        </div>
        <div>
            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-zinc-400">To</label>
            <input type="date" wire:model.live="to" class="w-full rounded-lg border border-orange-100 bg-white dark:bg-zinc-900 px-2.5 py-1.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300 focus:border-orange-400 focus:ring-0">
        </div>
        <div>
            <x-clean-select model="departmentId" label="Department" :live="true"
                :options="[['value' => '', 'label' => 'All'], ...collect($departments)->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()]" />
        </div>
        <div>
            <x-clean-select model="officeId" label="Branch / Office" :live="true"
                :options="[['value' => '', 'label' => 'All'], ...collect($offices)->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->all()]" />
        </div>
        <div>
            <x-clean-select model="shiftId" label="Shift" :live="true"
                :options="[['value' => '', 'label' => 'All'], ...collect($shifts)->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all()]" />
        </div>
        <div>
            <x-clean-select model="employeeId" label="Employee" :live="true"
                :options="[['value' => '', 'label' => 'All'], ...collect($employees)->map(fn ($e) => ['value' => $e->id, 'label' => $e->user->name])->all()]" />
        </div>
        <div>
            <x-clean-select model="mode" label="Mode" :live="true"
                :options="[
                    ['value' => '', 'label' => 'All'],
                    ['value' => 'office', 'label' => 'Office'],
                    ['value' => 'wfh', 'label' => 'WFH'],
                    ['value' => 'hybrid', 'label' => 'Hybrid'],
                ]" />
        </div>
    </div>
</div>

{{-- ═══════════════ SUMMARY ═══════════════ --}}
@if(! empty($report['summary']))
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach($report['summary'] as $s)
            <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                <div class="text-lg font-black tabular-nums text-orange-600">{{ $s['value'] }}</div>
                <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $s['label'] }}</div>
            </div>
        @endforeach
    </div>
@endif

{{-- ═══════════════ TREND CHART ═══════════════ --}}
@if($trendChart)
    <div class="mb-4 rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Trend</div>
        <x-dashboard.chart :options="$trendChart" id="report-trend" wire:key="trend-{{ $type }}-{{ $from }}-{{ $to }}" class="-mb-2" />
    </div>
@endif

{{-- ═══════════════ PREVIEW TABLE ═══════════════ --}}
<div class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3">
        <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white">
            <flux:icon.document-text class="size-4 text-orange-500" /> {{ $report['title'] }}
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ $report['subtitle'] }}</span>
        </h3>
        <span class="text-xs text-zinc-400">
            {{ $totalRows }} {{ Str::plural('record', $totalRows) }}
            @if($totalRows > count($previewRows)) · showing first {{ count($previewRows) }} (export for all) @endif
        </span>
    </div>
    <div class="overflow-x-auto" wire:loading.class="opacity-50" wire:target="type,from,to,departmentId,officeId,shiftId,employeeId,mode">
        @if(count($previewRows) > 0)
            <table class="w-full text-left text-xs">
                <thead class="border-b border-orange-100/70 bg-orange-50/40">
                    <tr class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">
                        @foreach($report['columns'] as $col)<th class="whitespace-nowrap px-4 py-2.5">{{ $col }}</th>@endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50">
                    @foreach($previewRows as $row)
                        <tr class="transition hover:bg-orange-50/40">
                            @foreach($row as $cell)<td class="whitespace-nowrap px-4 py-2 text-zinc-700 dark:text-zinc-200">{{ $cell }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-14 text-center text-sm text-zinc-400"><flux:icon.document-magnifying-glass class="mx-auto mb-2 size-9 opacity-30" /> No records for the selected filters.</div>
        @endif
    </div>
</div>

</flux:main>
