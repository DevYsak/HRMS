@php
    $company = \App\Models\Company::first();
    $c = $summary['counts'];
    $s = $summary['scores'];
    $run = $summary['latest_run'];
    $env = fn (?string $v) => \App\Enums\QaEnvironment::tryFrom((string) $v)?->label() ?? ($v ?: '—');
    $openFindings = $findings->filter(fn ($f) => $f->status->isOpen());
    $knownIssues = $cases->where('status', \App\Enums\QaTestStatus::KnownBaseline);
    $uiFindings = $findings->where('kind', 'ui');
    $securityFindings = $findings->whereIn('kind', ['security', 'rbac']);
    $retested = $findings->filter(fn ($f) => filled($f->retest_result));
    $commits = $fixes->groupBy('commit_hash');
    $short = fn (?string $h) => $h ? substr($h, 0, 7) : '—';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NEXCORE HRMS — QA, Audit &amp; Readiness Report</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10px; color: #1f2937; margin: 0; padding: 22px 26px; background: #fff; }
        h1 { font-size: 20px; margin: 2px 0 0; color: #111827; }
        h2 { font-size: 12px; margin: 20px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #f97316; color: #111827; text-transform: uppercase; letter-spacing: .04em; page-break-after: avoid; }
        .muted { color: #6b7280; }
        .brand { color: #ea580c; font-weight: bold; letter-spacing: .03em; }
        .mono { font-family: 'DejaVu Sans Mono', Menlo, Consolas, monospace; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #fff7ed; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; color: #9a3412; }
        td { padding: 4px 6px; border-bottom: 1px solid #f1f1f1; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .grid td { border: none; padding: 3px 10px 3px 0; }
        .kpi td { border: 1px solid #fde4cf; text-align: center; padding: 7px 3px; }
        .kpi .v { font-size: 15px; font-weight: bold; color: #111827; }
        .kpi .l { font-size: 8px; text-transform: uppercase; color: #6b7280; }
        .pill { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 8.5px; font-weight: bold; }
        .ok { background: #d1fae5; color: #065f46; } .warn { background: #fef3c7; color: #92400e; } .bad { background: #ffe4e6; color: #9f1239; } .info { background: #ede9fe; color: #5b21b6; } .none { background: #f4f4f5; color: #71717a; }
        .level { font-size: 14px; font-weight: bold; padding: 6px 10px; border-radius: 6px; display: inline-block; }
        .bar { height: 6px; background: #f4f4f5; border-radius: 3px; } .bar div { height: 6px; border-radius: 3px; background: #f97316; }
        .footer { margin-top: 26px; border-top: 1px solid #e5e7eb; padding-top: 6px; font-size: 8px; color: #9ca3af; }
        .toolbar { position: sticky; top: 0; background: #fff; padding: 8px 0 12px; border-bottom: 1px solid #f4f4f5; margin-bottom: 12px; }
        .toolbar button, .toolbar a { font: inherit; font-size: 12px; padding: 6px 12px; border-radius: 6px; border: 1px solid #e5e7eb; background: #fff; color: #111827; text-decoration: none; cursor: pointer; }
        .scroll { overflow-x: auto; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
        @media (max-width: 640px) { body { padding: 14px 16px; } }
    </style>
</head>
<body>
    @if($printable)
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print / Save as PDF</button>
            <a href="{{ route('qa-audit.report', [...$filters, 'format' => 'pdf']) }}">Download PDF</a>
            <a href="{{ route('qa-audit.index') }}">Back to QA &amp; Audit</a>
        </div>
    @endif

    <div class="brand">NEXCORE HRMS</div>
    <h1>QA, Audit &amp; Readiness Report</h1>
    <div class="muted">@if($company?->name){{ $company->name }} · @endif Generated {{ $generatedAt->format('d M Y H:i') }} by {{ $generatedBy }}@if($filters !== []) · Filtered: {{ collect($filters)->map(fn ($v, $k) => $k.'='.$v)->implode(', ') }}@endif</div>

    {{-- 1. Executive summary + 18. Overall readiness score --}}
    <h2>Executive summary</h2>
    @php $levelClass = match ($summary['level']) { 'production' => 'ok', 'staging', 'uat' => 'info', 'internal_qa' => 'warn', default => 'bad' }; @endphp
    <p><span class="level {{ $levelClass }}">{{ $summary['level_label'] }}</span> &nbsp; Overall readiness score: <strong>{{ $s['production'] }}%</strong></p>
    <p>{{ $c['total'] }} test cases are tracked: {{ $c['passed'] }} passing, {{ $c['failed'] }} failing, {{ $c['partial'] }} partial, {{ $c['blocked'] }} blocked, {{ $c['not_tested'] }} not yet tested and {{ $c['known_baseline'] }} known baseline.
        {{ $c['open_findings'] }} finding(s) are open ({{ $c['open_critical'] }} critical, {{ $c['open_high'] }} high); {{ $c['fixed'] }} have been fixed.
        @if($run) The latest run, “{{ $run->name }}”, ran {{ $run->total }} tests: {{ $run->passed }} passed, {{ $run->known_baseline }} known baseline, {{ $run->new_regressions }} new regression(s) — {{ $run->resultLabel() }}.@endif</p>
    @if($summary['blockers'] !== [])
        <p><strong>Blockers:</strong> {{ implode(' ', $summary['blockers']) }}</p>
    @endif

    {{-- 2–3. Build & environment --}}
    <h2>Build tested &amp; environment</h2>
    <table class="grid">
        <tr>
            <td><span class="muted">Report built on commit</span><br><span class="mono">{{ $commit ?? '—' }}</span></td>
            <td><span class="muted">Latest run commit</span><br><span class="mono">{{ $short($run?->commit) }}</span></td>
            <td><span class="muted">Latest run environment</span><br>{{ $env($run?->environment) }}</td>
            <td><span class="muted">Latest run</span><br>{{ $run?->run_at->format('d M Y H:i') ?? '—' }}</td>
        </tr>
    </table>
    <p class="muted">Environments: Local, Local Test DB, E2E, Staging, Production (read-only). Destructive QA is never run in production.</p>

    {{-- 4. Test summary --}}
    <h2>Test summary</h2>
    <table class="kpi">
        <tr>
            @foreach([['Total', $c['total']], ['Passed', $c['passed']], ['Failed', $c['failed']], ['Partial', $c['partial']], ['Blocked', $c['blocked']], ['Known baseline', $c['known_baseline']], ['Not tested', $c['not_tested']], ['Fixed', $c['fixed']], ['Pending fix', $c['pending_fix']], ['Retest required', $c['retest_required']]] as [$l, $v])
                <td><div class="v">{{ $v }}</div><div class="l">{{ $l }}</div></td>
            @endforeach
        </tr>
    </table>
    <table style="margin-top: 8px;">
        <tr><th>Run</th><th>Suite</th><th>Environment</th><th>Commit</th><th>Total</th><th>Passed</th><th>Failed</th><th>Known baseline</th><th>New regressions</th><th>Result</th></tr>
        @forelse($runs as $r)
            <tr><td>{{ $r->name }}</td><td>{{ \App\Models\QaTestRun::SUITES[$r->suite] ?? $r->suite }}</td><td>{{ $env($r->environment) }}</td><td class="mono">{{ $short($r->commit) }}</td>
                <td>{{ $r->total }}</td><td>{{ $r->passed }}</td><td>{{ $r->failed }}</td><td>{{ $r->known_baseline }}</td><td>{{ $r->new_regressions }}</td><td>{{ $r->resultLabel() }}</td></tr>
        @empty
            <tr><td colspan="10" class="muted">No runs recorded.</td></tr>
        @endforelse
    </table>

    {{-- 5. Phase summary --}}
    <h2>Phase summary</h2>
    <table>
        <tr><th>#</th><th>Phase</th><th>Status</th><th>Owner</th><th>Started</th><th>Completed</th><th>Tests</th><th>Passed</th><th>Failed</th><th>Open findings</th><th>Commits</th></tr>
        @foreach($phaseRows as $p)
            <tr><td>{{ $p->number }}</td><td>{{ $p->name }}</td><td>{{ $p->status->label() }}</td><td>{{ $p->owner ?? '—' }}</td><td>{{ $p->started_at?->format('d M Y') ?? '—' }}</td><td>{{ $p->completed_at?->format('d M Y') ?? '—' }}</td>
                <td>{{ $p->test_cases_count }}</td><td>{{ $p->passed_count }}</td><td>{{ $p->failed_count }}</td><td>{{ $p->open_findings_count }}</td>
                <td class="mono">{{ $p->findings->flatMap->fixes->map->shortHash()->unique()->implode(' ') ?: '—' }}</td></tr>
        @endforeach
    </table>

    {{-- 6. Role matrix --}}
    <h2>Role access matrix</h2>
    <p class="muted">A = allowed (company), S = scoped (scope shown), E = explicit per-user grant only, — = denied. Live from Roles &amp; Permissions.</p>
    <div class="scroll">
    <table>
        <tr><th>Capability</th>@foreach($matrix['roles'] as $r)<th>{{ $r->name }}</th>@endforeach</tr>
        @foreach($matrix['groups'] as $group => $rows)
            <tr><td colspan="{{ $matrix['roles']->count() + 1 }}" style="background:#fafafa; font-weight:bold;">{{ $group }}</td></tr>
            @foreach($rows as $label => $row)
                <tr><td>{{ $label }}</td>
                    @foreach($matrix['roles'] as $r)
                        @php $m = $row['cells'][$r->slug]; @endphp
                        <td>{{ ['allowed' => 'A', 'scoped' => 'S', 'explicit' => 'E', 'denied' => '—'][$m['access']] }}@if($m['access'] === 'scoped') <span class="muted">{{ $m['scope'] }}</span>@endif</td>
                    @endforeach
                </tr>
            @endforeach
        @endforeach
    </table>
    </div>

    {{-- 7. Module matrix --}}
    <h2>Module readiness matrix — {{ $s['modules'] }}%</h2>
    <div class="scroll">
    <table>
        <tr><th>Module</th>@foreach(\App\Services\Qa\QaReadiness::MODULE_DIMENSIONS as $label)<th>{{ $label }}</th>@endforeach</tr>
        @foreach(\App\Services\Qa\QaReadiness::MODULES as $module)
            <tr><td>{{ $module }}</td>
                @foreach(array_keys(\App\Services\Qa\QaReadiness::MODULE_DIMENSIONS) as $dim)
                    @php $state = $moduleCells[$module.'|'.$dim]->status ?? 'not_tested'; @endphp
                    <td><span class="pill {{ ['pass' => 'ok', 'partial' => 'warn', 'fail' => 'bad'][$state] ?? 'none' }}">{{ \App\Models\QaChecklistItem::STATUSES[$state] }}</span></td>
                @endforeach
            </tr>
        @endforeach
    </table>
    </div>

    {{-- 8. Findings --}}
    <h2>Findings ({{ $findings->count() }})</h2>
    <table>
        <tr><th>ID</th><th>Severity</th><th>Type</th><th>Module</th><th>Title</th><th>Status</th><th>Role / scope</th><th>Fix</th></tr>
        @forelse($findings as $f)
            <tr><td class="mono">{{ $f->code }}</td><td>{{ $f->severity->label() }}</td><td>{{ \App\Models\QaFinding::KINDS[$f->kind] ?? $f->kind }}</td><td>{{ $f->module }}</td><td>{{ $f->title }}</td>
                <td>{{ $f->status->label() }}</td><td>{{ $f->role_affected ?? '—' }}{{ $f->scope_affected ? ' · '.$f->scope_affected : '' }}</td><td class="mono">{{ $f->fixes->map->shortHash()->unique()->implode(' ') ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="8" class="muted">No findings.</td></tr>
        @endforelse
    </table>

    {{-- 9–10. Fixes & commits --}}
    <h2>Fixes &amp; commits ({{ $commits->count() }} commits)</h2>
    <table>
        <tr><th>Commit</th><th>Message</th><th>Findings</th><th>Tests</th><th>Files</th><th>Date</th></tr>
        @forelse($commits as $hash => $links)
            <tr><td class="mono">{{ $short($hash) }}</td><td>{{ $links->first()->commit_message }}</td><td class="mono">{{ $links->map(fn ($l) => $l->finding?->code)->filter()->implode(' ') }}</td>
                <td class="mono">{{ $links->flatMap(fn ($l) => $l->test_case_codes ?? [])->unique()->implode(' ') }}</td><td>{{ $links->flatMap(fn ($l) => $l->files_changed ?? [])->unique()->count() }}</td><td>{{ $links->first()->fixed_at?->format('d M Y') }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">No fixes linked.</td></tr>
        @endforelse
    </table>

    {{-- 11. Retest results --}}
    <h2>Retest results</h2>
    <table>
        <tr><th>Finding</th><th>Status</th><th>Retest result</th><th>Closed</th></tr>
        @forelse($retested as $f)
            <tr><td class="mono">{{ $f->code }}</td><td>{{ $f->status->label() }}</td><td>{{ $f->retest_result }}</td><td>{{ $f->closed_at?->format('d M Y') ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">No retests recorded.</td></tr>
        @endforelse
    </table>

    {{-- 12. Known issues --}}
    <h2>Known issues</h2>
    <table>
        <tr><th>ID</th><th>Title</th><th>Detail</th></tr>
        @foreach($knownIssues as $k)
            <tr><td class="mono">{{ $k->code }}</td><td>{{ $k->title }}</td><td>Known baseline — reported separately, never counted as a regression. {{ $k->automated_reference }}</td></tr>
        @endforeach
        @foreach($openFindings->whereIn('severity', [\App\Enums\QaSeverity::Low, \App\Enums\QaSeverity::Info]) as $f)
            <tr><td class="mono">{{ $f->code }}</td><td>{{ $f->title }}</td><td>{{ $f->severity->label() }} · {{ $f->status->label() }}</td></tr>
        @endforeach
        @if($knownIssues->isEmpty() && $openFindings->isEmpty())
            <tr><td colspan="3" class="muted">None.</td></tr>
        @endif
    </table>

    {{-- 13. UI/UX findings --}}
    <h2>UI / UX findings — score {{ $s['ui'] }}%</h2>
    <table>
        <tr><th>ID</th><th>Page</th><th>Viewport · theme</th><th>Title</th><th>Severity</th><th>Status</th><th>Commit</th></tr>
        @forelse($uiFindings as $f)
            <tr><td class="mono">{{ $f->code }}</td><td>{{ $f->page ?? $f->route ?? '—' }}</td><td>{{ $f->viewport ?? '—' }} · {{ $f->theme ?? '—' }}</td><td>{{ $f->title }}</td><td>{{ $f->severity->label() }}</td><td>{{ $f->status->label() }}</td><td class="mono">{{ $f->fixes->map->shortHash()->unique()->implode(' ') ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="7" class="muted">No UI / UX findings.</td></tr>
        @endforelse
    </table>

    {{-- 14. Responsive --}}
    <h2>Responsive results</h2>
    <p>{{ $responsiveDone }} of {{ count(\App\Services\Qa\QaReadiness::RESPONSIVE_PAGES) * count(\App\Services\Qa\QaReadiness::VIEWPORTS) * 2 }} page × viewport × theme cells checked ({{ implode(', ', \App\Services\Qa\QaReadiness::VIEWPORTS) }} px; light and dark).
        @if($responsiveDone === 0) The responsive matrix has not been run yet (Phase 5). @endif</p>

    {{-- 15. Security --}}
    <h2>Security &amp; RBAC results — security {{ $s['security'] }}%, RBAC {{ $s['rbac'] }}%</h2>
    <table>
        <tr><th>ID</th><th>Type</th><th>Title</th><th>Severity</th><th>Status</th><th>Fix</th></tr>
        @forelse($securityFindings as $f)
            <tr><td class="mono">{{ $f->code }}</td><td>{{ \App\Models\QaFinding::KINDS[$f->kind] }}</td><td>{{ $f->title }}</td><td>{{ $f->severity->label() }}</td><td>{{ $f->status->label() }}</td><td class="mono">{{ $f->fixes->map->shortHash()->unique()->implode(' ') ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">No security or RBAC findings.</td></tr>
        @endforelse
    </table>

    {{-- 16. Demo readiness --}}
    <h2>Demo readiness — {{ $s['demo'] }}%</h2>
    <div class="scroll">
    <table>
        <tr><th>Role</th>@foreach(\App\Services\Qa\QaReadiness::DEMO_STEPS as $label)<th>{{ $label }}</th>@endforeach</tr>
        @foreach(\App\Services\Qa\QaReadiness::DEMO_ROLES as $slug => $roleName)
            <tr><td>{{ $roleName }}</td>
                @foreach(array_keys(\App\Services\Qa\QaReadiness::DEMO_STEPS) as $step)
                    @php $state = $demoCells[$slug.'|'.$step]->status ?? 'not_tested'; @endphp
                    <td><span class="pill {{ ['pass' => 'ok', 'partial' => 'warn', 'fail' => 'bad'][$state] ?? 'none' }}">{{ ['pass' => 'PASS', 'partial' => 'PART', 'fail' => 'FAIL'][$state] ?? '—' }}</span></td>
                @endforeach
            </tr>
        @endforeach
    </table>
    </div>

    {{-- 17. Remaining work --}}
    <h2>Remaining work</h2>
    <ul>
        @foreach($summary['next_steps'] as $step)<li>{{ $step }}</li>@endforeach
        @foreach($phaseRows->where('status', '!=', \App\Enums\QaPhaseStatus::Completed) as $p)<li>Phase {{ $p->number }} — {{ $p->name }} ({{ $p->status->label() }}).</li>@endforeach
        @foreach($openFindings->whereNotIn('severity', [\App\Enums\QaSeverity::Low, \App\Enums\QaSeverity::Info]) as $f)<li>{{ $f->code }} — {{ $f->title }} ({{ $f->severity->label() }}, {{ $f->status->label() }}).</li>@endforeach
    </ul>

    {{-- 18. Overall readiness score --}}
    <h2>Overall readiness score</h2>
    <table>
        @foreach([['Overall QA', 'overall'], ['Security', 'security'], ['RBAC', 'rbac'], ['UI / UX', 'ui'], ['Module readiness', 'modules'], ['Demo readiness', 'demo'], ['Production readiness', 'production']] as [$label, $key])
            <tr><td style="width: 30%;">{{ $label }}</td><td style="width: 55%;"><div class="bar"><div style="width: {{ $s[$key] }}%;"></div></div></td><td style="text-align: right;"><strong>{{ $s[$key] }}%</strong></td></tr>
        @endforeach
    </table>
    <p><span class="level {{ $levelClass }}">{{ $summary['level_label'] }}</span></p>

    <div class="footer">Internal — QA &amp; Audit tracker. Readiness is gated by blockers (failed migration, new regressions, open Critical / High security findings), not by PASS count alone. Confidential: do not share outside the project team.</div>
</body>
</html>
