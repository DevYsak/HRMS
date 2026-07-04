<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['title'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; padding: 24px; color: #27272a; font-size: 10px; }
        .head { border-bottom: 3px solid #F97316; padding-bottom: 10px; margin-bottom: 14px; }
        .head h1 { margin: 0; font-size: 18px; color: #18181b; }
        .head .sub { color: #71717a; font-size: 11px; margin-top: 2px; }
        .head .meta { color: #a1a1aa; font-size: 9px; margin-top: 4px; }
        .summary { margin-bottom: 14px; }
        .summary .box { display: inline-block; border: 1px solid #FED7AA; background: #FFF7ED; border-radius: 6px; padding: 6px 12px; margin-right: 8px; }
        .summary .box .v { font-size: 14px; font-weight: bold; color: #EA580C; }
        .summary .box .l { font-size: 8px; text-transform: uppercase; color: #a1a1aa; letter-spacing: .5px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #FFF7ED; color: #9a3412; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; border-bottom: 2px solid #FED7AA; }
        td { padding: 5px 8px; border-bottom: 1px solid #f4f4f5; }
        tr:nth-child(even) td { background: #fffdfb; }
        .foot { margin-top: 14px; color: #a1a1aa; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $report['title'] }}</h1>
        <div class="sub">{{ $report['subtitle'] }}</div>
        <div class="meta">Generated {{ $generatedAt }} · {{ count($report['rows']) }} records · Pulse HRMS</div>
    </div>

    @if(! empty($report['summary']))
        <div class="summary">
            @foreach($report['summary'] as $s)
                <div class="box"><span class="v">{{ $s['value'] }}</span> <span class="l">{{ $s['label'] }}</span></div>
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>@foreach($report['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse($report['rows'] as $row)
                <tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($report['columns']) }}" style="text-align:center;color:#a1a1aa;padding:20px;">No records for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">Pulse HRMS — Conexus Network Solutions · Confidential</div>
</body>
</html>
