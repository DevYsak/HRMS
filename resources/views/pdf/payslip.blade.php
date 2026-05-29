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

    $orange = '#f97316';
    $green  = '#16a34a';

    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;
    $user     = $employee->user;

    // Avatar initials
    $parts    = explode(' ', trim($user->name ?? 'U'));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));

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
    $leaveDays   = 0;
    LeaveRequest::where('employee_id', $employee->id)
        ->where('status', 'approved')
        ->where('start_date', '<=', $to->toDateString())
        ->where('end_date', '>=', $from->toDateString())
        ->get()->each(function ($lr) use ($from, $to, &$leaveDays) {
            $s = Carbon::parse($lr->start_date)->max($from);
            $e = Carbon::parse($lr->end_date)->min($to);
            if ($s->lte($e)) {
                $leaveDays += (int) ($s->diffInDays($e) + 1);
            }
        });
    $lwp = ($presentDays > 0 || $leaveDays > 0) ? max(0, $paidDays - $presentDays - $leaveDays) : 0;

    // Items
    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count(), 4);

    // Labels
    $monthLabel   = $payroll->month . ' ' . $payroll->year;
    $cycleLabel   = $payroll->month . ' ' . $payroll->year . ' - Cycle ' . strtoupper(str_replace('cycle_', '', $payroll->cycle ?? 'a'));
    $joiningLabel = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('F Y') : '-';
    $paymentDate  = $to->copy()->addDay()->format('d M Y');

    // Company — updated contact details
    $coName  = 'Conexus Network Solutions Pvt Ltd';
    $coAddr  = '709, 7th Level, Wing F, Tower II Seawoods Grand Central, Seawoods Railway Station, Nerul, Navi Mumbai - 400706';
    $coCIN   = 'U72900MH2013PTC234567';
    $coPhone = '+91 959 458 6666';
    $coEmail = 'info@conexus-ns.com';
    $coWeb   = 'www.conexus-ns.com';

    // YTD
    $ytdSlips = \App\Models\Payslip::where('employee_id', $employee->id)
        ->where('status', 'paid')
        ->whereHas('payroll', fn ($q) => $q->where('year', $payroll->year))
        ->with('items')->get();
    $ytdGross = $ytdSlips->sum('gross_salary');
    $ytdDed   = $ytdSlips->sum('total_deductions');
    $ytdTax   = $ytdSlips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
    $ytdTaxbl = max(0, $ytdGross - $ytdSlips->flatMap->items->where('name', 'Provident Fund (PF)')->sum('amount'));

    function fmt($n) { return 'Rs.' . number_format((float) $n, 2); }
@endphp
<style>
@page  { margin: 14px 20px; size: A4 portrait; }
*      { margin: 0; padding: 0; box-sizing: border-box; }
body   { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #1f2937; background: #fff; line-height: 1.5; }

/* ── TOP BAR ── */
.top { height: 5px; background: {{ $orange }}; margin: -14px -20px 0; }

/* ── HEADER ── */
.hdr     { padding: 14px 0 12px; border-bottom: 1.5px solid #e5e7eb; }
.hdr-tbl { width: 100%; border-collapse: collapse; }
.h-logo  { width: 145px; vertical-align: middle; }
.h-co    { vertical-align: middle; text-align: center; padding: 0 10px; }
.h-slip  { width: 155px; text-align: right; vertical-align: middle; }

/* Logo */
.lg-main { font-size: 22px; font-weight: 900; color: #111; letter-spacing: -0.5px; line-height: 1; }
.lg-ex   { color: {{ $orange }}; }
.lg-sub  { font-size: 7px; color: #9ca3af; letter-spacing: 2.5px; text-transform: uppercase; margin-top: 2px; }
.lg-rule { height: 2.5px; background: {{ $orange }}; width: 110px; margin-top: 3px; border-radius: 2px; }

.co-name { font-size: 13px; font-weight: 800; color: #111; line-height: 1; }
.co-addr { font-size: 8px; color: #6b7280; margin-top: 3px; line-height: 1.7; }
.co-cin  { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

.slip-title { font-size: 26px; font-weight: 900; color: #111; letter-spacing: 1px; line-height: 1; }
.slip-month { font-size: 14px; font-weight: 800; color: {{ $orange }}; margin-top: 3px; }
.cyc-tag    {
    display: inline-block; margin-top: 7px;
    background: #fff7ed; border: 1px solid #fed7aa;
    color: #9a3412; font-size: 7.5px; font-weight: 700;
    padding: 3px 9px; border-radius: 4px; letter-spacing: 0.2px;
}

/* ── EMPLOYEE ── */
.emp     { padding: 12px 0 11px; border-bottom: 1.5px solid #e5e7eb; }
.emp-tbl { width: 100%; border-collapse: collapse; }
.ea      { width: 74px; vertical-align: middle; }
.en      { vertical-align: top; padding-left: 12px; }
.em      { vertical-align: top; border-left: 1px solid #f3f4f6; padding-left: 14px; width: 27%; }
.er      { vertical-align: top; border-left: 1px solid #f3f4f6; padding-left: 14px; width: 27%; }

.av-circle {
    width: 60px; height: 60px; border-radius: 50%;
    background: {{ $orange }}; text-align: center; line-height: 60px;
    font-size: 22px; font-weight: 900; color: #fff; display: inline-block;
}
.e-name { font-size: 16px; font-weight: 900; color: #111; line-height: 1.2; }
.e-role { font-size: 10px; color: {{ $orange }}; font-weight: 700; margin-top: 2px; }
.e-eid  { font-size: 8px; color: #6b7280; margin-top: 6px; font-weight: 600; }

.fi       { margin-bottom: 7px; }
.fi:last-child { margin-bottom: 0; }
.fl       { font-size: 7.5px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 1px; }
.fv       { font-size: 9px; font-weight: 700; color: #111; display: block; }

/* ── SALARY SUMMARY ── */
.sum-bg  { background: #f9fafb; padding: 12px 0; border-bottom: 1.5px solid #e5e7eb; }
.sum-tbl { width: 100%; border-collapse: separate; border-spacing: 10px 0; }

.sc {
    border: 1.5px solid #e5e7eb; background: #fff;
    border-radius: 10px; padding: 13px 14px;
    vertical-align: middle; width: 33%;
}
.sc-net {
    border: 1.5px solid #86efac; background: #f0fdf4;
    border-radius: 10px; padding: 13px 14px;
    vertical-align: middle; width: 33%;
}
.sc-ico {
    width: 38px; height: 38px; border-radius: 50%;
    text-align: center; line-height: 38px;
    font-size: 14px; font-weight: 900;
    display: inline-block; vertical-align: middle;
}
.ico-e   { background: #fff7ed; color: {{ $orange }}; }
.ico-d   { background: #fef2f2; color: #ef4444; }
.ico-n   { background: #dcfce7; color: {{ $green }}; }
.sc-body { display: inline-block; vertical-align: middle; padding-left: 11px; }
.sc-lbl  { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #6b7280; }
.sc-amt  { font-size: 17px; font-weight: 900; color: #111; line-height: 1.25; margin-top: 2px; }
.sc-amt-d { font-size: 17px; font-weight: 900; color: #dc2626; line-height: 1.25; margin-top: 2px; }
.sc-amt-n { font-size: 17px; font-weight: 900; color: {{ $green }}; line-height: 1.25; margin-top: 2px; }
.sc-sub  { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

/* ── EARNINGS TABLE ── */
.tbl-wrap { padding: 2px 0 10px; }
.sal-tbl  { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; border: 1px solid #e5e7eb; }

.eth  { width: 36%; font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
        color: {{ $orange }}; padding: 8px 12px 7px; border-bottom: 2px solid {{ $orange }};
        background: #fff7ed; text-align: left; }
.ath  { width: 14%; font-size: 8px; font-weight: 900; text-transform: uppercase;
        color: {{ $orange }}; padding: 8px 12px 7px; border-bottom: 2px solid {{ $orange }};
        background: #fff7ed; text-align: right; }
.dth  { width: 36%; font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
        color: {{ $orange }}; padding: 8px 12px 7px; border-bottom: 2px solid {{ $orange }};
        background: #fff7ed; text-align: left; border-left: 2px solid #e5e7eb; }
.xth  { width: 14%; font-size: 8px; font-weight: 900; text-transform: uppercase;
        color: {{ $orange }}; padding: 8px 12px 7px; border-bottom: 2px solid {{ $orange }};
        background: #fff7ed; text-align: right; }

.etd { padding: 6px 12px; font-size: 9.5px; color: #374151; border-bottom: 1px solid #f9fafb; }
.atd { padding: 6px 12px; font-size: 9.5px; text-align: right; font-weight: 600; color: #111;
       border-bottom: 1px solid #f9fafb; }
.dtd { padding: 6px 12px; font-size: 9.5px; color: #374151; border-bottom: 1px solid #f9fafb;
       border-left: 2px solid #f3f4f6; }
.xtd { padding: 6px 12px; font-size: 9.5px; text-align: right; font-weight: 600; color: #374151;
       border-bottom: 1px solid #f9fafb; }

.tot-e { padding: 8px 12px; font-size: 10px; font-weight: 900; color: {{ $orange }};
         background: #fff7ed; border-top: 2px solid #fed7aa; }
.tot-a { padding: 8px 12px; font-size: 10px; font-weight: 900; color: {{ $orange }};
         text-align: right; background: #fff7ed; border-top: 2px solid #fed7aa; }
.tot-d { padding: 8px 12px; font-size: 10px; font-weight: 900; color: #dc2626;
         background: #fff7ed; border-left: 2px solid #e5e7eb; border-top: 2px solid #fed7aa; }
.tot-x { padding: 8px 12px; font-size: 10px; font-weight: 900; color: #dc2626;
         text-align: right; background: #fff7ed; border-top: 2px solid #fed7aa; }

/* ── ATTENDANCE ── */
.att-wrap { padding: 0 0 10px; }
.att-head { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
            color: #111; padding: 10px 0 8px; border-top: 1.5px solid #e5e7eb; }
.att-tbl  { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; }
.att-td   { text-align: center; padding: 14px 6px 12px; border-right: 1px solid #e5e7eb; width: 25%; }
.att-td:last-child { border-right: none; }
.att-icon-box {
    width: 40px; height: 40px; border-radius: 10px;
    display: inline-block; text-align: center; line-height: 40px;
    font-size: 13px; font-weight: 900; margin-bottom: 6px;
}
.aib-blue   { background: #eff6ff; color: #1d4ed8; }
.aib-green  { background: #f0fdf4; color: #15803d; }
.aib-purple { background: #f5f3ff; color: #6d28d9; }
.aib-orange { background: #fff7ed; color: {{ $orange }}; }
.att-lbl { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; display: block; margin-bottom: 3px; }
.att-val { font-size: 22px; font-weight: 900; color: #111; display: block; line-height: 1; }
.att-val-o { font-size: 22px; font-weight: 900; color: {{ $orange }}; display: block; line-height: 1; }

/* ── BOTTOM 3-COL ── */
.bot-wrap { padding: 10px 0 10px; border-top: 1.5px solid #e5e7eb; }
.bot-tbl  { width: 100%; border-collapse: collapse; }
.bc1 { vertical-align: top; width: 31%; padding-right: 16px; border-right: 1px solid #f3f4f6; }
.bc2 { vertical-align: top; width: 38%; padding: 0 16px; border-right: 1px solid #f3f4f6; }
.bc3 { vertical-align: top; width: 31%; padding-left: 16px; }

.bh-wrap { margin-bottom: 10px; display: table; }
.bh-ico  { display: table-cell; vertical-align: middle; width: 22px; }
.bh-pill {
    width: 20px; height: 20px; border-radius: 50%;
    text-align: center; line-height: 20px; font-size: 9px;
    font-weight: 900; color: #fff; display: inline-block;
}
.bip-o { background: {{ $orange }}; }
.bip-b { background: #3b82f6; }
.bip-v { background: #8b5cf6; }
.bh-txt  { display: table-cell; vertical-align: middle; padding-left: 6px;
           font-size: 9px; font-weight: 900; text-transform: uppercase;
           letter-spacing: 0.5px; color: #111; }

.br  { display: table; width: 100%; margin-bottom: 6px; }
.brl { display: table-cell; font-size: 8.5px; color: #6b7280; width: 46%; vertical-align: top; }
.brc { display: table-cell; font-size: 8.5px; color: #d1d5db; width: 5%; }
.brv { display: table-cell; font-size: 9px; font-weight: 700; color: #111; }
.brv-g { display: table-cell; font-size: 9px; font-weight: 700; color: {{ $green }}; }

/* ── STATUS BANNER ── */
.sal-wrap { padding: 10px 0 10px; border-top: 1.5px solid #e5e7eb; }
.sal-tbl  { width: 100%; border-collapse: collapse; }
.sal-box-td { width: 55%; vertical-align: middle; }
.sal-note-td { vertical-align: middle; text-align: right; }

.sal-box {
    border: 1.5px solid #86efac; background: #f0fdf4;
    border-radius: 10px; padding: 12px 16px;
    display: table; width: 95%;
}
.sal-ico-td  { display: table-cell; width: 40px; vertical-align: middle; }
.sal-ico-c   {
    width: 34px; height: 34px; border-radius: 50%;
    border: 2.5px solid {{ $green }}; text-align: center;
    line-height: 30px; font-size: 15px; font-weight: 900;
    color: {{ $green }}; display: inline-block;
}
.sal-txt-td  { display: table-cell; vertical-align: middle; padding-left: 10px; }
.sal-title   { font-size: 10px; font-weight: 900; color: {{ $green }}; letter-spacing: 0.3px; }
.sal-sub     { font-size: 8.5px; color: #374151; margin-top: 3px; line-height: 1.6; }
.sys-note    { font-size: 8.5px; color: #6b7280; line-height: 1.8; font-style: italic; }

/* ── BOTTOM BAR ── */
.btm     { background: {{ $orange }}; padding: 9px 0; margin: 8px -20px -14px; }
.btm-tbl { width: 100%; border-collapse: collapse; }
.btm-td  { text-align: center; padding: 0 10px;
           border-right: 1px solid rgba(255,255,255,0.3); }
.btm-td:last-child { border-right: none; }
.btm-lbl { font-size: 7px; color: rgba(255,255,255,0.75); text-transform: uppercase;
           letter-spacing: 0.8px; display: block; margin-bottom: 1px; }
.btm-val { font-size: 8.5px; font-weight: 700; color: #fff; }
</style>
</head>
<body>

<div class="top"></div>

{{-- HEADER --}}
<div class="hdr">
<table class="hdr-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="h-logo">
        <div class="lg-main">CON<span class="lg-ex">EX</span>US</div>
        <div class="lg-sub">Network Solutions</div>
        <div class="lg-rule"></div>
    </td>
    <td class="h-co">
        <div class="co-name">{{ $coName }}</div>
        <div class="co-addr">{{ $coAddr }}</div>
        <div class="co-cin">CIN: {{ $coCIN }}&nbsp;&nbsp;|&nbsp;&nbsp;{{ $coWeb }}</div>
    </td>
    <td class="h-slip">
        <div class="slip-title">PAYSLIP</div>
        <div class="slip-month">{{ $monthLabel }}</div>
        <div class="cyc-tag">Payroll Cycle: {{ $cycleLabel }}</div>
    </td>
</tr>
</table>
</div>

{{-- EMPLOYEE --}}
<div class="emp">
<table class="emp-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="ea"><div class="av-circle">{{ $initials }}</div></td>
    <td class="en">
        <div class="e-name">{{ $user->name }}</div>
        <div class="e-role">{{ $employee->jobTitle?->name ?? 'Employee' }}</div>
        <div class="e-eid">Employee ID: {{ $employee->employee_id ?? '-' }}</div>
    </td>
    <td class="em">
        <div class="fi"><span class="fl">Department</span><span class="fv">{{ $employee->department?->name ?? '-' }}</span></div>
        <div class="fi"><span class="fl">Date of Joining</span><span class="fv">{{ $joiningLabel }}</span></div>
        <div class="fi"><span class="fl">Location</span><span class="fv">{{ $employee->office?->name ?? '-' }}</span></div>
        <div class="fi"><span class="fl">Gender</span><span class="fv">{{ ucfirst($employee->gender ?? '-') }}</span></div>
    </td>
    <td class="er">
        <div class="fi"><span class="fl">PAN</span><span class="fv">{{ $employee->pan_number ?? '-' }}</span></div>
        <div class="fi"><span class="fl">UAN</span><span class="fv">{{ $employee->uan_number ?? '-' }}</span></div>
        <div class="fi"><span class="fl">PF Account No</span><span class="fv">{{ $employee->pf_account ?? '-' }}</span></div>
        <div class="fi"><span class="fl">ESI Number</span><span class="fv">{{ $employee->esi_number ?? '-' }}</span></div>
    </td>
</tr>
</table>
</div>

{{-- SALARY SUMMARY --}}
<div class="sum-bg">
<table class="sum-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="sc">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico ico-e">E</div></td>
            <td class="sc-body">
                <div class="sc-lbl">Gross Salary</div>
                <div class="sc-amt">Rs.{{ number_format($payslip->gross_salary, 2) }}</div>
                <div class="sc-sub">Total Earnings (A)</div>
            </td>
        </tr></table>
    </td>
    <td class="sc">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico ico-d">D</div></td>
            <td class="sc-body">
                <div class="sc-lbl">Total Deductions</div>
                <div class="sc-amt-d">Rs.{{ number_format($payslip->total_deductions, 2) }}</div>
                <div class="sc-sub">Total Deductions (B)</div>
            </td>
        </tr></table>
    </td>
    <td class="sc-net">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico ico-n">N</div></td>
            <td class="sc-body">
                <div class="sc-lbl" style="color:#15803d;">Net Pay</div>
                <div class="sc-amt-n">Rs.{{ number_format($payslip->net_salary, 2) }}</div>
                <div class="sc-sub">(A - B) Net Payable</div>
            </td>
        </tr></table>
    </td>
</tr>
</table>
</div>

{{-- EARNINGS & DEDUCTIONS --}}
<div class="tbl-wrap">
<table class="sal-tbl" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <th class="eth">Earnings &amp; Reimbursements</th>
            <th class="ath">Amount (Rs.)</th>
            <th class="dth">Deductions &amp; Recoveries</th>
            <th class="xth">Amount (Rs.)</th>
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

{{-- ATTENDANCE --}}
<div class="att-wrap">
    <div class="att-head">Attendance Summary</div>
    <table class="att-tbl" cellpadding="0" cellspacing="0">
    <tr>
        <td class="att-td">
            <div class="att-icon-box aib-blue">{{ $paidDays }}</div>
            <span class="att-lbl">Paid Days</span>
            <span class="att-val">{{ $paidDays }}</span>
        </td>
        <td class="att-td">
            <div class="att-icon-box aib-green">P</div>
            <span class="att-lbl">Present Days</span>
            <span class="att-val">{{ $presentDays }}</span>
        </td>
        <td class="att-td">
            <div class="att-icon-box aib-purple">W</div>
            <span class="att-lbl">Week Off</span>
            <span class="att-val">{{ $weekOff }}</span>
        </td>
        <td class="att-td">
            <div class="att-icon-box aib-orange">A</div>
            <span class="att-lbl">LWP / ABS</span>
            <span class="{{ $lwp > 0 ? 'att-val-o' : 'att-val' }}">{{ number_format($lwp, 2) }}</span>
        </td>
    </tr>
    </table>
</div>

{{-- BOTTOM 3-COL --}}
<div class="bot-wrap">
<table class="bot-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="bc1">
        <div class="bh-wrap">
            <span class="bh-ico"><div class="bh-pill bip-o">B</div></span>
            <span class="bh-txt">Bank Details</span>
        </div>
        <div class="br"><span class="brl">Bank Name</span><span class="brc">:</span><span class="brv">{{ $employee->bank_name ?? 'HDFC Bank' }}</span></div>
        <div class="br"><span class="brl">Account No</span><span class="brc">:</span><span class="brv">{{ $employee->bank_account ? '5020 **** **** ' . substr($employee->bank_account, -4) : '-' }}</span></div>
        <div class="br"><span class="brl">IFSC Code</span><span class="brc">:</span><span class="brv">{{ $employee->ifsc_code ?? '-' }}</span></div>
        <div class="br"><span class="brl">Account Type</span><span class="brc">:</span><span class="brv">Savings</span></div>
    </td>
    <td class="bc2">
        <div class="bh-wrap">
            <span class="bh-ico"><div class="bh-pill bip-b">T</div></span>
            <span class="bh-txt">Tax Details (FY {{ $payroll->year }}-{{ substr($payroll->year + 1, -2) }})</span>
        </div>
        <div class="br"><span class="brl">YTD Gross Salary</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdGross, 2) }}</span></div>
        <div class="br"><span class="brl">YTD Taxable Salary</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdTaxbl, 2) }}</span></div>
        <div class="br"><span class="brl">YTD Tax Paid</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdTax, 2) }}</span></div>
        <div class="br"><span class="brl">YTD Deductions</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdDed, 2) }}</span></div>
    </td>
    <td class="bc3">
        <div class="bh-wrap">
            <span class="bh-ico"><div class="bh-pill bip-v">i</div></span>
            <span class="bh-txt">Other Information</span>
        </div>
        <div class="br"><span class="brl">Working Days</span><span class="brc">:</span><span class="brv">{{ $totalDays }}</span></div>
        <div class="br"><span class="brl">Payroll Days</span><span class="brc">:</span><span class="brv">{{ $presentDays }}</span></div>
        <div class="br"><span class="brl">Payment Date</span><span class="brc">:</span><span class="brv">{{ $paymentDate }}</span></div>
        <div class="br"><span class="brl">Payment Mode</span><span class="brc">:</span><span class="brv">Bank Transfer</span></div>
    </td>
</tr>
</table>
</div>

{{-- SALARY CREDITED BANNER --}}
<div class="sal-wrap">
<table class="sal-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="sal-box-td">
        <div class="sal-box">
            <div class="sal-ico-td"><div class="sal-ico-c">V</div></div>
            <div class="sal-txt-td">
                <div class="sal-title">SALARY CREDITED</div>
                <div class="sal-sub">Your salary for {{ $monthLabel }} has been credited to your bank account.</div>
            </div>
        </div>
    </td>
    <td class="sal-note-td">
        <div class="sys-note">This is a system generated payslip.<br>No signature is required.</div>
    </td>
</tr>
</table>
</div>

{{-- BOTTOM BAR --}}
<div class="btm">
<table class="btm-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="btm-td">
        <span class="btm-lbl">Email</span>
        <span class="btm-val">{{ $coEmail }}</span>
    </td>
    <td class="btm-td">
        <span class="btm-lbl">Phone</span>
        <span class="btm-val">{{ $coPhone }}</span>
    </td>
    <td class="btm-td" style="border-right:none;">
        <span class="btm-lbl">Website</span>
        <span class="btm-val">{{ $coWeb }}</span>
    </td>
</tr>
</table>
</div>

</body>
</html>
