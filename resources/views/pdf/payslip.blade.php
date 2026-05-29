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
        ?? new \App\Models\Company(['name' => 'Conexus Network Solutions Pvt Ltd']);

    $brand    = '#fe9a00';
    $curr     = 'Rs.';

    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;
    $user     = $employee->user;

    // Avatar initials
    $nameParts = explode(' ', trim($user->name ?? 'U'));
    $initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

    // Cycle dates
    $monthNum = Carbon::parse("1 {$payroll->month} {$payroll->year}")->month;
    if ($payroll->cycle === 'cycle_a') {
        $from = Carbon::create($payroll->year, $monthNum, 1)->startOfDay();
        $to   = $from->copy()->endOfMonth();
    } else {
        $from = Carbon::create($payroll->year, $monthNum, 1)->subMonth()->setDay(21)->startOfDay();
        $to   = Carbon::create($payroll->year, $monthNum, 20)->endOfDay();
    }

    // Attendance stats
    $totalDays   = (int)($from->diffInDays($to) + 1);
    $weekOff     = 0;
    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
        if ($d->isSunday() || $d->isSaturday()) $weekOff++;
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
        ->get()->each(function($lr) use ($from, $to, &$leaveDays) {
            $s = Carbon::parse($lr->start_date)->max($from);
            $e = Carbon::parse($lr->end_date)->min($to);
            if ($s->lte($e)) $leaveDays += (int)($s->diffInDays($e) + 1);
        });
    // LWP only meaningful if attendance is tracked; default 0 if no data
    $lwp = ($presentDays > 0 || $leaveDays > 0) ? max(0, $paidDays - $presentDays - $leaveDays) : 0;

    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count(), 4);

    $monthLabel  = $payroll->month . ' ' . $payroll->year;
    $cycleLabel  = $payroll->month . ' ' . $payroll->year . ' - ' . strtoupper(str_replace(['cycle_', '_'], ['CYCLE ', ' '], $payroll->cycle ?? 'cycle_a'));
    $joiningLabel = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('F Y') : '—';
    $paymentDate  = $to->copy()->addDay()->format('d M Y');

    // Company info with correct domain
    $companyName    = $company->name ?? 'Conexus Network Solutions Pvt Ltd';
    $companyAddress = $company->address ?? 'F-25, Centurion Mall, Sector 19A, Nerul East, Navi Mumbai - 400706';
    $companyPhone   = $company->phone ?? '+91 98765 43210';
    $companyEmail   = $company->email ?? 'hr@conexus-ns.com';
    $companyWebsite = 'https://conexus-ns.com';
    $companyCIN     = $company->cin ?? null;

    // YTD calculations
    $ytdPayslips   = \App\Models\Payslip::where('employee_id', $employee->id)
        ->where('status', 'paid')
        ->whereHas('payroll', fn($q) => $q->where('year', $payroll->year))
        ->with('items')->get();
    $ytdGross      = $ytdPayslips->sum('gross_salary');
    $ytdDeductions = $ytdPayslips->sum('total_deductions');
    $ytdTax        = $ytdPayslips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
    $ytdTaxable    = max(0, $ytdGross - $ytdPayslips->flatMap->items->where('name', 'Provident Fund (PF)')->sum('amount'));
@endphp
<style>
    @page { margin: 0; size: A4; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }

    .wrap { width: 100%; }

    /* ── HEADER ── */
    .hdr { padding: 14px 22px 12px; border-bottom: 2px solid #f0f0f0; }
    .hdr-tbl { width: 100%; border-collapse: collapse; }
    .hdr-logo { width: 120px; vertical-align: middle; }
    .hdr-ctr  { vertical-align: middle; text-align: center; padding: 0 8px; }
    .hdr-rgt  { width: 145px; text-align: right; vertical-align: middle; }

    /* Logo text style */
    .logo-box { display: inline-block; }
    .logo-con { font-size: 20px; font-weight: 900; color: #111; letter-spacing: -0.5px; line-height: 1; }
    .logo-exus { font-size: 20px; font-weight: 900; color: #111; letter-spacing: -0.5px; }
    .logo-sub { font-size: 6.5px; color: #888; letter-spacing: 2.5px; text-transform: uppercase; margin-top: 1px; }
    .logo-line { height: 2px; background: {{ $brand }}; margin-top: 2px; }

    .co-name { font-size: 13px; font-weight: 800; color: #111; }
    .co-addr { font-size: 7.5px; color: #555; margin-top: 3px; line-height: 1.7; }

    .slip-word { font-size: 24px; font-weight: 900; color: #111; letter-spacing: 1.5px; line-height: 1; }
    .slip-mth  { font-size: 14px; font-weight: 800; color: {{ $brand }}; margin-top: 2px; }
    .cycle-pill {
        background: #fff8ee; border: 1px solid #fdd580;
        color: #a05a00; font-size: 7px; font-weight: 700;
        padding: 3px 8px; border-radius: 3px;
        display: inline-block; margin-top: 6px;
    }

    /* ── EMP CARD ── */
    .emp { padding: 12px 22px 10px; border-bottom: 1px solid #eee; }
    .emp-tbl { width: 100%; border-collapse: collapse; }

    .avatar {
        width: 58px; height: 58px; border-radius: 50%;
        background: {{ $brand }};
        text-align: center; line-height: 58px;
        font-size: 20px; font-weight: 900; color: #fff;
        display: inline-block;
    }
    .emp-name  { font-size: 14px; font-weight: 900; color: #111; line-height: 1.2; }
    .emp-role  { font-size: 9.5px; color: {{ $brand }}; font-weight: 700; margin-top: 2px; }
    .emp-eid   { font-size: 7.5px; color: #999; margin-top: 5px; }

    .fi { margin-bottom: 6px; }
    .fl { font-size: 7px; color: #999; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .fv { font-size: 8.5px; font-weight: 700; color: #222; display: block; margin-top: 1px; }

    /* ── SUMMARY BOXES ── */
    .sum-wrap { padding: 10px 22px; border-bottom: 1px solid #eee; }
    .sum-tbl { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
    .sum-box {
        border: 1px solid #e5e5e5; border-radius: 5px;
        padding: 10px 14px; vertical-align: top; width: 33%;
    }
    .sum-box-net {
        border: 1.5px solid #86efac; border-radius: 5px;
        background: #f0fdf4; padding: 10px 14px; vertical-align: top; width: 33%;
    }
    .sl { font-size: 7px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; color: #888; }
    .sa { font-size: 16px; font-weight: 900; color: #111; line-height: 1.2; margin-top: 3px; }
    .sa-net { font-size: 16px; font-weight: 900; color: #16a34a; line-height: 1.2; margin-top: 3px; }
    .ss { font-size: 7px; color: #bbb; margin-top: 2px; }

    /* ── EARNINGS TABLE ── */
    .sal-wrap { padding: 2px 22px 8px; }
    .sal-tbl { width: 100%; border-collapse: collapse; margin-top: 8px; table-layout: fixed; }

    .eth { font-size: 7.5px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.4px;
           color: {{ $brand }}; padding: 6px 10px 5px; border-bottom: 2px solid {{ $brand }};
           text-align: left; }
    .ath { font-size: 7.5px; font-weight: 900; text-transform: uppercase;
           color: {{ $brand }}; padding: 6px 10px 5px; border-bottom: 2px solid {{ $brand }};
           text-align: right; }
    .dth { font-size: 7.5px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.4px;
           color: {{ $brand }}; padding: 6px 10px 5px; border-bottom: 2px solid {{ $brand }};
           text-align: left; border-left: 2px solid #eee; }
    .xth { font-size: 7.5px; font-weight: 900; text-transform: uppercase;
           color: {{ $brand }}; padding: 6px 10px 5px; border-bottom: 2px solid {{ $brand }};
           text-align: right; }

    .etd { padding: 5px 10px; font-size: 8.5px; color: #333; border-bottom: 1px solid #f5f5f5; }
    .atd { padding: 5px 10px; font-size: 8.5px; text-align: right; font-weight: 600;
           color: #222; border-bottom: 1px solid #f5f5f5; font-family: 'DejaVu Sans Mono', monospace; }
    .dtd { padding: 5px 10px; font-size: 8.5px; color: #555;
           border-bottom: 1px solid #f5f5f5; border-left: 2px solid #eee; }
    .xtd { padding: 5px 10px; font-size: 8.5px; text-align: right; font-weight: 600;
           color: #555; border-bottom: 1px solid #f5f5f5; font-family: 'DejaVu Sans Mono', monospace; }

    .tot-e { background: #fef9f0; font-weight: 900; font-size: 9px; color: {{ $brand }};
             padding: 7px 10px; border-top: 2px solid #ffe0b2; }
    .tot-a { background: #fef9f0; font-weight: 900; font-size: 9px; color: {{ $brand }};
             text-align: right; padding: 7px 10px; border-top: 2px solid #ffe0b2;
             font-family: 'DejaVu Sans Mono', monospace; }
    .tot-d { background: #fef9f0; font-weight: 900; font-size: 9px; color: #c00;
             padding: 7px 10px; border-top: 2px solid #ffe0b2; border-left: 2px solid #eee; }
    .tot-x { background: #fef9f0; font-weight: 900; font-size: 9px; color: #c00;
             text-align: right; padding: 7px 10px; border-top: 2px solid #ffe0b2;
             font-family: 'DejaVu Sans Mono', monospace; }

    /* ── ATTENDANCE ── */
    .att-wrap { padding: 0 22px 8px; }
    .att-title { font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
                 color: #333; padding: 8px 0 6px; border-top: 1px solid #eee; }
    .att-tbl { width: 100%; border-collapse: collapse; border: 1px solid #e5e5e5; }
    .att-td { text-align: center; padding: 12px 6px 10px; border-right: 1px solid #e5e5e5; width: 25%; vertical-align: middle; }
    .att-td:last-child { border-right: none; }
    .att-icon { width: 28px; height: 28px; border-radius: 50%; margin: 0 auto 4px auto; line-height: 28px; text-align: center; font-size: 10px; font-weight: 700; }
    .ic-blue   { background: #dbeafe; color: #1d4ed8; }
    .ic-green  { background: #dcfce7; color: #15803d; }
    .ic-purple { background: #ede9fe; color: #6d28d9; }
    .ic-orange { background: #ffedd5; color: #c2410c; }
    .att-lbl  { font-size: 7px; text-transform: uppercase; letter-spacing: 0.6px; color: #999; display: block; }
    .att-val  { font-size: 18px; font-weight: 900; color: #111; display: block; line-height: 1.1; margin-top: 1px; }
    .att-red  { font-size: 18px; font-weight: 900; color: {{ $brand }}; display: block; line-height: 1.1; margin-top: 1px; }

    /* ── BOTTOM 3-COL ── */
    .info-wrap { padding: 8px 22px; border-top: 1px solid #eee; }
    .info-tbl { width: 100%; border-collapse: collapse; }
    .ic1 { vertical-align: top; width: 33%; padding-right: 14px; border-right: 1px solid #eee; }
    .ic2 { vertical-align: top; width: 34%; padding: 0 14px; border-right: 1px solid #eee; }
    .ic3 { vertical-align: top; width: 33%; padding-left: 14px; }
    .ih { font-size: 8.5px; font-weight: 900; text-transform: uppercase; color: #333;
          letter-spacing: 0.5px; margin-bottom: 7px; display: block; }
    .ir { margin-bottom: 5px; display: table; width: 100%; }
    .irl { font-size: 7.5px; color: #888; display: table-cell; width: 48%; }
    .irc { font-size: 7.5px; color: #ccc; display: table-cell; width: 5%; }
    .irv { font-size: 8px; font-weight: 700; color: #222; display: table-cell; }

    /* ── FOOTER ── */
    .ftr-wrap { padding: 8px 22px 6px; border-top: 1px solid #eee; }
    .ftr-tbl { width: 100%; border-collapse: collapse; }
    .ftr-qr  { vertical-align: top; width: 70px; }
    .ftr-crd { vertical-align: middle; padding-left: 12px; width: 210px; }
    .ftr-sig { vertical-align: top; text-align: right; }
    .crd-box { border: 1px solid #86efac; background: #f0fdf4; border-radius: 5px; padding: 8px 12px; }
    .crd-ttl { font-size: 8.5px; font-weight: 900; color: #15803d; }
    .crd-sub { font-size: 7.5px; color: #555; margin-top: 3px; line-height: 1.6; }
    .sig-ln  { border-top: 1px solid #999; width: 110px; margin-left: auto; margin-top: 30px; }
    .sig-note { font-size: 7px; color: #999; text-align: center; margin-top: 3px; line-height: 1.5; }

    /* ── BOTTOM BAR ── */
    .btm { background: {{ $brand }}; padding: 7px 22px; margin-top: 6px; }
    .btm-tbl { width: 100%; border-collapse: collapse; }
    .btm-td { text-align: center; color: #fff; font-size: 8px; font-weight: 600;
              padding: 0 10px; border-right: 1px solid rgba(255,255,255,0.3); }
    .btm-td:last-child { border-right: none; }
</style>
</head>
<body>
<div class="wrap">

{{-- ── HEADER ── --}}
<div class="hdr">
<table class="hdr-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="hdr-logo">
        <div class="logo-box">
            <div><span class="logo-con">CON</span><span class="logo-exus" style="color:{{ $brand }};">EX</span><span class="logo-con">US</span></div>
            <div class="logo-sub">Network Solutions</div>
            <div class="logo-line" style="width:100px;"></div>
        </div>
    </td>
    <td class="hdr-ctr">
        <div class="co-name">{{ $companyName }}</div>
        <div class="co-addr">
            {{ $companyAddress }}<br>
            {{ $companyPhone }} | {{ $companyWebsite }}
            @if($companyCIN)
            <br>CIN : {{ $companyCIN }}
            @endif
        </div>
    </td>
    <td class="hdr-rgt">
        <div class="slip-word">PAYSLIP</div>
        <div class="slip-mth">{{ $monthLabel }}</div>
        <div class="cycle-pill">Payroll Cycle: {{ $cycleLabel }}</div>
    </td>
</tr>
</table>
</div>

{{-- ── EMPLOYEE CARD ── --}}
<div class="emp">
<table class="emp-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td style="width:70px; vertical-align:middle;">
        <div class="avatar">{{ $initials }}</div>
    </td>
    <td style="vertical-align:top; padding-left:12px; width:42%;">
        <div class="emp-name">{{ $user->name }}</div>
        <div class="emp-role">{{ $employee->jobTitle?->name ?? 'Employee' }}</div>
        <div class="emp-eid">Employee ID: {{ $employee->employee_id ?? '—' }}</div>
    </td>
    <td style="vertical-align:top; width:28%; border-left:1px solid #eee; padding-left:14px;">
        <div class="fi"><span class="fl">Department</span><span class="fv">{{ $employee->department?->name ?? '—' }}</span></div>
        <div class="fi"><span class="fl">Date of Joining</span><span class="fv">{{ $joiningLabel }}</span></div>
        <div class="fi"><span class="fl">Location</span><span class="fv">{{ $employee->office?->name ?? '—' }}</span></div>
        <div class="fi" style="margin-bottom:0"><span class="fl">Gender</span><span class="fv">{{ ucfirst($employee->gender ?? '—') }}</span></div>
    </td>
    <td style="vertical-align:top; width:28%; border-left:1px solid #eee; padding-left:14px;">
        <div class="fi"><span class="fl">PAN</span><span class="fv">{{ $employee->pan_number ?? '—' }}</span></div>
        <div class="fi"><span class="fl">UAN</span><span class="fv">{{ $employee->uan_number ?? '—' }}</span></div>
        <div class="fi"><span class="fl">PF Account No</span><span class="fv">{{ $employee->pf_account ?? '—' }}</span></div>
        <div class="fi" style="margin-bottom:0"><span class="fl">ESI Number</span><span class="fv">{{ $employee->esi_number ?? '—' }}</span></div>
    </td>
</tr>
</table>
</div>

{{-- ── SALARY SUMMARY ── --}}
<div class="sum-wrap">
<table class="sum-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="sum-box">
        <div class="sl">Gross Salary</div>
        <div class="sa">{{ $curr }} {{ number_format($payslip->gross_salary, 2) }}</div>
        <div class="ss">Total Earnings (A)</div>
    </td>
    <td class="sum-box">
        <div class="sl">Total Deductions</div>
        <div class="sa">{{ $curr }} {{ number_format($payslip->total_deductions, 2) }}</div>
        <div class="ss">Total Deductions (B)</div>
    </td>
    <td class="sum-box-net">
        <div class="sl">Net Pay</div>
        <div class="sa-net">{{ $curr }} {{ number_format($payslip->net_salary, 2) }}</div>
        <div class="ss">(A - B) Net Payable</div>
    </td>
</tr>
</table>
</div>

{{-- ── EARNINGS & DEDUCTIONS TABLE ── --}}
<div class="sal-wrap">
<table class="sal-tbl" cellpadding="0" cellspacing="0">
<thead>
<tr>
    <th class="eth" style="width:34%;">Earnings &amp; Reimbursements</th>
    <th class="ath" style="width:16%;">Amount ({{ $curr }})</th>
    <th class="dth" style="width:34%;">Deductions &amp; Recoveries</th>
    <th class="xth" style="width:16%;">Amount ({{ $curr }})</th>
</tr>
</thead>
<tbody>
@for($i = 0; $i < $maxRows; $i++)
@php $e = $earnings->get($i); $d = $deductions->get($i); @endphp
<tr>
    <td class="etd">{{ $e?->name ?? '' }}</td>
    <td class="atd">{{ $e ? number_format($e->amount, 2) : '' }}</td>
    <td class="dtd">{{ $d?->name ?? '' }}</td>
    <td class="xtd">{{ $d ? number_format($d->amount, 2) : '' }}</td>
</tr>
@endfor
</tbody>
<tfoot>
<tr>
    <td class="tot-e">Total Earnings (A)</td>
    <td class="tot-a">{{ number_format($payslip->gross_salary, 2) }}</td>
    <td class="tot-d">Total Deductions (B)</td>
    <td class="tot-x">{{ number_format($payslip->total_deductions, 2) }}</td>
</tr>
</tfoot>
</table>
</div>

{{-- ── ATTENDANCE SUMMARY ── --}}
<div class="att-wrap">
<div class="att-title">Attendance Summary</div>
<table class="att-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="att-td">
        <div class="att-icon ic-blue">31</div>
        <span class="att-lbl">Paid Days</span>
        <span class="att-val">{{ $paidDays }}</span>
    </td>
    <td class="att-td">
        <div class="att-icon ic-green">P</div>
        <span class="att-lbl">Present Days</span>
        <span class="att-val">{{ $presentDays }}</span>
    </td>
    <td class="att-td">
        <div class="att-icon ic-purple">W</div>
        <span class="att-lbl">Week Off</span>
        <span class="att-val">{{ $weekOff }}</span>
    </td>
    <td class="att-td">
        <div class="att-icon ic-orange">L</div>
        <span class="att-lbl">LWP / ABS</span>
        <span class="{{ $lwp > 0 ? 'att-red' : 'att-val' }}">{{ number_format($lwp, 2) }}</span>
    </td>
</tr>
</table>
</div>

{{-- ── BOTTOM 3-COL ── --}}
<div class="info-wrap">
<table class="info-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="ic1">
        <span class="ih">Bank Details</span>
        <div class="ir"><span class="irl">Bank Name</span><span class="irc">:</span><span class="irv">{{ $employee->bank_name ?? 'HDFC Bank' }}</span></div>
        <div class="ir"><span class="irl">Account No</span><span class="irc">:</span><span class="irv">{{ $employee->bank_account ? '****' . substr($employee->bank_account, -4) : '—' }}</span></div>
        <div class="ir"><span class="irl">IFSC Code</span><span class="irc">:</span><span class="irv">{{ $employee->ifsc_code ?? '—' }}</span></div>
        <div class="ir"><span class="irl">Account Type</span><span class="irc">:</span><span class="irv">Savings</span></div>
    </td>
    <td class="ic2">
        <span class="ih">Tax Details (FY {{ $payroll->year }}-{{ substr($payroll->year + 1, -2) }})</span>
        <div class="ir"><span class="irl">YTD Gross Salary</span><span class="irc">:</span><span class="irv">{{ $curr }} {{ number_format($ytdGross, 2) }}</span></div>
        <div class="ir"><span class="irl">YTD Taxable Salary</span><span class="irc">:</span><span class="irv">{{ $curr }} {{ number_format($ytdTaxable, 2) }}</span></div>
        <div class="ir"><span class="irl">YTD Tax Paid</span><span class="irc">:</span><span class="irv">{{ $curr }} {{ number_format($ytdTax, 2) }}</span></div>
        <div class="ir"><span class="irl">YTD Deductions</span><span class="irc">:</span><span class="irv">{{ $curr }} {{ number_format($ytdDeductions, 2) }}</span></div>
    </td>
    <td class="ic3">
        <span class="ih">Other Information</span>
        <div class="ir"><span class="irl">Working Days</span><span class="irc">:</span><span class="irv">{{ $totalDays }}</span></div>
        <div class="ir"><span class="irl">Payroll Days</span><span class="irc">:</span><span class="irv">{{ $paidDays }}</span></div>
        <div class="ir"><span class="irl">Payment Date</span><span class="irc">:</span><span class="irv">{{ $paymentDate }}</span></div>
        <div class="ir"><span class="irl">Payment Mode</span><span class="irc">:</span><span class="irv">Bank Transfer</span></div>
    </td>
</tr>
</table>
</div>

{{-- ── FOOTER ── --}}
<div class="ftr-wrap">
<table class="ftr-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="ftr-qr">
        {{-- Simple QR-style grid --}}
        <table cellpadding="0" cellspacing="0" style="border:2px solid #111; border-radius:3px; overflow:hidden;">
        @php
            $qrRows = ['11111011','10001010','10101001','10001010','11111011','00000010','10110101','01001010'];
        @endphp
        @foreach($qrRows as $row)
        <tr>
            @foreach(str_split($row) as $b)
            <td style="width:5px;height:5px;background:{{ $b==='1'?'#111':'#fff' }};padding:0;"></td>
            @endforeach
        </tr>
        @endforeach
        </table>
    </td>
    <td class="ftr-crd">
        <div class="crd-box">
            <div class="crd-ttl">&#10003; SALARY CREDITED</div>
            <div class="crd-sub">Your salary for {{ $monthLabel }} has been<br>credited to your bank account.</div>
        </div>
    </td>
    <td class="ftr-sig">
        <div class="sig-ln"></div>
        <div class="sig-note">This is a system generated payslip.<br>No signature is required.</div>
    </td>
</tr>
</table>
</div>

{{-- ── BOTTOM BAR ── --}}
<div class="btm">
<table class="btm-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="btm-td">{{ $companyEmail }}</td>
    <td class="btm-td">{{ $companyPhone }}</td>
    <td class="btm-td" style="border-right:none;">{{ $companyWebsite }}</td>
</tr>
</table>
</div>

</div>
</body>
</html>
