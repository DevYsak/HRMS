<flux:main>
    <div class="mx-auto w-full max-w-6xl space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <h1 class="pulse-page-title">KPI Management</h1>
                <p class="pulse-page-subtitle">KPIs for the cycle, who carries them and how far they are.</p>
                <x-pulse.scope-badge :scope="$scope" class="mt-2" />
            </div>
            @if($canConfigure && \Illuminate\Support\Facades\Route::has('performance.kpi-templates'))
                <flux:button variant="primary" icon="adjustments-horizontal" :href="route('performance.kpi-templates')" wire:navigate>Configure KPIs</flux:button>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:max-w-2xl" data-kpi-filters>
            <flux:select wire:model.live="cycleId" label="Cycle">
                @foreach($cycles as $c)
                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->status }})</option>
                @endforeach
            </flux:select>
            @if($lockedDepartment)
                <flux:input label="Department" :value="$lockedDepartment->name" disabled />
            @else
                <flux:select wire:model.live="departmentId" label="Department">
                    <option value="">All departments in scope</option>
                    @foreach($departments as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        @foreach($warnings as $warning)
            <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300" data-kpi-warning>
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" /> <span>{{ $warning }}</span>
            </div>
        @endforeach

        <x-pulse.grid :cols="4">
            <x-pulse.kpi-card label="Employees" :value="$reviews['total']" icon="users" sub="in scope" />
            <x-pulse.kpi-card label="Reviews pending" :value="$reviews['pending']" icon="clock" accent="amber" :sub="$reviews['self_review_pending'].' self · '.$reviews['manager_review_pending'].' manager'" />
            <x-pulse.kpi-card label="Completed" :value="$reviews['completed']" icon="check-circle" accent="emerald" sub="locked reviews" />
            <x-pulse.kpi-card label="Overdue" :value="$reviews['overdue']" icon="exclamation-triangle" accent="rose" sub="past stage deadline" />
        </x-pulse.grid>

        <x-pulse.card title="KPIs" :subtitle="$cycle?->name" icon="chart-bar" flush>
            <x-pulse.table :columns="['KPI', ['label' => 'Department', 'class' => 'hidden md:table-cell'], 'Weight', 'Active Employees', 'Completed', 'Pending']" min="min-w-0">
                @forelse($rows as $row)
                    <tr>
                        <td class="px-3 py-2.5 pl-5"><p class="font-medium text-zinc-900 dark:text-white">{{ $row['kpi'] }}</p>@if($row['category'])<p class="text-[11px] text-zinc-400">{{ $row['category'] }}</p>@endif</td>
                        <td class="hidden px-3 py-2.5 text-xs text-zinc-500 md:table-cell">{{ $row['department'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ rtrim(rtrim(number_format($row['weight'], 2), '0'), '.') }}%</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['employees'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['completed'] }}</td>
                        <td class="px-3 py-2.5 pr-5 tabular-nums">{{ $row['pending'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-zinc-500">No KPI data for this cycle and scope.</td></tr>
                @endforelse
            </x-pulse.table>
        </x-pulse.card>
    </div>
</flux:main>
