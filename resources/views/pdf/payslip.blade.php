<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip</title>
@php
    use Carbon\Carbon;
    use App\Models\Attendance;
    use App\Models\LeaveRequest;

    $company  = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#881819']);

    $brand    = '#881819';
    $curr     = 'Rs.'; // ₹ not supported in DomPDF DejaVu font — use Rs.
    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;

    // Logo via base64 (requires GD) — graceful fallback to text
    $logoData = null;
    if (extension_loaded('gd')) {
        foreach ([
            public_path('build/assets/logo-company.png'),
            public_path('storage/' . $company->logo),
        ] as $p) {
            if ($p && file_exists($p)) {
                $mime     = mime_content_type($p);
                $logoData = "data:{$mime};base64," . base64_encode(file_get_contents($p));
                break;
            }
        }
    }

    // Cycle dates
    $monthNum  = Carbon::parse("1 {$payroll->month} {$payroll->year}")->month;
    if ($payroll->cycle === 'cycle_a') {
        $from = Carbon::create($payroll->year, $monthNum, 1)->startOfDay();
        $to   = $from->copy()->endOfMonth();
    } else {
        $from = Carbon::create($payroll->year, $monthNum, 1)->subMonth()->setDay(21)->startOfDay();
        $to   = Carbon::create($payroll->year, $monthNum, 20)->endOfDay();
    }

    // Attendance stats — use integers throughout
    $totalDays   = (int) ($from->diffInDays($to) + 1);
    $weekOff     = 0;
    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
        if ($d->isSunday()) $weekOff++;
    }
    $paidDays    = $totalDays - $weekOff;
    $presentDays = (int) Attendance::where('employee_id', $employee->id)
        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->whereNotNull('check_in')->count();

    $leaveDays = 0;
    LeaveRequest::where('employee_id', $employee->id)
        ->where('status', 'approved')
        ->where('start_date', '<=', $to->toDateString())
        ->where('end_date', '>=', $from->toDateString())
        ->get()
        ->each(function ($lr) use ($from, $to, &$leaveDays) {
            $s = Carbon::parse($lr->start_date)->max($from);
            $e = Carbon::parse($lr->end_date)->min($to);
            if ($s->lte($e)) $leaveDays += (int)($s->diffInDays($e) + 1);
        });

    $lwp = max(0, $paidDays - $presentDays - $leaveDays);

    // Items
    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count());

    $monthLabel   = Carbon::parse("1 {$payroll->month} {$payroll->year}")->format('F Y');
    $cycleLabel   = $payroll->cycle === 'cycle_a' ? '1st – Last' : '21st – 20th';
    $joiningLabel = $employee->joining_date
        ? Carbon::parse($employee->joining_date)->format('F Y') : '—';
@endphp
<style>
    @page { margin: 0; size: A4; }
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        font-size: 9px;
        color: #1a1a1a;
        background: #fff;
    }

    /* ── OUTER WRAPPER ── */
    .page {
        padding: 0;
        width: 100%;
    }

    /* ── TOP COLOR BAND ── */
    .top-band {
        background: {{ $brand }};
        height: 6px;
        width: 100%;
    }

    /* ── HEADER ── */
    .header {
        padding: 18px 28px 14px;
        border-bottom: 1px solid #e0e0e0;
        width: 100%;
    }
    .header-table { width: 100%; border-collapse: collapse; }
    .header-logo-cell { width: 110px; vertical-align: middle; }
    .header-center { text-align: center; vertical-align: middle; padding: 0 8px; }
    .header-right { width: 100px; text-align: right; vertical-align: middle; }

    .logo-img { width: 100px; height: auto; }
    .logo-text {
        font-size: 22px;
        font-weight: 900;
        color: {{ $brand }};
        letter-spacing: -0.5px;
        line-height: 1;
    }
    .logo-sub {
        font-size: 7px;
        color: #999;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-top: 1px;
    }

    .company-name {
        font-size: 14px;
        font-weight: 700;
        color: #111;
        letter-spacing: 0.2px;
    }
    .company-detail {
        font-size: 8px;
        color: #666;
        margin-top: 2px;
        line-height: 1.6;
    }

    .slip-badge {
        background: {{ $brand }};
        color: #fff;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        padding: 4px 8px 3px;
        border-radius: 3px;
        display: inline-block;
        white-space: nowrap;
    }
    .slip-month {
        font-size: 9px;
        color: #888;
        margin-top: 5px;
        font-weight: 600;
    }

    /* ── SECTION TITLE ── */
    .section-title {
        font-size: 7.5px;
        font-weight: 700;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: {{ $brand }};
        padding: 7px 28px 4px;
        border-top: 1px solid #eee;
    }

    /* ── EMPLOYEE INFO ── */
    .emp-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0 0 0;
    }
    .emp-table td {
        padding: 5px 12px 5px 28px;
        font-size: 8.5px;
        vertical-align: top;
        border-bottom: 1px solid #f0f0f0;
    }
    .emp-table td:nth-child(3) { padding-left: 12px; border-left: 1px solid #f0f0f0; }
    .emp-label { color: #999; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 1px; }
    .emp-value { font-weight: 600; color: #111; }

    /* ── ATTENDANCE BAR ── */
    .att-wrap { padding: 0 28px 0; }
    .att-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin: 8px 0;
    }
    .att-table td {
        text-align: center;
        padding: 7px 4px 5px;
        border-right: 1px solid #e5e5e5;
        vertical-align: middle;
    }
    .att-table td:last-child { border-right: none; }
    .att-label { font-size: 6.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #aaa; display: block; margin-bottom: 2px; }
    .att-value { font-size: 13px; font-weight: 800; color: #111; display: block; line-height: 1; }
    .att-value.lwp-red { color: {{ $brand }}; }

    /* ── SALARY TABLE ── */
    .sal-wrap { padding: 0 28px 0; }
    .sal-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #ddd;
        margin: 8px 0 0;
        table-layout: fixed;
    }
    .sal-table thead tr {
        background: {{ $brand }};
    }
    .sal-table thead th {
        color: #fff;
        font-size: 8px;
        font-weight: 700;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        padding: 7px 10px;
        text-align: left;
    }
    .sal-table thead th.right { text-align: right; }
    .sal-table thead th.divider { border-left: 1px solid rgba(255,255,255,0.25); }
    .col-desc-e { width: 32%; }
    .col-amt-e  { width: 18%; border-right: 2px solid #bbb; }
    .col-desc-d { width: 30%; }
    .col-amt-d  { width: 20%; }

    .sal-table tbody tr:nth-child(odd)  { background: #fff; }
    .sal-table tbody tr:nth-child(even) { background: #fafafa; }
    .sal-table tbody td {
        padding: 5px 10px;
        font-size: 8.5px;
        color: #333;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: top;
    }
    .sal-table tbody td.amt    { text-align: right; font-weight: 600; font-family: 'DejaVu Sans Mono', monospace; }
    .sal-table tbody td.right-div { border-right: 2px solid #ddd; }
    .sal-table tbody td.ded-label { color: #555; }

    /* Total row */
    .sal-table tfoot tr.total td {
        background: #f2f2f2;
        font-weight: 700;
        font-size: 9px;
        padding: 6px 10px;
        border-top: 2px solid #ccc;
        border-bottom: none;
    }
    .sal-table tfoot tr.total td.amt { font-family: 'DejaVu Sans Mono', monospace; }

    /* Net pay row */
    .sal-table tfoot tr.net td {
        background: {{ $brand }};
        color: #fff;
        font-weight: 700;
        font-size: 12px;
        padding: 10px 12px;
        border: none;
    }
    .sal-table tfoot tr.net td.net-label { font-size: 10px; letter-spacing: 0.5px; text-align: right; }
    .sal-table tfoot tr.net td.net-amount {
        text-align: right;
        font-size: 15px;
        font-weight: 900;
        font-family: 'DejaVu Sans Mono', monospace;
    }

    /* ── FOOTER ── */
    .footer {
        margin: 16px 28px 0;
        padding: 8px 0;
        border-top: 1px solid #eee;
        display: table;
        width: calc(100% - 56px);
    }
    .footer-left  { display: table-cell; width: 50%; font-size: 7.5px; color: #aaa; vertical-align: bottom; }
    .footer-right { display: table-cell; width: 50%; text-align: right; font-size: 7.5px; color: #aaa; vertical-align: bottom; }

    .bottom-band {
        background: {{ $brand }};
        height: 4px;
        width: 100%;
        margin-top: 24px;
    }
</style>
</head>
<body>
<div class="page">

    <div class="top-band"></div>

    {{-- HEADER --}}
    <div class="header">
        <table class="header-table" cellpadding="0" cellspacing="0">
            <tr>
                <td class="header-logo-cell">
                    @if($logoData)
                        <img src="{{ $logoData }}" class="logo-img" alt="{{ $company->name }}">
                    @else
                        <div class="logo-text">
                            @php
                                // Build styled text from company name
                                $parts = explode(' ', $company->name);
                                echo strtoupper(implode('', array_map(fn($p) => substr($p,0,3), array_slice($parts,0,2))));
                            @endphp
                        </div>
                        <div class="logo-sub">{{ strtolower($company->industry ?? 'Technologies') }}</div>
                    @endif
                </td>
                <td class="header-center">
                    <div class="company-name">{{ $company->name }}</div>
                    @if($company->address)
                        <div class="company-detail">{{ $company->address }}</div>
                    @endif
                    @if($company->city)
                        <div class="company-detail">{{ $company->city }}{{ $company->country ? ', ' . $company->country : '' }}</div>
                    @endif
                    @if($company->phone || $company->email)
                        <div class="company-detail">
                            {{ $company->phone }}{{ $company->phone && $company->email ? '  ·  ' : '' }}{{ $company->email }}
                        </div>
                    @endif
                </td>
                <td class="header-right">
                    <div class="slip-badge">Salary Slip</div>
                    <div class="slip-month">{{ $monthLabel }}</div>
                    <div class="slip-month" style="color:#bbb; font-size:7.5px; font-weight:400;">{{ $cycleLabel }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- EMPLOYEE INFO --}}
    <div class="section-title">Employee Details</div>
    <table class="emp-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width:50%;">
                <span class="emp-label">Employee Name</span>
                <span class="emp-value">{{ $employee->user->name }}</span>
            </td>
            <td style="width:50%;">
                <span class="emp-label">Employee ID</span>
                <span class="emp-value">{{ $employee->employee_id ?? '—' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="emp-label">Designation</span>
                <span class="emp-value">{{ $employee->jobTitle?->title ?? $employee->jobTitle?->name ?? '—' }}</span>
            </td>
            <td>
                <span class="emp-label">Department</span>
                <span class="emp-value">{{ $employee->department?->name ?? '—' }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="emp-label">Date of Joining</span>
                <span class="emp-value">{{ $employee->joining_date ? Carbon::parse($employee->joining_date)->format('d M Y') : '—' }}</span>
            </td>
            <td>
                <span class="emp-label">Pay Period</span>
                <span class="emp-value">{{ $monthLabel }} &nbsp;·&nbsp; {{ $cycleLabel }}</span>
            </td>
        </tr>
    </table>

    {{-- ATTENDANCE --}}
    <div class="section-title">Attendance Summary</div>
    <div class="att-wrap">
        <table class="att-table" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width:16%">
                    <span class="att-label">Total Days</span>
                    <span class="att-value">{{ $totalDays }}</span>
                </td>
                <td style="width:16%">
                    <span class="att-label">Week Off</span>
                    <span class="att-value">{{ $weekOff }}</span>
                </td>
                <td style="width:16%">
                    <span class="att-label">Paid Days</span>
                    <span class="att-value">{{ $paidDays }}</span>
                </td>
                <td style="width:16%">
                    <span class="att-label">Present</span>
                    <span class="att-value">{{ $presentDays }}</span>
                </td>
                <td style="width:16%">
                    <span class="att-label">On Leave</span>
                    <span class="att-value">{{ $leaveDays }}</span>
                </td>
                <td style="width:20%">
                    <span class="att-label">LWP / Absent</span>
                    <span class="att-value {{ $lwp > 0 ? 'lwp-red' : '' }}">{{ $lwp > 0 ? $lwp : '0' }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- EARNINGS & DEDUCTIONS --}}
    <div class="section-title">Earnings &amp; Deductions</div>
    <div class="sal-wrap">
        <table class="sal-table" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th class="col-desc-e">Earnings &amp; Reimbursements</th>
                    <th class="col-amt-e right">Amount ({{ $curr }})</th>
                    <th class="col-desc-d divider">Deductions &amp; Recoveries</th>
                    <th class="col-amt-d right">Amount ({{ $curr }})</th>
                </tr>
            </thead>
            <tbody>
                @for($i = 0; $i < $maxRows; $i++)
                    @php $e = $earnings->get($i); $d = $deductions->get($i); @endphp
                    <tr>
                        <td>{{ $e?->name ?? '' }}</td>
                        <td class="amt right-div">{{ $e ? number_format($e->amount, 2) : '' }}</td>
                        <td class="ded-label">{{ $d?->name ?? '' }}</td>
                        <td class="amt">{{ $d ? number_format($d->amount, 2) : '' }}</td>
                    </tr>
                @endfor

                {{-- Filler rows to balance columns --}}
                @if($maxRows < 4)
                    @for($i = $maxRows; $i < 4; $i++)
                        <tr>
                            <td>&nbsp;</td><td class="right-div"></td><td></td><td></td>
                        </tr>
                    @endfor
                @endif
            </tbody>
            <tfoot>
                <tr class="total">
                    <td><strong>Total Earnings</strong></td>
                    <td class="amt right-div"><strong>{{ number_format($payslip->gross_salary, 2) }}</strong></td>
                    <td><strong>Total Deductions</strong></td>
                    <td class="amt"><strong>{{ number_format($payslip->total_deductions, 2) }}</strong></td>
                </tr>
                <tr class="net">
                    <td colspan="2" class="net-label">Net Pay</td>
                    <td colspan="2" class="net-amount">
                        {{ $curr }} {{ number_format($payslip->net_salary, 2) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        <div class="footer-left">This is a computer-generated payslip and does not require a signature.</div>
        <div class="footer-right">Generated: {{ now()->format('d M Y') }}</div>
    </div>

    <div class="bottom-band"></div>

</div>
</body>
</html>
