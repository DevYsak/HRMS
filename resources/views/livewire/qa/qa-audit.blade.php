@php
    $levelTone = match ($summary['level']) {
        'production' => 'emerald', 'staging', 'uat' => 'sky', 'internal_qa' => 'amber', default => 'rose',
    };
    $soft = [
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20',
        'zinc' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    ];
    $inputClass = 'h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
    $labelClass = 'mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500';
    $statusOptions = match ($tab) {
        'cases' => collect(\App\Enums\QaTestStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
        'findings', 'ui' => collect(\App\Enums\QaFindingStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]),
        default => null,
    };
    $filterable = in_array($tab, ['cases', 'findings', 'ui', 'fixes', 'runs'], true);
@endphp

<flux:main class="bg-zinc-50 p-4 sm:p-6 dark:bg-zinc-950">
<div class="mx-auto w-full max-w-[1400px] min-w-0 space-y-5" data-qa-audit>

    {{-- ── Header ── --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <nav class="flex items-center gap-1.5 text-xs font-medium text-zinc-500" aria-label="Breadcrumb">
                <span>System</span>
                <flux:icon.chevron-right class="size-3 shrink-0" />
                <span class="text-zinc-800 dark:text-zinc-200">QA &amp; Audit</span>
            </nav>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">QA &amp; Audit</h1>
            <p class="mt-1 max-w-2xl text-sm text-zinc-500 dark:text-zinc-400">What has been tested, what failed, which commit fixed it, and how ready the portal is. Records QA only — it never changes employee, attendance, leave, payroll or permission data.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold tracking-wide ring-1 {{ $soft[$levelTone] }}" data-readiness-level>
                <flux:icon.shield-check class="size-4" /> {{ $summary['level_label'] }}
            </span>
            @if($canExport)
                <flux:button size="sm" variant="ghost" icon="printer" :href="route('qa-audit.report', $filterQuery)" target="_blank">Report</flux:button>
                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('qa-audit.report', [...$filterQuery, 'format' => 'pdf'])">PDF</flux:button>
            @endif
            @if($canRun)
                <flux:button size="sm" variant="ghost" icon="play-circle" wire:click="newRun">Record run</flux:button>
            @endif
            @if($canManage)
                <flux:button size="sm" variant="ghost" icon="bug-ant" wire:click="newFinding">New finding</flux:button>
                <flux:button size="sm" variant="primary" icon="plus" wire:click="newCase">New test case</flux:button>
            @endif
        </div>
    </div>

    {{-- ── Tabs ── --}}
    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="QA sections">
        <div class="inline-flex min-w-full gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10 sm:min-w-0">
            @foreach(\App\Livewire\Qa\QaAudit::TABS as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}"
                    @class([
                        'whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition',
                        'bg-orange-500 text-white shadow-sm' => $tab === $key,
                        'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => $tab !== $key,
                    ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- ── Search & filters ── --}}
    @if($filterable)
        <div class="ui-surface grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-6" data-qa-filters x-data="{ more: {{ $hasFilters ? 'true' : 'false' }} }">
            <label class="min-w-0 sm:col-span-2">
                <span class="{{ $labelClass }}">Search</span>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Test ID, finding ID, commit, module, title, route" class="{{ $inputClass }}" aria-label="Search">
            </label>
            {{-- Phones: the other filters fold away behind one button. --}}
            <button type="button" x-on:click="more = ! more" class="flex items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 sm:hidden dark:border-white/10 dark:text-zinc-200" :aria-expanded="more">
                <span class="flex items-center gap-1.5"><flux:icon.adjustments-horizontal class="size-4" /> Filters @if($hasFilters)<span class="rounded-full bg-orange-500 px-1.5 text-[10px] font-bold text-white">{{ count($filterQuery) }}</span>@endif</span>
                <flux:icon.chevron-down class="size-4 transition" x-bind:class="more && 'rotate-180'" />
            </button>
            <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                <span class="{{ $labelClass }}">Phase</span>
                <select wire:model.live="phase" class="{{ $inputClass }}">
                    <option value="">All phases</option>
                    @foreach($phases as $p)
                        <option value="{{ $p->id }}">{{ $p->number }} · {{ $p->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                <span class="{{ $labelClass }}">Module</span>
                <select wire:model.live="module" class="{{ $inputClass }}">
                    <option value="">All modules</option>
                    @foreach($modules as $m)
                        <option value="{{ $m }}">{{ $m }}</option>
                    @endforeach
                </select>
            </label>
            @if($statusOptions)
                <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                    <span class="{{ $labelClass }}">Status</span>
                    <select wire:model.live="status" class="{{ $inputClass }}">
                        <option value="">All statuses</option>
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                    <span class="{{ $labelClass }}">Severity</span>
                    <select wire:model.live="severity" class="{{ $inputClass }}">
                        <option value="">All severities</option>
                        @foreach(\App\Enums\QaSeverity::cases() as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                <span class="{{ $labelClass }}">Role</span>
                <input type="text" wire:model.live.debounce.400ms="role" placeholder="e.g. Manager" class="{{ $inputClass }}">
            </label>
            <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                <span class="{{ $labelClass }}">Environment</span>
                <select wire:model.live="environment" class="{{ $inputClass }}">
                    <option value="">All environments</option>
                    @foreach(\App\Enums\QaEnvironment::cases() as $e)
                        <option value="{{ $e->value }}">{{ $e->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                <span class="{{ $labelClass }}">Commit</span>
                <input type="text" wire:model.live.debounce.400ms="commitRef" placeholder="a90dc23" class="{{ $inputClass }} font-mono">
            </label>
            <div class="grid min-w-0 grid-cols-2 gap-2 sm:col-span-2 lg:col-span-2" x-bind:class="more ? '' : 'max-sm:hidden'">
                <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                    <span class="{{ $labelClass }}">From</span>
                    <input type="date" wire:model.live="from" class="{{ $inputClass }} px-2" aria-label="From date">
                </label>
                <label class="min-w-0" x-bind:class="more ? '' : 'max-sm:hidden'">
                    <span class="{{ $labelClass }}">To</span>
                    <input type="date" wire:model.live="to" class="{{ $inputClass }} px-2" aria-label="To date">
                </label>
            </div>
            <div class="flex items-end gap-2" x-bind:class="more ? '' : 'max-sm:hidden'">
                @if($hasFilters)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Clear</flux:button>
                @endif
                @if($canExport && in_array($tab, ['cases', 'findings', 'fixes', 'runs'], true))
                    <flux:button size="sm" variant="ghost" icon="table-cells" :href="route('qa-audit.export', ['dataset' => $tab, ...$filterQuery])">CSV</flux:button>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Tab content ── --}}
    <div wire:loading.class="opacity-60" class="transition-opacity">
        @include('livewire.qa.tabs.'.$tab)
    </div>

    @include('livewire.qa.partials.case-flyout')
    @include('livewire.qa.partials.finding-flyout')
    @include('livewire.qa.partials.forms')
</div>
</flux:main>
