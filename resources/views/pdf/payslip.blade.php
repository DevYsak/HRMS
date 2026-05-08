<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip</title>
@php
    use Carbon\Carbon;
    use App\Models\Attendance;
    use App\Models\LeaveRequest;
    use App\Models\PublicHoliday;

    $company  = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#1DB77A']);

    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;

    // Logo — only embed if GD extension is available (DomPDF requires it for images)
    $logoData = null;
    $gdAvailable = extension_loaded('gd');
    if ($gdAvailable) {
        if ($company->logo) {
            $p = public_path('storage/' . $company->logo);
            if (file_exists($p)) {
                $mime     = mime_content_type($p);
                $logoData = "data:{$mime};base64," . base64_encode(file_get_contents($p));
            }
        }
        if (!$logoData) {
            $p = public_path('build/assets/logo-company.png');
            if (file_exists($p)) {
                $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($p));
            }
        }
    }

    // Cycle date range
    $monthNum  = Carbon::parse("1 {$payroll->month} {$payroll->year}")->month;
    if ($payroll->cycle === 'cycle_a') {
        $cycleFrom = Carbon::create($payroll->year, $monthNum, 1)->startOfDay();
        $cycleTo   = $cycleFrom->copy()->endOfMonth();
    } else {
        $cycleFrom = Carbon::create($payroll->year, $monthNum, 1)->subMonth()->setDay(21)->startOfDay();
        $cycleTo   = Carbon::create($payroll->year, $monthNum, 20)->endOfDay();
    }

    // Attendance stats
    $totalDays    = $cycleFrom->diffInDays($cycleTo) + 1;
    $weekOffDays  = 0;
    for ($d = $cycleFrom->copy(); $d->lte($cycleTo); $d->addDay()) {
        if ($d->isSunday()) { $weekOffDays++; }
    }
    $paidDays    = $totalDays - $weekOffDays;

    $presentDays = Attendance::where('employee_id', $employee->id)
        ->whereBetween('date', [$cycleFrom->toDateString(), $cycleTo->toDateString()])
        ->whereNotNull('check_in')
        ->count();

    $approvedLeaveDays = 0;
    $leaveRequests = LeaveRequest::where('employee_id', $employee->id)
        ->where('status', 'approved')
        ->whereBetween('start_date', [$cycleFrom->toDateString(), $cycleTo->toDateString()])
        ->get();
    foreach ($leaveRequests as $lr) {
        $s = Carbon::parse($lr->start_date)->max($cycleFrom);
        $e = Carbon::parse($lr->end_date)->min($cycleTo);
        if ($s->lte($e)) { $approvedLeaveDays += $s->diffInDays($e) + 1; }
    }

    $lwp = max(0, $paidDays - $presentDays - $approvedLeaveDays);

    // Earnings & Deductions
    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count());

    $monthLabel = Carbon::parse("1 {$payroll->month} {$payroll->year}")->format('F-Y');
    $joiningLabel = $employee->joining_date
        ? Carbon::parse($employee->joining_date)->format('F-Y')
        : '—';
@endphp
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', Arial, sans-serif;
        font-size: 9.5px;
        color: #222;
        padding: 22px 28px;
        background: #fff;
    }

    /* ── TOP HEADER ── */
    .top-header {
        width: 100%;
        margin-bottom: 10px;
        border-bottom: 2px solid #333;
        padding-bottom: 8px;
    }
    .logo-cell { width: 90px; vertical-align: middle; }
    .logo-cell img { width: 80px; height: auto; }
    .logo-cell .company-text {
        font-size: 18px;
        font-weight: 900;
        color: {{ $company->primary_color }};
        letter-spacing: -0.5px;
    }
    .company-info-cell { text-align: center; vertical-align: middle; }
    .company-info-cell .company-name {
        font-size: 13px;
        font-weight: 700;
        color: #111;
        margin-bottom: 2px;
    }
    .company-info-cell .company-addr {
        font-size: 8.5px;
        color: #555;
        line-height: 1.5;
    }

    /* ── EMPLOYEE INFO ── */
    .info-table {
        width: 100%;
        margin-bottom: 0;
        border-collapse: collapse;
    }
    .info-table td {
        padding: 3px 6px;
        font-size: 9px;
        vertical-align: top;
        border: none;
    }
    .info-label { color: #555; width: 90px; }
    .info-value { font-weight: 600; color: #111; }
    .info-section {
        border: 1px solid #ccc;
        margin-bottom: 6px;
    }

    /* ── ATTENDANCE BAR ── */
    .att-bar {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
        background: #f5f5f5;
        border: 1px solid #bbb;
    }
    .att-bar td {
        padding: 5px 10px;
        text-align: center;
        font-size: 9px;
        border-right: 1px solid #ccc;
    }
    .att-bar td:last-child { border-right: none; }
    .att-label { font-size: 7.5px; color: #888; display: block; margin-bottom: 1px; }
    .att-value { font-size: 11px; font-weight: 700; color: #111; }

    /* ── SALARY TABLE ── */
    .salary-wrap {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #bbb;
        margin-bottom: 0;
    }
    .salary-wrap th {
        background: #e8e8e8;
        padding: 5px 8px;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        border: 1px solid #bbb;
    }
    .salary-wrap td {
        padding: 4px 8px;
        font-size: 9px;
        border-bottom: 1px solid #e5e5e5;
        vertical-align: top;
    }
    .salary-wrap .divider td { border-right: 1px solid #bbb; }
    .salary-wrap .amount { text-align: right; }
    .salary-wrap .total-row td {
        background: #f0f0f0;
        font-weight: 700;
        font-size: 9.5px;
        border-top: 1.5px solid #999;
        padding: 5px 8px;
    }
    .salary-wrap .net-row td {
        background: {{ $company->primary_color }};
        color: #fff;
        font-weight: 700;
        font-size: 11px;
        padding: 7px 10px;
        border: none;
    }
    .col-earn { width: 50%; border-right: 2px solid #aaa; }
    .col-ded  { width: 50%; }

    .footer-note {
        margin-top: 18px;
        text-align: center;
        font-size: 8px;
        color: #999;
        border-top: 1px solid #ddd;
        padding-top: 8px;
    }
</style>
</head>
<body>

{{-- ── COMPANY HEADER ── --}}
<table class="top-header" cellpadding="0" cellspacing="0">
    <tr>
        <td class="logo-cell">
            @if($logoData)
                <img src="{{ $logoData }}" alt="{{ $company->name }}">
            @else
                <div class="company-text">{{ strtoupper(substr($company->name, 0, 6)) }}</div>
            @endif
        </td>
        <td class="company-info-cell">
            <div class="company-name">{{ $company->name }}</div>
            @if($company->address)
                <div class="company-addr">{{ $company->address }}</div>
            @endif
            @if($company->city)
                <div class="company-addr">{{ $company->city }}{{ $company->country ? ', ' . $company->country : '' }}</div>
            @endif
            @if($company->phone || $company->email)
                <div class="company-addr">
                    {{ $company->phone }}{{ $company->phone && $company->email ? '  |  ' : '' }}{{ $company->email }}
                </div>
            @endif
        </td>
        <td style="width:90px; text-align:right; vertical-align:middle;">
            <div style="font-size:11px; font-weight:800; color:{{ $company->primary_color }};">PAYSLIP</div>
            <div style="font-size:8px; color:#888; margin-top:2px;">{{ $monthLabel }}</div>
        </td>
    </tr>
</table>

{{-- ── EMPLOYEE INFO ── --}}
<table class="info-section" style="width:100%; border-collapse:collapse; margin-bottom:6px;">
    <tr>
        <td style="width:50%; padding:4px 8px; border-right:1px solid #ddd; font-size:9px;">
            <span style="color:#888;">Month:</span>
            <strong style="margin-left:6px;">{{ $monthLabel }}</strong>
        </td>
        <td style="width:50%; padding:4px 8px; font-size:9px;">
            <span style="color:#888;">Joining Month:</span>
            <strong style="margin-left:6px;">{{ $joiningLabel }}</strong>
        </td>
    </tr>
    <tr style="border-top:1px solid #eee;">
        <td style="padding:4px 8px; border-right:1px solid #ddd; font-size:9px;">
            <span style="color:#888;">Name:</span>
            <strong style="margin-left:6px;">{{ $employee->user->name }}</strong>
        </td>
        <td style="padding:4px 8px; font-size:9px;">
            <span style="color:#888;">Department:</span>
            <strong style="margin-left:6px;">{{ $employee->department?->name ?? '—' }}</strong>
        </td>
    </tr>
    <tr style="border-top:1px solid #eee;">
        <td style="padding:4px 8px; border-right:1px solid #ddd; font-size:9px;">
            <span style="color:#888;">Designation:</span>
            <strong style="margin-left:6px;">{{ $employee->jobTitle?->title ?? $employee->jobTitle?->name ?? '—' }}</strong>
        </td>
        <td style="padding:4px 8px; font-size:9px;">
            <span style="color:#888;">Employee ID:</span>
            <strong style="margin-left:6px;">{{ $employee->employee_id ?? '—' }}</strong>
        </td>
    </tr>
</table>

{{-- ── ATTENDANCE BAR ── --}}
<table class="att-bar" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <span class="att-label">Paid Days</span>
            <span class="att-value">{{ $paidDays }}</span>
        </td>
        <td>
            <span class="att-label">Present</span>
            <span class="att-value">{{ $presentDays }}</span>
        </td>
        <td>
            <span class="att-label">Approved Leave</span>
            <span class="att-value">{{ $approvedLeaveDays }}</span>
        </td>
        <td>
            <span class="att-label">Wk Off</span>
            <span class="att-value">{{ $weekOffDays }}</span>
        </td>
        <td>
            <span class="att-label">LWP / ABS</span>
            <span class="att-value" style="{{ $lwp > 0 ? 'color:#c00;' : '' }}">{{ number_format($lwp, 2) }}</span>
        </td>
        <td>
            <span class="att-label">Cycle</span>
            <span class="att-value">{{ strtoupper(str_replace('_', ' ', $payroll->cycle)) }}</span>
        </td>
    </tr>
</table>

{{-- ── EARNINGS & DEDUCTIONS TABLE ── --}}
<table class="salary-wrap" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th class="col-earn" colspan="2">Earnings &amp; Reimbursements</th>
            <th class="col-ded"  colspan="2">Deductions &amp; Recoveries</th>
        </tr>
    </thead>
    <tbody>
        @for($i = 0; $i < $maxRows; $i++)
            @php
                $earn = $earnings->get($i);
                $ded  = $deductions->get($i);
            @endphp
            <tr class="divider">
                <td class="col-earn" style="border-right:none;">{{ $earn?->name ?? '' }}</td>
                <td class="col-earn amount" style="border-right:2px solid #aaa;">
                    {{ $earn ? number_format($earn->amount, 2) : '' }}
                </td>
                <td>{{ $ded?->name ?? '' }}</td>
                <td class="amount">{{ $ded ? number_format($ded->amount, 2) : '' }}</td>
            </tr>
        @endfor
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td class="col-earn" style="border-right:none;"><strong>Total Earnings</strong></td>
            <td class="col-earn amount" style="border-right:2px solid #999;">
                <strong>{{ number_format($payslip->gross_salary, 2) }}</strong>
            </td>
            <td><strong>Total Deductions</strong></td>
            <td class="amount"><strong>{{ number_format($payslip->total_deductions, 2) }}</strong></td>
        </tr>
        <tr class="net-row">
            <td colspan="2" style="text-align:right; padding-right:16px;">Net Pay</td>
            <td colspan="2" style="text-align:right; font-size:13px;">
                {{ $company->currency_symbol ?? '₹' }} {{ number_format($payslip->net_salary, 2) }}
            </td>
        </tr>
    </tfoot>
</table>

<div class="footer-note">
    This is a system-generated payslip and does not require a signature. &nbsp;|&nbsp;
    Generated: {{ now()->format('d M Y, H:i') }}
</div>

</body>
</html>
