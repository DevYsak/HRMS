@php
    $company = \App\Models\Company::first();
    $dd = app(\App\Support\DateDisplay::class);
    $num = fn ($v) => \App\Services\People\Employee360Service::number((float) $v);
    $hm = fn (int $m) => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee 360° — {{ $identity['name'] }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; padding: 22px 26px; }
        h1 { font-size: 19px; margin: 0; color: #111827; }
        h2 { font-size: 12px; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #f97316; color: #111827; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: #6b7280; }
        .brand { color: #ea580c; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #fff7ed; text-align: left; padding: 5px 7px; font-size: 9px; text-transform: uppercase; color: #9a3412; }
        td { padding: 5px 7px; border-bottom: 1px solid #f1f1f1; vertical-align: top; }
        .grid td { border: none; padding: 3px 10px 3px 0; }
        .kpi td { border: 1px solid #fde4cf; text-align: center; padding: 8px 4px; }
        .kpi .v { font-size: 15px; font-weight: bold; color: #111827; }
        .kpi .l { font-size: 8.5px; text-transform: uppercase; color: #6b7280; }
        .footer { margin-top: 26px; border-top: 1px solid #e5e7eb; padding-top: 6px; font-size: 8.5px; color: #9ca3af; }
    </style>
</head>
<body>
    <table class="grid">
        <tr>
            <td>
                <div class="brand">{{ $company->name ?? config('app.name') }} · Employee 360° report</div>
                <h1>{{ $identity['name'] }}</h1>
                <div class="muted">{{ $identity['designation'] ?? 'No designation' }} · {{ $identity['department'] ?? 'No department' }} · {{ $identity['status'] }}</div>
            </td>
            <td style="text-align: right;" class="muted">
                {{ $dd->date($from) }} – {{ $dd->date($to) }}<br>
                {{ $scopeLabel }}
            </td>
        </tr>
    </table>

    <table class="grid" style="margin-top: 10px;">
        <tr>
            <td><span class="muted">Employee ID</span><br>{{ $identity['code'] ?: '—' }}</td>
            <td><span class="muted">Joined</span><br>{{ $identity['joining_date'] ? $dd->date($identity['joining_date']) : '—' }}</td>
            <td><span class="muted">Manager</span><br>{{ $identity['manager'] ?? '—' }}</td>
            <td><span class="muted">Shift</span><br>{{ $identity['shift'] ?? '—' }}</td>
            <td><span class="muted">Office</span><br>{{ $identity['office'] ?? '—' }}</td>
            <td><span class="muted">Work mode</span><br>{{ $identity['work_mode'] ?? '—' }}</td>
        </tr>
    </table>

    @if($kpis !== [])
        <table class="kpi" style="margin-top: 14px;">
            <tr>
                @foreach($kpis as $kpi)
                    <td><div class="v">{{ $kpi['value'] }}</div><div class="l">{{ $kpi['label'] }}</div></td>
                @endforeach
            </tr>
        </table>
    @endif

    @if($attendance)
        <h2>Attendance</h2>
        <p>
            Present {{ $attendance['summary']['present'] }} · Late {{ $attendance['summary']['late'] }} · Absent {{ $attendance['summary']['absent'] }}
            · Leave {{ $attendance['counts']['leave'] }} · Weekly off {{ $attendance['counts']['weekly_off'] }} · Holiday {{ $attendance['counts']['holiday'] }}
            · Worked {{ $hm((int) $attendance['summary']['minutes']) }}@if($attendance['rate'] !== null) · Attendance rate {{ $attendance['rate'] }}%@endif
            · Missing checkouts {{ $attendance['missing_checkouts'] }}
        </p>
        @if($attendance['punches'] !== [])
            <table>
                <thead><tr><th>Time</th><th>In / Out</th><th>Source</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($attendance['punches'] as $punch)
                        <tr><td>{{ $dd->shortDate($punch['at']) }} {{ $dd->time($punch['at']) }}</td><td>{{ strtoupper($punch['direction'] ?? 'scan') }}</td><td>{{ $punch['source'] }}</td><td>{{ $punch['status'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if($leave)
        <h2>Leave · {{ $leave['year']->label }}</h2>
        <p>Available {{ $num($leave['available']) }} · Taken this year {{ $num($leave['taken_this_year']) }} · Pending requests {{ $leave['pending_requests'] }}</p>
        @if($leave['types'] !== [])
            <table>
                <thead><tr><th>Leave type</th><th>Used</th><th>Available</th><th>Pending</th></tr></thead>
                <tbody>
                    @foreach($leave['types'] as $type)
                        <tr><td>{{ $type['name'] }}</td><td>{{ $num($type['used']) }}</td><td>{{ $num($type['available']) }}</td><td>{{ $num($type['pending']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if($leave['requests'] !== [])
            <table style="margin-top: 8px;">
                <thead><tr><th>Dates</th><th>Type</th><th>Days</th><th>Status</th><th>Approver</th></tr></thead>
                <tbody>
                    @foreach($leave['requests'] as $request)
                        <tr><td>{{ $dd->shortDate($request['start']) }} – {{ $dd->shortDate($request['end']) }}</td><td>{{ $request['type'] }}</td><td>{{ $num($request['days']) }}</td><td>{{ str_replace('_', ' ', $request['status']) }}</td><td>{{ $request['approver'] ?? '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if($overtime)
        <h2>Overtime</h2>
        <p>
            Pending requests {{ $overtime['pending'] }} · Approved requests {{ $overtime['approved'] }}
            · Payable OT {{ $num($overtime['payable_hours']) }}h · Approved requested OT {{ $num($overtime['approved_requested_hours']) }}h · Actual worked OT {{ $num($overtime['actual_worked_hours'] ?? 0) }}h
            @if($overtime['payable']) · Payable, not yet paid {{ $num($overtime['payable']['hours']) }}h / {{ number_format($overtime['payable']['amount'], 2) }}@endif
        </p>
    @endif

    @if($performance)
        <h2>Performance · {{ $performance['cycle']?->name ?? 'No active cycle' }}</h2>
        <p>
            Status {{ $performance['label'] }} · Score {{ $performance['score'] !== null ? $num($performance['score']) : '—' }}
            · Self review {{ $performance['self'] }} · Manager review {{ $performance['manager'] }}
            · Goals {{ $performance['goals']['completed'] }}/{{ $performance['goals']['total'] }}
            · KPI progress {{ $performance['kpis']['progress'] !== null ? $performance['kpis']['progress'].'%' : '—' }}
        </p>
    @endif

    @if($warnings)
        <h2>Warnings &amp; conduct</h2>
        <p>Active warnings {{ $warnings['active'] }} · On record {{ $warnings['total'] }}</p>
        @if($warnings['timeline'] !== [])
            <table>
                <thead><tr><th>Date</th><th>Type</th><th>Reason</th></tr></thead>
                <tbody>
                    @foreach($warnings['timeline'] as $letter)
                        <tr><td>{{ $dd->date($letter->issue_date) }}</td><td>{{ \Illuminate\Support\Str::headline((string) $letter->warning_type) }}</td><td>{{ \Illuminate\Support\Str::limit((string) $letter->reason, 120) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if($activity && $activity['entries'] !== [])
        <h2>Recent activity</h2>
        <table>
            <thead><tr><th>Date &amp; time</th><th>Action</th><th>Details</th><th>By</th></tr></thead>
            <tbody>
                @foreach($activity['entries'] as $entry)
                    <tr><td>{{ $dd->dateTime($entry['at']) }}</td><td>{{ $entry['action'] }}</td><td>{{ $entry['details'] }}</td><td>{{ $entry['by'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Generated {{ $dd->dateTime(now()) }} by {{ $generatedBy }}. Only the sections the requester may view are included. Confidential — for authorised HR use.
    </div>
</body>
</html>
