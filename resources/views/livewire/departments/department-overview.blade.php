@php
    $user = auth()->user();
    $access = app(\App\Services\Help\RouteAccess::class);
    $link = fn (string $route, array $params = []): ?string => \Illuminate\Support\Facades\Route::has($route) && $access->allows($user, $route) ? route($route, $params) : null;
    $hints = \App\Services\Attendance\TodayStatusService::HINTS;
    $bucketTone = ['present' => 'emerald', 'no_checkin' => 'amber', 'absent' => 'rose', 'on_leave' => 'blue', 'weekly_off' => 'zinc', 'holiday' => 'violet', 'not_employed' => 'zinc'];
    $bucketLabel = \App\Services\Attendance\TodayStatusService::LABELS + ['not_employed' => 'Not employed'];
    $approvalLabels = collect(\App\Services\Approvals\ApprovalQueueService::TYPES)->map(fn ($t) => $t['label'])->all();
@endphp

<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">

        <x-pulse.dashboard-header :title="$department->name" :subtitle="($head ? 'Department Head: '.$head->name : 'No department head set').' · '.$employees->count().' '.\Illuminate\Support\Str::plural('employee', $employees->count())" :show-badge="false">
            <x-slot:actions>
                @if($link('dashboard.hr-admin'))
                    <a href="{{ $link('dashboard.hr-admin') }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:text-brand-700">← HR Operations</a>
                @endif
            </x-slot:actions>
        </x-pulse.dashboard-header>
        <x-pulse.scope-badge :scope="$scope" />

        {{-- Tabs --}}
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="flex min-w-max gap-1 border-b border-zinc-200 dark:border-white/10" role="tablist">
                @foreach(\App\Livewire\Departments\DepartmentOverview::TABS as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"
                        @class(['relative whitespace-nowrap px-4 py-2.5 text-sm font-semibold transition-colors', 'text-orange-600 dark:text-orange-400' => $tab === $key, 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $key])>
                        {{ $label }}
                        @if($tab === $key)<span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500"></span>@endif
                    </button>
                @endforeach
            </div>
        </div>

        @if($tab === 'overview')
            <p class="text-[11px] font-black uppercase tracking-widest text-zinc-400">Today</p>
            <x-pulse.grid :cols="4">
                <x-pulse.kpi-card label="Present Today" :value="$today['present']" icon="check-circle" accent="emerald" :sub="'of '.$today['expected'].' due today'" title="{{ $hints['present'] }}" />
                <x-pulse.kpi-card label="Late" :value="$today['late']" icon="clock" accent="amber" sub="after shift start + grace" />
                <x-pulse.kpi-card :label="$today['day_off'] ? 'Weekly Off' : 'No Check-in'" :value="$today['day_off'] ? $today['weekly_off'] + $today['holiday'] : $today['no_checkin'] + $today['absent']" icon="x-circle" accent="rose"
                    :sub="$today['day_off'] ? 'not due today — not absent' : $today['absent'].' absent (shift over) · '.$today['missing_checkout'].' missing checkout'" />
                <x-pulse.kpi-card label="On Leave" :value="$today['on_leave']" icon="calendar-days" accent="blue" sub="approved leave today" />
            </x-pulse.grid>

            <x-pulse.grid :cols="3" class="items-start">
                <x-pulse.card title="Approvals" subtitle="Waiting for your decision" icon="inbox-stack">
                    @forelse($approvals as $type => $count)
                        <div class="flex items-center justify-between border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-white/5" data-dept-approval="{{ $type }}">
                            <span class="text-zinc-700 dark:text-zinc-200">{{ $approvalLabels[$type] }}</span>
                            <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $count }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">You do not approve requests for this department.</p>
                    @endforelse
                    @if($link('attendance.command-center'))
                        <a href="{{ $link('attendance.command-center') }}" wire:navigate class="mt-3 inline-block text-xs font-semibold text-brand-600">Open Command Center →</a>
                    @endif
                </x-pulse.card>

                <x-pulse.card title="Performance" :subtitle="$reviews['cycle']?->name ?? 'No cycle running'" icon="star">
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-zinc-500">Reviews due</dt><dd class="text-xl font-bold tabular-nums">{{ $reviews['pending'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Completed</dt><dd class="text-xl font-bold tabular-nums">{{ $reviews['completed'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Average score</dt><dd class="text-xl font-bold tabular-nums">{{ $reviews['avg_score'] ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Overdue</dt><dd @class(['text-xl font-bold tabular-nums', 'text-rose-600' => $reviews['overdue'] > 0])>{{ $reviews['overdue'] }}</dd></div>
                    </dl>
                </x-pulse.card>

                <x-pulse.card title="People" icon="users">
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-zinc-500">Active</dt><dd class="text-xl font-bold tabular-nums">{{ $people['active'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Probation</dt><dd class="text-xl font-bold tabular-nums">{{ $people['probation'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Notice period</dt><dd class="text-xl font-bold tabular-nums">{{ $people['notice_period'] }}</dd></div>
                        <div><dt class="text-xs text-zinc-500">Approved OT (month)</dt><dd class="text-xl font-bold tabular-nums">{{ $otHours }}h</dd></div>
                    </dl>
                </x-pulse.card>
            </x-pulse.grid>
        @endif

        @if($tab === 'people')
            <x-pulse.card title="People" :subtitle="$employees->count().' working'" icon="users" flush>
                <x-pulse.table :columns="['Employee', 'Designation', ['label' => 'Manager', 'class' => 'hidden md:table-cell'], ['label' => 'Shift', 'class' => 'hidden lg:table-cell'], 'Status']" min="min-w-0">
                    @foreach($employees as $e)
                        <tr wire:key="dp-{{ $e->id }}">
                            <td class="px-3 py-2.5 pl-5 font-medium text-zinc-900 dark:text-white">{{ $e->user?->name }}</td>
                            <td class="px-3 py-2.5 text-xs text-zinc-500">{{ $e->jobTitle?->name ?? '—' }}</td>
                            <td class="hidden px-3 py-2.5 text-xs text-zinc-500 md:table-cell">{{ $e->manager?->name ?? '—' }}</td>
                            <td class="hidden px-3 py-2.5 text-xs text-zinc-500 lg:table-cell">{{ $e->shift?->name ?? 'Company default' }}</td>
                            <td class="px-3 py-2.5 pr-5"><x-pulse.badge>{{ str_replace(['_', '-'], ' ', is_object($e->status) ? $e->status->value : $e->status) }}</x-pulse.badge></td>
                        </tr>
                    @endforeach
                </x-pulse.table>
            </x-pulse.card>
        @endif

        @if($tab === 'attendance')
            <x-pulse.card title="Attendance — Today" icon="clock" flush>
                <x-pulse.table :columns="['Employee', 'Status', 'First in', 'Last out', ['label' => 'Late', 'class' => 'hidden md:table-cell']]" min="min-w-0">
                    @foreach($employees as $e)
                        @php $row = $today['rows'][$e->id] ?? null; @endphp
                        <tr wire:key="da-{{ $e->id }}">
                            <td class="px-3 py-2.5 pl-5 font-medium text-zinc-900 dark:text-white">{{ $e->user?->name }}</td>
                            <td class="px-3 py-2.5" title="{{ $hints[$row['bucket'] ?? ''] ?? '' }}">
                                @if($row)
                                    <x-pulse.badge :color="$row['state'] === 'missing_checkout' ? 'rose' : ($bucketTone[$row['bucket']] ?? 'zinc')">{{ $row['state'] === 'missing_checkout' ? 'Missing checkout' : ($bucketLabel[$row['bucket']] ?? $row['bucket']) }}</x-pulse.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-xs tabular-nums">{{ $row && $row['first_in'] ? $row['first_in']->userTime() : '—' }}</td>
                            <td class="px-3 py-2.5 text-xs tabular-nums">{{ $row && $row['last_out'] ? $row['last_out']->userTime() : '—' }}</td>
                            <td class="hidden px-3 py-2.5 pr-5 text-xs md:table-cell">{{ $row && $row['bucket'] === 'present' && $row['day']->isLate ? 'Late' : '' }}</td>
                        </tr>
                    @endforeach
                </x-pulse.table>
            </x-pulse.card>
        @endif

        @if($tab === 'leave')
            <x-pulse.grid :split="true" class="items-start">
                <x-pulse.card title="Pending my approval" icon="inbox-stack" flush>
                    <x-pulse.table :columns="['Employee', 'Type', 'Dates', 'Status']" min="min-w-0">
                        @forelse($pendingLeave as $r)
                            <tr><td class="px-3 py-2.5 pl-5">{{ $r->employee?->user?->name }}</td><td class="px-3 py-2.5 text-xs">{{ $r->leaveType?->name }}</td><td class="px-3 py-2.5 text-xs">@userShortDate($r->start_date) – @userDate($r->end_date)</td><td class="px-3 py-2.5 pr-5"><x-pulse.badge color="amber">{{ str_replace('_', ' ', $r->status) }}</x-pulse.badge></td></tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-6 text-center text-sm text-zinc-500">Nothing waiting for you.</td></tr>
                        @endforelse
                    </x-pulse.table>
                </x-pulse.card>
                <x-pulse.card title="On leave now and upcoming" icon="calendar-days" flush>
                    <x-pulse.table :columns="['Employee', 'Type', 'Dates']" min="min-w-0">
                        @forelse($onLeave as $r)
                            <tr><td class="px-3 py-2.5 pl-5">{{ $r->employee?->user?->name }}</td><td class="px-3 py-2.5 text-xs">{{ $r->leaveType?->name }}</td><td class="px-3 py-2.5 pr-5 text-xs">@userShortDate($r->start_date) – @userDate($r->end_date)</td></tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-6 text-center text-sm text-zinc-500">No approved leave ahead.</td></tr>
                        @endforelse
                    </x-pulse.table>
                </x-pulse.card>
            </x-pulse.grid>
        @endif

        @if($tab === 'overtime')
            <x-pulse.card :title="'Approved OT — '.now()->format('F Y')" subtitle="Payable hours from attendance + an approved request (not the requested estimate)" icon="bolt" flush>
                <x-pulse.table :columns="['Employee', 'Approved hours']" min="min-w-0">
                    @foreach($employees as $e)
                        <tr><td class="px-3 py-2.5 pl-5">{{ $e->user?->name }}</td><td class="px-3 py-2.5 pr-5 tabular-nums">{{ round((float) ($otByEmployee[$e->id] ?? 0), 2) }}h</td></tr>
                    @endforeach
                </x-pulse.table>
            </x-pulse.card>
        @endif

        @if($tab === 'performance')
            <x-pulse.card title="Review status" :subtitle="$reviews['cycle']?->name ?? 'No cycle running'" icon="star" flush>
                <x-pulse.table :columns="['Employee', 'Status']" min="min-w-0">
                    @foreach($employees as $e)
                        @php $state = $reviews['by_employee'][$e->id] ?? 'not_started'; @endphp
                        <tr><td class="px-3 py-2.5 pl-5">{{ $e->user?->name }}</td>
                            <td class="px-3 py-2.5 pr-5"><x-pulse.badge :color="match($state) { 'completed' => 'emerald', 'overdue' => 'rose', 'not_started' => 'zinc', default => 'amber' }">{{ \App\Services\Performance\PerformanceStatusService::LABELS[$state] }}</x-pulse.badge></td></tr>
                    @endforeach
                </x-pulse.table>
            </x-pulse.card>
        @endif

        @if($tab === 'reports')
            <x-pulse.card title="Reports" icon="document-chart-bar">
                <ul class="space-y-2 text-sm">
                    @if($link('attendance.reports'))
                        <li><a href="{{ route('attendance.reports', ['department' => $department->id]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Attendance reports for {{ $department->name }} →</a></li>
                    @endif
                    @if($link('time-off.employees'))
                        <li><a href="{{ route('time-off.employees') }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Leave requests →</a></li>
                    @endif
                    @if($link('overtime.manage'))
                        <li><a href="{{ route('overtime.manage') }}" wire:navigate class="font-semibold text-brand-600 hover:underline">Overtime requests →</a></li>
                    @endif
                </ul>
            </x-pulse.card>
        @endif
    </div>
</flux:main>
