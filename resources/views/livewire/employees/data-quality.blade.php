@php
    $sevTone = [
        'critical' => 'bg-rose-600 text-white',
        'high' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'warning' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'info' => 'bg-zinc-100 text-zinc-600 ring-1 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    ];
    $lineTone = ['VALID' => 'emerald', 'REVIEW REQUIRED' => 'amber', 'INVALID' => 'rose'];
    $inputClass = 'h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
    $labelClass = 'mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500';
    $th = 'px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500';
    $td = 'px-4 py-3 align-top';
    $cards = [
        ['Employees checked', $counts['employees'], 'users', 'brand'],
        ['Critical', $summary['critical'], 'no-symbol', 'rose'],
        ['High', $summary['high'], 'exclamation-triangle', 'rose'],
        ['Warnings', $summary['warning'], 'exclamation-circle', 'amber'],
        ['Info', $summary['info'], 'information-circle', 'blue'],
        ['HR corrections', $summary['data'], 'pencil-square', 'indigo'],
        ['Policy decisions', $summary['policy'], 'scale', 'emerald'],
    ];
    $tabs = $companyWide ? \App\Livewire\Employees\DataQuality::TABS : array_diff_key(\App\Livewire\Employees\DataQuality::TABS, ['roles' => true]);
@endphp

<flux:main class="bg-zinc-50 p-4 sm:p-6">
<div class="mx-auto w-full max-w-[1400px] min-w-0 space-y-5" data-data-quality>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <nav class="flex items-center gap-1.5 text-xs font-medium text-zinc-500" aria-label="Breadcrumb">
                <span>People</span><flux:icon.chevron-right class="size-3 shrink-0" /><span class="text-zinc-800 dark:text-zinc-200">Data Quality</span>
            </nav>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Data Quality</h1>
            <p class="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">Org structure and master-data checks. Read-only: nothing here changes a record — open the employee, department or role to correct it.</p>
        </div>
        @unless($companyWide)
            <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-orange-50 px-2.5 py-1 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20">
                <flux:icon.eye class="size-3.5" /> Your departments only
            </span>
        @endunless
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" data-dq-cards>
        @foreach($cards as [$label, $value, $icon, $accent])
            <x-pulse.kpi-card :label="$label" :value="(string) $value" :icon="$icon" :accent="$accent" />
        @endforeach
    </div>

    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" role="tablist">
        <div class="inline-flex min-w-full gap-1 rounded-xl bg-white p-1 ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-white/10 sm:min-w-0">
            @foreach($tabs as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class([
                    'whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-orange-500 text-white shadow-sm' => $tab === $key,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-white/5' => $tab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div wire:loading.class="opacity-60" class="transition-opacity">
    @if($tab === 'issues')
        <div class="ui-surface grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-6" data-dq-filters>
            <label class="min-w-0">
                <span class="{{ $labelClass }}">Employee</span>
                <input type="search" wire:model.live.debounce.400ms="employee" placeholder="Name or code" class="{{ $inputClass }}">
            </label>
            <label class="min-w-0">
                <span class="{{ $labelClass }}">Department</span>
                <select wire:model.live="department" class="{{ $inputClass }}">
                    <option value="">All departments</option>
                    @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-0 lg:col-span-2">
                <span class="{{ $labelClass }}">Issue type</span>
                <select wire:model.live="check" class="{{ $inputClass }}">
                    <option value="">All issue types</option>
                    @foreach($checks as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-0">
                <span class="{{ $labelClass }}">Severity</span>
                <select wire:model.live="severity" class="{{ $inputClass }}">
                    <option value="">All severities</option>
                    @foreach(\App\Services\DataQuality\DataQualityAuditor::SEVERITIES as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-0">
                <span class="{{ $labelClass }}">Employee status</span>
                <select wire:model.live="status" class="{{ $inputClass }}">
                    <option value="">Any</option>
                    @foreach(\App\Enums\EmployeeStatus::cases() as $s)
                        @if($s->isActive())<option value="{{ $s->value }}">{{ $s->label() }}</option>@endif
                    @endforeach
                </select>
            </label>
        </div>

        <div class="ui-surface mt-4 overflow-hidden" data-dq-issues>
            @forelse($issues as $issue)
                @php $links = $this->links($issue); @endphp
                <div wire:key="dq-{{ $loop->index }}-{{ $issue->check }}-{{ $issue->subjectId }}" class="flex flex-col gap-2 border-b border-zinc-100 px-4 py-3 last:border-0 sm:flex-row sm:items-start sm:gap-4 dark:border-white/5" data-dq-issue="{{ $issue->check }}">
                    <span class="w-fit shrink-0 rounded-md px-2 py-0.5 text-[11px] font-bold uppercase {{ $sevTone[$issue->severity] }}">{{ $issue->severity }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $issue->label() }}</p>
                        <p class="text-sm text-zinc-700 dark:text-zinc-200"><span class="font-medium">{{ $issue->subjectLabel }}</span>@if($issue->departmentName && $issue->subjectType !== 'department') · <span class="text-zinc-500">{{ $issue->departmentName }}</span>@endif — {{ $issue->message }}</p>
                        <p class="mt-0.5 text-xs text-zinc-500"><span class="font-semibold">{{ $issue->nature() === 'policy' ? 'Decision' : 'HR action' }}:</span> {{ $issue->action() }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-1.5">
                        @if($links['employee'])<flux:button size="xs" variant="ghost" icon="user" :href="$links['employee']" wire:navigate>Open employee</flux:button>@endif
                        @if($links['department'])<flux:button size="xs" variant="ghost" icon="building-office" :href="$links['department']" wire:navigate>Open department</flux:button>@endif
                        @if($links['role'])<flux:button size="xs" variant="ghost" icon="key" :href="$links['role']" wire:navigate>Open role</flux:button>@endif
                    </div>
                </div>
            @empty
                <div class="p-5"><x-pulse.empty-state icon="check-badge" title="No issues match" /></div>
            @endforelse
        </div>
    @elseif($tab === 'structure')
        <div class="ui-surface overflow-hidden" data-dq-structure>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5"><tr>
                        @foreach(['Employee', 'Department', 'Job title', 'Manager', 'Manager dept.', 'Dept. head', 'Shift', 'Employment type', 'Role', 'Scope', 'Line'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $r)
                            <tr>
                                <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $r['employee'] }}</td>
                                @foreach(['department', 'job_title', 'manager', 'manager_department', 'department_head', 'shift', 'employment_type', 'role', 'scope'] as $k)
                                    <td class="{{ $td }} {{ $r[$k] ? 'text-zinc-700 dark:text-zinc-200' : 'text-rose-500' }}">{{ $r[$k] ?? '—' }}</td>
                                @endforeach
                                <td class="{{ $td }}">@if($r['line'])<flux:badge size="sm" :color="$lineTone[$r['line']]">{{ $r['line'] }}</flux:badge>@else<span class="text-zinc-400">—</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500 dark:border-white/5">Line: VALID = same department, the department head, company-wide — or no manager because they are top-level (Super Admin, Director, HR Admin, Department Head / head of function); REVIEW REQUIRED = no manager while not top-level, or a cross-department manager who can approve; INVALID = the manager cannot act for them.</p>
        </div>
    @elseif($tab === 'departments')
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-dq-departments>
            @foreach($rows as $r)
                <div class="ui-surface space-y-2 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $r['department'] }}</h3>
                        <flux:badge size="sm" :color="$r['flags'] === [] ? 'emerald' : 'amber'">{{ $r['flags'] === [] ? 'OK' : count($r['flags']).' issue(s)' }}</flux:badge>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        <dt class="text-zinc-500">Head</dt><dd class="{{ $r['head'] ? 'text-zinc-800 dark:text-zinc-100' : 'text-rose-500' }}">{{ $r['head'] ?? 'None' }}</dd>
                        <dt class="text-zinc-500">Head role · scope</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $r['head_role'] ?? '—' }}{{ $r['head_scope'] ? ' · '.$r['head_scope'] : '' }}</dd>
                        <dt class="text-zinc-500">Active employees</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $r['employees'] }}</dd>
                        <dt class="text-zinc-500">Managers</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ implode(', ', $r['managers']) ?: '—' }}</dd>
                        <dt class="text-zinc-500">Teams</dt><dd class="text-zinc-800 dark:text-zinc-100">{{ $r['teams'] }}</dd>
                    </dl>
                    @if($r['flags'])
                        <ul class="space-y-0.5 text-xs text-amber-700 dark:text-amber-300">@foreach($r['flags'] as $f)<li>• {{ $f }}</li>@endforeach</ul>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif($tab === 'managers')
        <div class="ui-surface overflow-hidden" data-dq-managers>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5"><tr>
                        @foreach(['Manager', 'Role', 'Department', 'Direct reports', 'Teams led', 'Approval rights', 'Scope', 'Flags'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @forelse($rows as $r)
                            <tr>
                                <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $r['manager'] }}</td>
                                <td class="{{ $td }}">{{ $r['role'] ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $r['department'] ?? '—' }}</td>
                                <td class="{{ $td }} tabular-nums">{{ $r['direct_reports'] }}</td>
                                <td class="{{ $td }}">{{ implode(', ', $r['teams']) ?: '—' }}</td>
                                <td class="{{ $td }} text-xs">{{ $r['permissions'] ? implode(', ', array_map(fn ($p) => str_replace('approve_', '', $p), $r['permissions'])) : 'None' }}</td>
                                <td class="{{ $td }}">{{ $r['scope'] }}</td>
                                <td class="{{ $td }} text-xs {{ $r['flags'] ? 'text-amber-700 dark:text-amber-300' : 'text-emerald-600' }}">{{ $r['flags'] ? implode('; ', $r['flags']) : 'OK' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="p-5"><x-pulse.empty-state icon="users" title="No managers in reach" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif($tab === 'routing')
        <div class="ui-surface overflow-hidden" data-dq-routing>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1000px] text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5"><tr>
                        <th class="{{ $th }}">Employee</th><th class="{{ $th }}">First approver</th>
                        @foreach(array_keys(\App\Services\DataQuality\DataQualityAuditor::REQUEST_TYPES) as $type)<th class="{{ $th }}">{{ ucfirst($type) }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $r)
                            <tr>
                                <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $r['employee'] }}<p class="text-xs font-normal text-zinc-500">{{ $r['department'] ?? '—' }}</p></td>
                                <td class="{{ $td }}">{{ $r['first_approver'] ?? '—' }}</td>
                                @foreach($r['types'] as $t)
                                    <td class="{{ $td }} text-xs">
                                        @if($t['decider'] === null)
                                            <span class="font-semibold text-rose-600">Unresolved</span>
                                        @else
                                            <span class="{{ $t['first_can_decide'] ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300' }}">{{ $t['decider'] }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500 dark:border-white/5">Each cell names the first person in the chain (manager → team lead → department head → HR) who may decide that request. Amber: the first approver cannot, so it moves on.</p>
        </div>
    @elseif($tab === 'wfh')
        @php
            $flagged = array_values(array_filter($rows, fn ($r) => $r['status'] !== \App\Services\Attendance\WfhConsistencyService::VALID));
            $validDays = count($rows) - count($flagged);
            $wfhTone = ['unapproved_wfh' => 'amber', 'approved_not_wfh' => 'blue'];
        @endphp
        <div class="ui-surface overflow-hidden" data-dq-wfh>
            <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-zinc-100 px-4 py-3 text-xs text-zinc-600 dark:border-white/5 dark:text-zinc-300">
                <span>WFH without an approved request: <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" data-wfh-count="unapproved_wfh">{{ count(array_filter($flagged, fn ($r) => $r['status'] === 'unapproved_wfh')) }}</span></span>
                <span>Approved WFH, recorded another mode: <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" data-wfh-count="approved_not_wfh">{{ count(array_filter($flagged, fn ($r) => $r['status'] === 'approved_not_wfh')) }}</span></span>
                <span>Valid: <span class="font-semibold tabular-nums text-zinc-900 dark:text-white" data-wfh-count="valid">{{ $validDays }}</span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead class="bg-zinc-50 dark:bg-white/5"><tr>
                        @foreach(['Employee', 'Date', 'Recorded mode', 'WFH request', 'Result'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @forelse($flagged as $r)
                            <tr>
                                <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $r['employee'] }}<p class="text-xs font-normal text-zinc-500">{{ $r['department'] ?? '—' }}</p></td>
                                <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M Y') }}</td>
                                <td class="{{ $td }}">{{ $r['recorded_label'] }}</td>
                                <td class="{{ $td }} text-xs">{{ $r['request_label'] ?? ($r['pending_request_id'] ? 'Pending #'.$r['pending_request_id'].' (not approved)' : 'None') }}</td>
                                <td class="{{ $td }}"><x-pulse.badge :color="$wfhTone[$r['status']] ?? 'zinc'">{{ $r['label'] }}</x-pulse.badge></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-5"><x-pulse.empty-state icon="check-badge" title="No WFH mismatches" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500 dark:border-white/5">An approved WFH request is the plan; the attendance mode is what was recorded. Both are kept as they are — each row is for HR to review, nothing is corrected automatically. A pending request authorises nothing.</p>
        </div>
    @elseif($tab === 'roles')
        <div class="space-y-4" data-dq-roles>
            <div class="ui-surface overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead class="bg-zinc-50 dark:bg-white/5"><tr>
                            @foreach(['Role', 'Users', 'Expected', 'Actual', 'Missing', 'Extra', 'Reason'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                        </tr></thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($rows as $r)
                                <tr>
                                    <td class="{{ $td }} font-medium text-zinc-900 dark:text-white">{{ $r['role'] }}</td>
                                    <td class="{{ $td }} tabular-nums">{{ $r['users'] }}</td>
                                    <td class="{{ $td }} tabular-nums">{{ $r['expected'] }}</td>
                                    <td class="{{ $td }} tabular-nums">{{ $r['actual'] }}</td>
                                    <td class="{{ $td }} font-mono text-[11px] text-rose-600">{{ implode(', ', $r['missing']) ?: '—' }}</td>
                                    <td class="{{ $td }} font-mono text-[11px] text-amber-700 dark:text-amber-300">{{ implode(', ', $r['extra']) ?: '—' }}</td>
                                    <td class="{{ $td }} text-xs text-zinc-600 dark:text-zinc-300">{{ $r['reason'] ?? 'Matches the defaults.' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500 dark:border-white/5">Expected = the built-in defaults. Nothing is reset automatically; review in Roles &amp; Permissions.</p>
            </div>
            <x-pulse.card title="Per-user overrides" icon="user-plus" flush>
                @forelse($overrides as $o)
                    <div class="flex flex-wrap items-center gap-2 border-b border-zinc-100 px-5 py-2.5 text-sm last:border-0 dark:border-white/5">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $o['user'] }}</span>
                        <span class="text-zinc-500">{{ $o['role'] ?? 'No role' }}</span>
                        <flux:badge size="sm" :color="$o['effect'] === 'grant' ? 'sky' : 'rose'">{{ $o['effect'] }}</flux:badge>
                        <span class="font-mono text-xs">{{ $o['permission'] }}</span>
                        @if($o['scope'])<span class="text-xs text-zinc-500">({{ $o['scope'] }})</span>@endif
                        @if($o['role_has_it'] && $o['effect'] === 'grant')<span class="text-xs text-zinc-400">— the role already grants it</span>@endif
                    </div>
                @empty
                    <div class="p-5"><x-pulse.empty-state icon="check-badge" title="No per-user overrides" /></div>
                @endforelse
            </x-pulse.card>
        </div>
    @endif
    </div>
</div>
</flux:main>
