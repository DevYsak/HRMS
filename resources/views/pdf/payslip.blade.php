<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Payslip</title>
@php
    use Carbon\Carbon;
    use App\Models\Attendance;
    use App\Models\LeaveRequest;

    $company  = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Conexus Network Solutions Pvt Ltd']);

    $orange   = '#f97316';
    $green    = '#16a34a';
    $brand    = $orange;

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

    // Attendance
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
    $lwp = ($presentDays > 0 || $leaveDays > 0) ? max(0, $paidDays - $presentDays - $leaveDays) : 0;

    // Items
    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count(), 5);

    // Labels
    $monthLabel  = $payroll->month . ' ' . $payroll->year;
    $cycleLabel  = $payroll->month . ' ' . $payroll->year . ' - Cycle ' . strtoupper(str_replace('cycle_', '', $payroll->cycle ?? 'a'));
    $joiningLabel = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('F Y') : '—';
    $paymentDate  = $to->copy()->addDay()->format('d M Y');

    // Company
    $companyName    = $company->name ?? 'Conexus Network Solutions Pvt Ltd';
    $companyAddress = $company->address ?? 'F-25, Centurion Mall, Sector 19A, Nerul East, Navi Mumbai - 400706';
    $companyCIN     = 'U72900MH2013PTC234567';
    $companyPhone   = $company->phone ?? '+91 98765 43210';
    $companyEmail   = $company->email ?? 'hr@conexus-ns.com';
    $companyWebsite = 'www.conexus-ns.com';

    // YTD
    $ytdSlips      = \App\Models\Payslip::where('employee_id', $employee->id)
        ->where('status', 'paid')
        ->whereHas('payroll', fn($q) => $q->where('year', $payroll->year))
        ->with('items')->get();
    $ytdGross      = $ytdSlips->sum('gross_salary');
    $ytdDeductions = $ytdSlips->sum('total_deductions');
    $ytdTax        = $ytdSlips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
    $ytdTaxable    = max(0, $ytdGross - $ytdSlips->flatMap->items->where('name', 'Provident Fund (PF)')->sum('amount'));

    // Format rupee
    function rs($n) { return 'Rs.' . number_format((float)$n, 2); }
@endphp
<style>
@page  { margin: 0; size: A4 portrait; }
*      { margin:0; padding:0; box-sizing:border-box; }
body   { font-family:'DejaVu Sans',Arial,sans-serif; font-size:8.5px; color:#1f2937; background:#fff; }

/* ─ TOP STRIP ─ */
.top-strip { height:5px; background:{{ $orange }}; width:100%; }

/* ─ HEADER ─ */
.hdr        { padding:16px 24px 14px; border-bottom:1px solid #f0f0f0; }
.hdr-tbl    { width:100%; border-collapse:collapse; }
.hdr-logo   { width:140px; vertical-align:middle; }
.hdr-center { vertical-align:middle; text-align:center; padding:0 10px; }
.hdr-right  { width:150px; text-align:right; vertical-align:middle; }

/* Logo */
.logo-main  { font-size:21px; font-weight:900; color:#111; letter-spacing:-0.5px; line-height:1; }
.logo-accent{ color:{{ $orange }}; }
.logo-sub   { font-size:6.5px; color:#9ca3af; letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
.logo-rule  { height:2px; background:{{ $orange }}; width:108px; margin-top:3px; }

/* Company */
.co-name    { font-size:14px; font-weight:800; color:#111; letter-spacing:0.2px; }
.co-addr    { font-size:7.5px; color:#6b7280; margin-top:3px; line-height:1.8; }
.co-cin     { font-size:7px; color:#9ca3af; margin-top:2px; }

/* Slip badge */
.slip-title { font-size:26px; font-weight:900; color:#111; letter-spacing:1px; line-height:1; }
.slip-month { font-size:14px; font-weight:800; color:{{ $orange }}; margin-top:3px; }
.cycle-tag  {
    display:inline-block; margin-top:7px;
    background:#fff7ed; border:1px solid #fed7aa;
    color:#9a3412; font-size:7px; font-weight:700;
    padding:3px 9px; border-radius:4px;
    letter-spacing:0.3px;
}

/* ─ EMPLOYEE CARD ─ */
.emp-card     { padding:14px 24px 12px; border-bottom:1px solid #f3f4f6; }
.emp-tbl      { width:100%; border-collapse:collapse; }
.emp-av-cell  { width:78px; vertical-align:middle; }
.emp-id-cell  { vertical-align:top; padding-left:14px; }
.emp-mid-cell { vertical-align:top; border-left:1px solid #f3f4f6; padding-left:16px; width:27%; }
.emp-rgt-cell { vertical-align:top; border-left:1px solid #f3f4f6; padding-left:16px; width:27%; }

/* Avatar */
.avatar {
    width:62px; height:62px; border-radius:50%;
    background:{{ $orange }}; text-align:center; line-height:62px;
    font-size:22px; font-weight:900; color:#fff; display:inline-block;
}
.emp-name { font-size:15px; font-weight:900; color:#111; line-height:1.2; }
.emp-role { font-size:9.5px; color:{{ $orange }}; font-weight:700; margin-top:3px; }
.emp-eid  { font-size:7.5px; color:#6b7280; margin-top:6px; font-weight:600; }

.fi       { margin-bottom:7px; }
.fi:last-child { margin-bottom:0; }
.fl       { font-size:7px; color:#9ca3af; text-transform:uppercase; letter-spacing:0.4px; display:block; line-height:1; }
.fv       { font-size:8.5px; font-weight:700; color:#111; display:block; margin-top:2px; }

/* ─ SALARY SUMMARY ─ */
.sum-wrap  { padding:12px 24px; border-bottom:1px solid #f3f4f6; background:#fafafa; }
.sum-tbl   { width:100%; border-collapse:separate; border-spacing:10px 0; }

.sum-card {
    border:1.5px solid #e5e7eb; background:#fff;
    border-radius:8px; padding:12px 16px;
    vertical-align:middle; width:33%;
}
.sum-card-net {
    border:1.5px solid #86efac; background:#f0fdf4;
    border-radius:8px; padding:12px 16px;
    vertical-align:middle; width:33%;
}

/* Icon circle */
.sum-icon {
    width:36px; height:36px; border-radius:50%;
    display:inline-block; text-align:center; line-height:36px;
    font-size:14px; font-weight:900; vertical-align:middle; margin-right:12px;
}
.sum-icon-earn { background:#fff7ed; color:{{ $orange }}; }
.sum-icon-ded  { background:#fef2f2; color:#ef4444; }
.sum-icon-net  { background:#dcfce7; color:{{ $green }}; }

.sum-content   { display:inline-block; vertical-align:middle; }
.sum-label     { font-size:7px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:#6b7280; }
.sum-amount    { font-size:17px; font-weight:900; color:#111; line-height:1.2; margin-top:2px; }
.sum-amt-net   { font-size:17px; font-weight:900; color:{{ $green }}; line-height:1.2; margin-top:2px; }
.sum-sub       { font-size:7px; color:#9ca3af; margin-top:2px; }

/* ─ MAIN TABLE ─ */
.main-wrap { padding:0 24px 10px; }
.main-tbl  { width:100%; border-collapse:collapse; margin-top:12px; table-layout:fixed; }

/* Column headers */
.earn-hdr {
    width:36%; font-size:8px; font-weight:900;
    text-transform:uppercase; letter-spacing:0.5px;
    color:{{ $orange }}; padding:7px 12px 6px;
    border-bottom:2.5px solid {{ $orange }};
    background:#fff7ed;
}
.earn-amt-hdr {
    width:14%; font-size:8px; font-weight:900;
    text-transform:uppercase; color:{{ $orange }};
    padding:7px 12px 6px; text-align:right;
    border-bottom:2.5px solid {{ $orange }};
    background:#fff7ed;
}
.ded-hdr {
    width:36%; font-size:8px; font-weight:900;
    text-transform:uppercase; letter-spacing:0.5px;
    color:{{ $orange }}; padding:7px 12px 6px;
    border-left:3px solid #f3f4f6;
    border-bottom:2.5px solid {{ $orange }};
    background:#fff7ed;
}
.ded-amt-hdr {
    width:14%; font-size:8px; font-weight:900;
    text-transform:uppercase; color:{{ $orange }};
    padding:7px 12px 6px; text-align:right;
    border-bottom:2.5px solid {{ $orange }};
    background:#fff7ed;
}

/* Body rows */
.earn-td  { padding:5.5px 12px; font-size:8.5px; color:#374151; border-bottom:1px solid #f9fafb; }
.amt-td   { padding:5.5px 12px; font-size:8.5px; text-align:right; font-weight:600; color:#111; border-bottom:1px solid #f9fafb; font-family:'DejaVu Sans Mono',monospace; }
.ded-td   { padding:5.5px 12px; font-size:8.5px; color:#374151; border-bottom:1px solid #f9fafb; border-left:3px solid #f3f4f6; }
.damt-td  { padding:5.5px 12px; font-size:8.5px; text-align:right; font-weight:600; color:#374151; border-bottom:1px solid #f9fafb; font-family:'DejaVu Sans Mono',monospace; }

/* Total rows */
.earn-tot { padding:7px 12px; font-size:9px; font-weight:900; color:{{ $orange }}; background:#fff7ed; border-top:2px solid #fed7aa; }
.amt-tot  { padding:7px 12px; font-size:9px; font-weight:900; color:{{ $orange }}; text-align:right; background:#fff7ed; border-top:2px solid #fed7aa; font-family:'DejaVu Sans Mono',monospace; }
.ded-tot  { padding:7px 12px; font-size:9px; font-weight:900; color:#dc2626; background:#fff7ed; border-left:3px solid #f3f4f6; border-top:2px solid #fed7aa; }
.damt-tot { padding:7px 12px; font-size:9px; font-weight:900; color:#dc2626; text-align:right; background:#fff7ed; border-top:2px solid #fed7aa; font-family:'DejaVu Sans Mono',monospace; }

/* ─ ATTENDANCE ─ */
.att-wrap  { padding:0 24px 10px; }
.att-title { font-size:9px; font-weight:900; text-transform:uppercase; letter-spacing:0.5px; color:#111; padding:8px 0 7px; border-top:1px solid #f3f4f6; }
.att-tbl   { width:100%; border-collapse:collapse; border:1px solid #e5e7eb; border-radius:8px; }
.att-td    { text-align:center; padding:13px 8px 11px; border-right:1px solid #e5e7eb; width:25%; }
.att-td:last-child { border-right:none; }

.att-badge {
    width:36px; height:36px; border-radius:50%;
    display:inline-block; text-align:center; line-height:36px;
    font-size:11px; font-weight:900; margin-bottom:5px;
}
.ab-blue   { background:#dbeafe; color:#1d4ed8; }
.ab-green  { background:#dcfce7; color:#15803d; }
.ab-purple { background:#ede9fe; color:#6d28d9; }
.ab-red    { background:#fff7ed; color:#c2410c; }

.att-lbl   { font-size:7px; text-transform:uppercase; letter-spacing:0.6px; color:#9ca3af; display:block; margin-bottom:2px; }
.att-val   { font-size:20px; font-weight:900; color:#111; display:block; line-height:1.1; }
.att-val-o { font-size:20px; font-weight:900; color:{{ $orange }}; display:block; line-height:1.1; }

/* ─ BOTTOM 3-COL ─ */
.bot-wrap  { padding:10px 24px 10px; border-top:1px solid #f3f4f6; }
.bot-tbl   { width:100%; border-collapse:collapse; }
.bot-c1 { vertical-align:top; width:32%; padding-right:16px; border-right:1px solid #f3f4f6; }
.bot-c2 { vertical-align:top; width:36%; padding:0 16px; border-right:1px solid #f3f4f6; }
.bot-c3 { vertical-align:top; width:32%; padding-left:16px; }

.bot-head { font-size:8.5px; font-weight:900; text-transform:uppercase; color:#111; letter-spacing:0.5px; margin-bottom:8px; display:block; }
.bot-ico  { display:inline-block; width:14px; height:14px; border-radius:50%; background:{{ $orange }}; color:#fff; text-align:center; line-height:14px; font-size:8px; font-weight:900; margin-right:5px; vertical-align:middle; }
.bot-ico-g { display:inline-block; width:14px; height:14px; border-radius:50%; background:#3b82f6; color:#fff; text-align:center; line-height:14px; font-size:8px; font-weight:900; margin-right:5px; vertical-align:middle; }
.bot-ico-i { display:inline-block; width:14px; height:14px; border-radius:50%; background:#8b5cf6; color:#fff; text-align:center; line-height:14px; font-size:8px; font-weight:900; margin-right:5px; vertical-align:middle; }

.bi-row    { display:table; width:100%; margin-bottom:5px; }
.bi-lbl    { display:table-cell; font-size:7.5px; color:#6b7280; width:46%; }
.bi-col    { display:table-cell; font-size:7.5px; color:#9ca3af; width:5%; }
.bi-val    { display:table-cell; font-size:8px; font-weight:700; color:#111; }
.bi-val-g  { display:table-cell; font-size:8px; font-weight:700; color:{{ $green }}; }

/* ─ FOOTER ─ */
.ftr-wrap  { padding:8px 24px 8px; border-top:1px solid #f3f4f6; }
.ftr-tbl   { width:100%; border-collapse:collapse; }
.ftr-qr    { vertical-align:middle; width:65px; }
.ftr-msg   { vertical-align:middle; padding-left:14px; width:220px; }
.ftr-sig   { vertical-align:top; text-align:right; }

.sal-box   { border:1.5px solid #86efac; background:#f0fdf4; border-radius:6px; padding:9px 14px; }
.sal-title { font-size:9px; font-weight:900; color:{{ $green }}; }
.sal-sub   { font-size:7.5px; color:#4b5563; margin-top:3px; line-height:1.7; }
.sal-check { font-size:13px; color:{{ $green }}; margin-right:4px; }

.sig-line  { border-top:1px solid #9ca3af; width:120px; margin-left:auto; margin-top:28px; }
.sig-note  { font-size:7px; color:#9ca3af; margin-top:4px; text-align:center; line-height:1.6; }

/* ─ BOTTOM BAR ─ */
.btm-bar   { background:{{ $orange }}; padding:9px 24px; }
.btm-tbl   { width:100%; border-collapse:collapse; }
.btm-cell  { text-align:center; font-size:8px; font-weight:700; color:#fff; padding:0 12px; border-right:1px solid rgba(255,255,255,0.35); }
.btm-cell:last-child { border-right:none; }
</style>
</head>
<body>

<div class="top-strip"></div>

{{-- ══ HEADER ══ --}}
<div class="hdr">
<table class="hdr-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="hdr-logo">
    <div class="logo-main">CON<span class="logo-accent">EX</span>US</div>
    <div class="logo-sub">Network Solutions</div>
    <div class="logo-rule"></div>
  </td>
  <td class="hdr-center">
    <div class="co-name">{{ $companyName }}</div>
    <div class="co-addr">
      {{ $companyAddress }}<br>
      CIN : {{ $companyCIN }} &nbsp;|&nbsp; {{ $companyWebsite }}
    </div>
  </td>
  <td class="hdr-right">
    <div class="slip-title">PAYSLIP</div>
    <div class="slip-month">{{ $monthLabel }}</div>
    <div class="cycle-tag">Payroll Cycle: {{ $cycleLabel }}</div>
  </td>
</tr>
</table>
</div>

{{-- ══ EMPLOYEE CARD ══ --}}
<div class="emp-card">
<table class="emp-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="emp-av-cell">
    <div class="avatar">{{ $initials }}</div>
  </td>
  <td class="emp-id-cell">
    <div class="emp-name">{{ $user->name }}</div>
    <div class="emp-role">{{ $employee->jobTitle?->name ?? 'Employee' }}</div>
    <div class="emp-eid">Employee ID: {{ $employee->employee_id ?? '—' }}</div>
  </td>
  <td class="emp-mid-cell">
    <div class="fi"><span class="fl">Department</span><span class="fv">{{ $employee->department?->name ?? '—' }}</span></div>
    <div class="fi"><span class="fl">Date of Joining</span><span class="fv">{{ $joiningLabel }}</span></div>
    <div class="fi"><span class="fl">Location</span><span class="fv">{{ $employee->office?->name ?? '—' }}</span></div>
    <div class="fi"><span class="fl">Gender</span><span class="fv">{{ ucfirst($employee->gender ?? '—') }}</span></div>
  </td>
  <td class="emp-rgt-cell">
    <div class="fi"><span class="fl">PAN</span><span class="fv">{{ $employee->pan_number ?? '—' }}</span></div>
    <div class="fi"><span class="fl">UAN</span><span class="fv">{{ $employee->uan_number ?? '—' }}</span></div>
    <div class="fi"><span class="fl">PF Account No</span><span class="fv">{{ $employee->pf_account ?? '—' }}</span></div>
    <div class="fi"><span class="fl">ESI Number</span><span class="fv">{{ $employee->esi_number ?? '—' }}</span></div>
  </td>
</tr>
</table>
</div>

{{-- ══ SALARY SUMMARY ══ --}}
<div class="sum-wrap">
<table class="sum-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="sum-card">
    <table cellpadding="0" cellspacing="0" style="width:100%;"><tr>
      <td style="width:50px; vertical-align:middle;">
        <div class="sum-icon sum-icon-earn">E</div>
      </td>
      <td style="vertical-align:middle;">
        <div class="sum-label">Gross Salary</div>
        <div class="sum-amount">Rs.{{ number_format($payslip->gross_salary, 2) }}</div>
        <div class="sum-sub">Total Earnings (A)</div>
      </td>
    </tr></table>
  </td>
  <td class="sum-card">
    <table cellpadding="0" cellspacing="0" style="width:100%;"><tr>
      <td style="width:50px; vertical-align:middle;">
        <div class="sum-icon sum-icon-ded">D</div>
      </td>
      <td style="vertical-align:middle;">
        <div class="sum-label">Total Deductions</div>
        <div class="sum-amount" style="color:#dc2626;">Rs.{{ number_format($payslip->total_deductions, 2) }}</div>
        <div class="sum-sub">Total Deductions (B)</div>
      </td>
    </tr></table>
  </td>
  <td class="sum-card-net">
    <table cellpadding="0" cellspacing="0" style="width:100%;"><tr>
      <td style="width:50px; vertical-align:middle;">
        <div class="sum-icon sum-icon-net">N</div>
      </td>
      <td style="vertical-align:middle;">
        <div class="sum-label" style="color:#15803d;">Net Pay</div>
        <div class="sum-amt-net">Rs.{{ number_format($payslip->net_salary, 2) }}</div>
        <div class="sum-sub">(A - B) Net Payable</div>
      </td>
    </tr></table>
  </td>
</tr>
</table>
</div>

{{-- ══ EARNINGS & DEDUCTIONS TABLE ══ --}}
<div class="main-wrap">
<table class="main-tbl" cellpadding="0" cellspacing="0">
  <thead>
    <tr>
      <th class="earn-hdr">Earnings &amp; Reimbursements</th>
      <th class="earn-amt-hdr">Amount (Rs.)</th>
      <th class="ded-hdr">Deductions &amp; Recoveries</th>
      <th class="ded-amt-hdr">Amount (Rs.)</th>
    </tr>
  </thead>
  <tbody>
    @for($i = 0; $i < $maxRows; $i++)
      @php $e = $earnings->get($i); $d = $deductions->get($i); @endphp
      <tr>
        <td class="earn-td">{{ $e?->name ?? '' }}</td>
        <td class="amt-td">{{ $e ? number_format($e->amount, 2) : '' }}</td>
        <td class="ded-td">{{ $d?->name ?? '' }}</td>
        <td class="damt-td">{{ $d ? number_format($d->amount, 2) : '' }}</td>
      </tr>
    @endfor
  </tbody>
  <tfoot>
    <tr>
      <td class="earn-tot">Total Earnings (A)</td>
      <td class="amt-tot">{{ number_format($payslip->gross_salary, 2) }}</td>
      <td class="ded-tot">Total Deductions (B)</td>
      <td class="damt-tot">{{ number_format($payslip->total_deductions, 2) }}</td>
    </tr>
  </tfoot>
</table>
</div>

{{-- ══ ATTENDANCE SUMMARY ══ --}}
<div class="att-wrap">
  <div class="att-title">Attendance Summary</div>
  <table class="att-tbl" cellpadding="0" cellspacing="0">
    <tr>
      <td class="att-td">
        <div class="att-badge ab-blue">31</div>
        <span class="att-lbl">Paid Days</span>
        <span class="att-val">{{ $paidDays }}</span>
      </td>
      <td class="att-td">
        <div class="att-badge ab-green">P</div>
        <span class="att-lbl">Present Days</span>
        <span class="att-val">{{ $presentDays }}</span>
      </td>
      <td class="att-td">
        <div class="att-badge ab-purple">W</div>
        <span class="att-lbl">Week Off</span>
        <span class="att-val">{{ $weekOff }}</span>
      </td>
      <td class="att-td">
        <div class="att-badge ab-red">L</div>
        <span class="att-lbl">LWP / ABS</span>
        <span class="{{ $lwp > 0 ? 'att-val-o' : 'att-val' }}">{{ number_format($lwp, 2) }}</span>
      </td>
    </tr>
  </table>
</div>

{{-- ══ BOTTOM 3-COL ══ --}}
<div class="bot-wrap">
<table class="bot-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="bot-c1">
    <span class="bot-head"><span class="bot-ico">B</span> Bank Details</span>
    <div class="bi-row"><span class="bi-lbl">Bank Name</span><span class="bi-col">:</span><span class="bi-val">{{ $employee->bank_name ?? 'HDFC Bank' }}</span></div>
    <div class="bi-row"><span class="bi-lbl">Account No</span><span class="bi-col">:</span><span class="bi-val">{{ $employee->bank_account ? '****' . substr($employee->bank_account, -4) : '—' }}</span></div>
    <div class="bi-row"><span class="bi-lbl">IFSC Code</span><span class="bi-col">:</span><span class="bi-val">{{ $employee->ifsc_code ?? '—' }}</span></div>
    <div class="bi-row"><span class="bi-lbl">Account Type</span><span class="bi-col">:</span><span class="bi-val">Savings</span></div>
  </td>
  <td class="bot-c2">
    <span class="bot-head"><span class="bot-ico-g">T</span> Tax Details (FY {{ $payroll->year }}-{{ substr($payroll->year + 1, -2) }})</span>
    <div class="bi-row"><span class="bi-lbl">YTD Gross Salary</span><span class="bi-col">:</span><span class="bi-val-g">Rs.{{ number_format($ytdGross, 2) }}</span></div>
    <div class="bi-row"><span class="bi-lbl">YTD Taxable Salary</span><span class="bi-col">:</span><span class="bi-val-g">Rs.{{ number_format($ytdTaxable, 2) }}</span></div>
    <div class="bi-row"><span class="bi-lbl">YTD Tax Paid</span><span class="bi-col">:</span><span class="bi-val-g">Rs.{{ number_format($ytdTax, 2) }}</span></div>
    <div class="bi-row"><span class="bi-lbl">YTD Deductions</span><span class="bi-col">:</span><span class="bi-val-g">Rs.{{ number_format($ytdDeductions, 2) }}</span></div>
  </td>
  <td class="bot-c3">
    <span class="bot-head"><span class="bot-ico-i">i</span> Other Information</span>
    <div class="bi-row"><span class="bi-lbl">Working Days</span><span class="bi-col">:</span><span class="bi-val">{{ $totalDays }}</span></div>
    <div class="bi-row"><span class="bi-lbl">Payroll Days</span><span class="bi-col">:</span><span class="bi-val">{{ $paidDays }}</span></div>
    <div class="bi-row"><span class="bi-lbl">Payment Date</span><span class="bi-col">:</span><span class="bi-val">{{ $paymentDate }}</span></div>
    <div class="bi-row"><span class="bi-lbl">Payment Mode</span><span class="bi-col">:</span><span class="bi-val">Bank Transfer</span></div>
  </td>
</tr>
</table>
</div>

{{-- ══ FOOTER ══ --}}
<div class="ftr-wrap">
<table class="ftr-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="ftr-qr">
    @php
      $qr = ['11101111','10100101','11101011','00010100','11101101','10100001','11101110'];
    @endphp
    <table cellpadding="0" cellspacing="0" style="border:2px solid #374151; border-radius:4px; background:#fff;">
    @foreach($qr as $row)
      <tr>@foreach(str_split($row) as $b)<td style="width:6px;height:6px;padding:0;background:{{ $b==='1'?'#1f2937':'#fff' }};"></td>@endforeach</tr>
    @endforeach
    </table>
  </td>
  <td class="ftr-msg">
    <div class="sal-box">
      <div class="sal-title"><span class="sal-check">&#10003;</span> SALARY CREDITED</div>
      <div class="sal-sub">Your salary for {{ $monthLabel }} has been<br>credited to your bank account.</div>
    </div>
  </td>
  <td class="ftr-sig">
    <div class="sig-line"></div>
    <div class="sig-note">This is a system generated payslip.<br>No signature is required.</div>
  </td>
</tr>
</table>
</div>

{{-- ══ BOTTOM BAR ══ --}}
<div class="btm-bar">
<table class="btm-tbl" cellpadding="0" cellspacing="0">
<tr>
  <td class="btm-cell">{{ $companyEmail }}</td>
  <td class="btm-cell">{{ $companyPhone }}</td>
  <td class="btm-cell" style="border-right:none;">{{ $companyWebsite }}</td>
</tr>
</table>
</div>

</body>
</html>
