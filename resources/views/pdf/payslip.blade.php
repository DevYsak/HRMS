<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Payslip - {{ $payslip->employee->user->name ?? '' }}</title>
@php
    use Carbon\Carbon;
    use App\Models\Attendance;
    use App\Models\LeaveRequest;

    $company  = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Conexus Network Solutions Pvt Ltd']);

    $orange   = '#fe9a00';
    $darkRed  = '#881819';
    $brand    = $orange;
    $curr     = 'Rs.';

    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;
    $user     = $employee->user;

    // Avatar initials
    $nameParts = explode(' ', $user->name ?? 'U');
    $initials  = strtoupper(
        (substr($nameParts[0], 0, 1) ?? '') .
        (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : '')
    );

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
        ->get()->each(function($lr) use ($from, $to, &$leaveDays) {
            $s = Carbon::parse($lr->start_date)->max($from);
            $e = Carbon::parse($lr->end_date)->min($to);
            if ($s->lte($e)) $leaveDays += (int)($s->diffInDays($e) + 1);
        });
    $lwp = max(0, $paidDays - $presentDays - $leaveDays);

    $earnings   = $payslip->items->where('type','earning')->values();
    $deductions = $payslip->items->where('type','deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count(), 4);

    $monthLabel   = $payroll->month . ' ' . $payroll->year;
    $cycleLabel   = $payroll->month . ' ' . $payroll->year . ' - ' . strtoupper(str_replace('_', ' ', $payroll->cycle ?? 'Cycle A'));
    $joiningLabel = $employee->joining_date
        ? Carbon::parse($employee->joining_date)->format('F Y') : '—';
    $paymentDate  = $to->copy()->addDay()->format('d M Y');

    // YTD calculations
    $ytdPayslips = \App\Models\Payslip::where('employee_id', $employee->id)
        ->where('status', 'paid')
        ->whereHas('payroll', fn($q) => $q->where('year', $payroll->year))
        ->with('items')->get();
    $ytdGross      = $ytdPayslips->sum('gross_salary');
    $ytdDeductions = $ytdPayslips->sum('total_deductions');
    $ytdTax        = $ytdPayslips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
    $ytdTaxable    = max(0, $ytdGross - $ytdPayslips->flatMap->items->where('name','Provident Fund (PF)')->sum('amount'));

    // Conexus logo SVG as base64
    $logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="54" viewBox="0 0 140 54" fill="none"><g clip-path="url(#a)"><path d="M18.894 33.599c-1.776 2.396-4.33 4.141-8.092 4.141-6.115 0-10.925-4.36-10.925-10.743S4.687 16.26 10.802 16.26c3.762 0 6.373 1.742 8.063 4.108l-2.2 1.212a7.7 7.7 0 0 0-2.778-2.031 7.74 7.74 0 0 0-3.085-.639c-4.65 0-8.187 3.457-8.187 8.44 0 4.92 3.552 8.44 8.187 8.44a7.76 7.76 0 0 0 3.086-.607 7.73 7.73 0 0 0 2.802-2.026l2.204 1.202Z" fill="#000"/><path d="M42.567 27c0 6.131-4.301 10.739-10.579 10.739S21.457 33.13 21.457 27c0-6.131 4.268-10.742 10.543-10.742S42.567 20.864 42.567 27Zm-2.741 0c0-4.847-3.09-8.437-7.838-8.437-4.748 0-7.805 3.578-7.805 8.437 0 4.86 3.028 8.447 7.805 8.447 4.777 0 7.838-3.62 7.838-8.447Z" fill="#000"/><path d="M62.705 16.616v20.767H60.156L47.763 20.818v16.565H45.117V16.616h2.709l12.236 16.22V16.616h2.643Z" fill="#000"/><path d="M79.175 16.616v2.302H67.898v4.436H65.252v-6.738h13.923Z" fill="#000"/><path d="m78.953 27.92-13.701.003v-2.311l13.701.003v2.305Z" fill="#7E1F24"/><path d="M67.898 35.078h11.277v2.305H65.252V30.18h2.646v4.898Z" fill="#000"/><path d="m90.071 27.089-8.329-10.57h3.368l6.63 8.349" fill="#7E1F24"/><path d="m94.932 28.507 6.734 8.867-3.35.009-4.988-6.68" fill="#000"/><path d="m93.389 22.672 4.88-6.153h3.398l-6.589 8.283-1.69-2.13Z" fill="#000"/><path d="m91.7 24.866 1.628 1.948-8.255 10.57-3.35-.01L91.7 24.866Z" fill="#7E1F24"/><path d="M104.219 25.857V16.6h2.664v9.257h-2.664Zm14.687 1.954h2.664v1.46c0 5.171-2.931 8.482-8.699 8.482s-8.664-3.332-8.664-8.437v-1.505h2.664v1.43c0 3.8 2.072 6.196 6 6.196s6.007-2.396 6.007-6.196l.028-1.43ZM118.906 25.857V16.6h2.664v9.257h-2.664Z" fill="#000"/><path d="M140.121 31.642c0 2.987-2.101 6.101-7.835 6.101-3.664 0-6.405-1.37-8.157-3.3l1.592-1.993a10.5 10.5 0 0 0 3.4 2.227 10.56 10.56 0 0 0 4.072.597c3.792 0 5.002-1.993 5.002-3.614 0-5.353-12.585-2.366-12.585-9.776 0-3.423 3.123-5.789 7.328-5.789 3.218 0 5.734 1.091 7.519 2.927l-1.593 1.899c-1.592-1.745-3.824-2.49-6.147-2.49-2.519 0-4.366 1.339-4.366 3.299 0 4.672 12.57 1.994 12.57 9.912Z" fill="#000"/></g><defs><clipPath id="a"><rect width="140" height="54" fill="#fff"/></clipPath></defs></svg>';
    $logoB64 = 'data:image/svg+xml;base64,' . base64_encode($logoSvg);
@endphp
<style>
    @page { margin: 0; size: A4; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9px; color: #1a1a1a; background: #fff; }

    .page { padding: 0; width: 100%; }

    /* HEADER */
    .header { padding: 16px 24px 12px; border-bottom: 2px solid #eee; }
    .header-table { width: 100%; border-collapse: collapse; }
    .hd-logo { width: 130px; vertical-align: middle; }
    .hd-company { vertical-align: middle; padding: 0 12px; text-align: center; }
    .hd-right { width: 140px; text-align: right; vertical-align: middle; }
    .logo-img { width: 120px; height: auto; }
    .company-name { font-size: 13px; font-weight: 700; color: #111; line-height: 1.2; }
    .company-addr { font-size: 7.5px; color: #555; line-height: 1.7; margin-top: 3px; }
    .company-cin { font-size: 7px; color: #888; margin-top: 2px; }
    .slip-title { font-size: 22px; font-weight: 900; color: #111; letter-spacing: 1px; line-height: 1; }
    .slip-month { font-size: 14px; font-weight: 700; color: {{ $brand }}; margin-top: 2px; }
    .cycle-badge {
        background: #fff8ee;
        border: 1px solid #ffd580;
        color: #b36a00;
        font-size: 7.5px;
        font-weight: 600;
        padding: 3px 7px;
        border-radius: 3px;
        display: inline-block;
        margin-top: 6px;
    }

    /* EMPLOYEE CARD */
    .emp-card { padding: 12px 24px; border-bottom: 1px solid #eee; }
    .emp-card-table { width: 100%; border-collapse: collapse; }
    .emp-avatar-cell { width: 100px; vertical-align: middle; }
    .emp-info-cell { vertical-align: top; padding-left: 12px; width: 45%; }
    .emp-fields-cell { vertical-align: top; width: 26%; border-left: 1px solid #eee; padding-left: 16px; }
    .emp-tax-cell { vertical-align: top; width: 26%; border-left: 1px solid #eee; padding-left: 16px; }

    .avatar-circle {
        width: 64px; height: 64px;
        background: {{ $brand }};
        border-radius: 50%;
        text-align: center;
        line-height: 64px;
        font-size: 22px;
        font-weight: 900;
        color: #fff;
        display: inline-block;
    }
    .emp-name { font-size: 14px; font-weight: 800; color: #111; line-height: 1.2; }
    .emp-title { font-size: 10px; color: {{ $brand }}; font-weight: 600; margin-top: 2px; }
    .emp-id { font-size: 8px; color: #888; margin-top: 4px; }

    .ef-row { margin-bottom: 6px; }
    .ef-label { font-size: 7.5px; color: #999; display: block; line-height: 1; }
    .ef-value { font-size: 8.5px; font-weight: 600; color: #222; display: block; margin-top: 1px; }
    .ef-icon { font-size: 8px; color: {{ $brand }}; margin-right: 3px; }

    /* SALARY SUMMARY */
    .summary-section { padding: 10px 24px; border-bottom: 1px solid #eee; }
    .sum-table { width: 100%; border-collapse: separate; border-spacing: 8px 0; }
    .sum-box {
        border: 1px solid #e0e0e0;
        border-radius: 6px;
        padding: 10px 14px;
        vertical-align: middle;
        width: 33%;
    }
    .sum-box-net {
        border: 1px solid #b6e5c8;
        background: #f0fdf4;
        border-radius: 6px;
        padding: 10px 14px;
        vertical-align: middle;
        width: 33%;
    }
    .sum-icon { font-size: 16px; margin-bottom: 3px; display: block; color: {{ $brand }}; }
    .sum-label { font-size: 7px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; color: #888; }
    .sum-amount { font-size: 15px; font-weight: 900; color: #111; line-height: 1.2; margin-top: 2px; }
    .sum-sub { font-size: 7px; color: #aaa; margin-top: 2px; }
    .sum-amount-net { font-size: 15px; font-weight: 900; color: #16a34a; line-height: 1.2; margin-top: 2px; }

    /* EARNINGS TABLE */
    .sal-section { padding: 0 24px 8px; }
    .sal-table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
    .sal-table thead tr { background: transparent; }
    .sal-th-earn {
        font-size: 8px; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase;
        color: {{ $brand }}; padding: 6px 10px; border-bottom: 2px solid {{ $brand }};
        text-align: left; width: 35%;
    }
    .sal-th-amt {
        font-size: 8px; font-weight: 800; color: {{ $brand }};
        padding: 6px 10px; border-bottom: 2px solid {{ $brand }};
        text-align: right; width: 15%;
    }
    .sal-th-div {
        width: 2px; background: #eee; border: none; border-bottom: 2px solid {{ $brand }};
    }
    .sal-th-ded {
        font-size: 8px; font-weight: 800; color: {{ $brand }};
        padding: 6px 10px; border-bottom: 2px solid {{ $brand }};
        text-align: left; width: 33%;
    }
    .sal-th-damt {
        font-size: 8px; font-weight: 800; color: {{ $brand }};
        padding: 6px 10px; border-bottom: 2px solid {{ $brand }};
        text-align: right; width: 15%;
    }
    .sal-td { padding: 5px 10px; font-size: 8.5px; color: #333; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
    .sal-td-amt { padding: 5px 10px; font-size: 8.5px; text-align: right; font-weight: 600; color: #333; border-bottom: 1px solid #f5f5f5; font-family: 'DejaVu Sans Mono', monospace; vertical-align: top; }
    .sal-td-ded { padding: 5px 10px; font-size: 8.5px; color: #555; border-bottom: 1px solid #f5f5f5; border-left: 2px solid #eee; vertical-align: top; }
    .sal-td-damt { padding: 5px 10px; font-size: 8.5px; text-align: right; font-weight: 600; color: #555; border-bottom: 1px solid #f5f5f5; font-family: 'DejaVu Sans Mono', monospace; vertical-align: top; }
    .sal-total-row td { background: #f9f9f9; font-weight: 800; font-size: 9px; padding: 7px 10px; border-top: 2px solid #eee; }
    .sal-total-earn { color: {{ $brand }}; font-size: 9px; }
    .sal-total-amt { text-align: right; color: {{ $brand }}; font-family: 'DejaVu Sans Mono', monospace; }

    /* ATTENDANCE */
    .att-section { padding: 0 24px 8px; }
    .att-title { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #333; padding: 8px 0 6px; border-top: 1px solid #eee; }
    .att-table { width: 100%; border-collapse: collapse; border: 1px solid #eee; border-radius: 4px; }
    .att-td { text-align: center; padding: 10px 6px 8px; border-right: 1px solid #eee; vertical-align: middle; width: 25%; }
    .att-td:last-child { border-right: none; }
    .att-icon-box {
        width: 30px; height: 30px; border-radius: 50%;
        display: inline-block; line-height: 30px; text-align: center;
        font-size: 14px; margin-bottom: 4px;
    }
    .att-icon-blue { background: #dbeafe; color: #2563eb; }
    .att-icon-green { background: #dcfce7; color: #16a34a; }
    .att-icon-purple { background: #ede9fe; color: #7c3aed; }
    .att-icon-orange { background: #ffedd5; color: {{ $brand }}; }
    .att-label { font-size: 7px; text-transform: uppercase; letter-spacing: 0.5px; color: #999; display: block; }
    .att-value { font-size: 16px; font-weight: 900; color: #111; display: block; line-height: 1.2; }
    .att-value-red { font-size: 16px; font-weight: 900; color: {{ $brand }}; display: block; line-height: 1.2; }

    /* BOTTOM INFO */
    .info-section { padding: 8px 24px 10px; border-top: 1px solid #eee; }
    .info-table { width: 100%; border-collapse: collapse; }
    .info-cell { vertical-align: top; padding-right: 16px; width: 33%; border-right: 1px solid #eee; padding-left: 0; }
    .info-cell:last-child { border-right: none; padding-left: 16px; padding-right: 0; }
    .info-cell:nth-child(2) { padding-left: 16px; }
    .info-header { font-size: 8.5px; font-weight: 800; text-transform: uppercase; color: #333; letter-spacing: 0.5px; margin-bottom: 8px; display: block; }
    .info-row { margin-bottom: 5px; display: table; width: 100%; }
    .info-lbl { font-size: 7.5px; color: #888; display: table-cell; width: 45%; }
    .info-colon { font-size: 7.5px; color: #bbb; display: table-cell; width: 8%; }
    .info-val { font-size: 8px; font-weight: 600; color: #222; display: table-cell; }

    /* FOOTER */
    .footer-section { padding: 10px 24px; border-top: 1px solid #eee; }
    .footer-table { width: 100%; border-collapse: collapse; }
    .footer-qr { vertical-align: top; width: 80px; }
    .footer-credited { vertical-align: top; padding-left: 12px; width: 200px; }
    .footer-sig { vertical-align: top; text-align: right; }
    .credited-box {
        border: 1px solid #bbf7d0;
        background: #f0fdf4;
        border-radius: 6px;
        padding: 8px 12px;
    }
    .credited-title { font-size: 9px; font-weight: 800; color: #16a34a; }
    .credited-sub { font-size: 7.5px; color: #555; margin-top: 3px; line-height: 1.5; }
    .sig-line { border-top: 1px solid #999; width: 120px; margin-left: auto; margin-top: 28px; }
    .sig-note { font-size: 7px; color: #888; text-align: center; margin-top: 3px; line-height: 1.5; }

    /* BOTTOM BAR */
    .bottom-bar {
        background: {{ $brand }};
        padding: 8px 24px;
        margin-top: 8px;
    }
    .bottom-bar-table { width: 100%; border-collapse: collapse; }
    .bbar-item { text-align: center; color: #fff; font-size: 8px; font-weight: 600; padding: 0 12px; border-right: 1px solid rgba(255,255,255,0.3); }
    .bbar-item:last-child { border-right: none; }
</style>
</head>
<body>
<div class="page">

{{-- HEADER --}}
<div class="header">
    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="hd-logo">
                <img src="{{ $logoB64 }}" class="logo-img" alt="{{ $company->name }}" />
            </td>
            <td class="hd-company">
                <div class="company-name">{{ $company->name ?? 'Conexus Network Solutions Pvt Ltd' }}</div>
                <div class="company-addr">
                    {{ $company->address ?? 'F-25, Centurion Mall, Sector 19A, Nerul East, Navi Mumbai - 400706' }}<br>
                    @if($company->phone || $company->website)
                        {{ $company->phone ? '+' . $company->phone : '' }}{{ ($company->phone && $company->website) ? '  |  ' : '' }}{{ $company->website ?? 'www.conexus.com' }}
                    @else
                        www.conexus.com
                    @endif
                </div>
                @if($company->cin ?? null)
                    <div class="company-cin">CIN : {{ $company->cin }}</div>
                @endif
            </td>
            <td class="hd-right">
                <div class="slip-title">PAYSLIP</div>
                <div class="slip-month">{{ $monthLabel }}</div>
                <div class="cycle-badge">Payroll Cycle: {{ $cycleLabel }}</div>
            </td>
        </tr>
    </table>
</div>

{{-- EMPLOYEE CARD --}}
<div class="emp-card">
    <table class="emp-card-table" cellpadding="0" cellspacing="0">
        <tr>
            {{-- Avatar --}}
            <td class="emp-avatar-cell">
                <div class="avatar-circle">{{ $initials }}</div>
            </td>
            {{-- Name + basic info --}}
            <td class="emp-info-cell">
                <div class="emp-name">{{ $user->name }}</div>
                <div class="emp-title">{{ $employee->jobTitle?->name ?? '—' }}</div>
                <div class="emp-id">Employee ID: {{ $employee->employee_id ?? '—' }}</div>
            </td>
            {{-- Middle fields --}}
            <td class="emp-fields-cell">
                <div class="ef-row">
                    <span class="ef-label">Department</span>
                    <span class="ef-value">{{ $employee->department?->name ?? '—' }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">Date of Joining</span>
                    <span class="ef-value">{{ $joiningLabel }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">Location</span>
                    <span class="ef-value">{{ $employee->office?->name ?? '—' }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">Gender</span>
                    <span class="ef-value">{{ ucfirst($employee->gender ?? '—') }}</span>
                </div>
            </td>
            {{-- Right fields (PAN, UAN etc) --}}
            <td class="emp-tax-cell">
                <div class="ef-row">
                    <span class="ef-label">PAN</span>
                    <span class="ef-value">{{ $employee->pan_number ?? '—' }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">UAN</span>
                    <span class="ef-value">{{ $employee->uan_number ?? '—' }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">PF Account No</span>
                    <span class="ef-value">{{ $employee->pf_account ?? '—' }}</span>
                </div>
                <div class="ef-row">
                    <span class="ef-label">ESI Number</span>
                    <span class="ef-value">{{ $employee->esi_number ?? '—' }}</span>
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- SALARY SUMMARY --}}
<div class="summary-section">
    <table class="sum-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="sum-box">
                <div class="sum-label">Gross Salary</div>
                <div class="sum-amount">{{ $curr }} {{ number_format($payslip->gross_salary, 2) }}</div>
                <div class="sum-sub">Total Earnings (A)</div>
            </td>
            <td class="sum-box">
                <div class="sum-label">Total Deductions</div>
                <div class="sum-amount">{{ $curr }} {{ number_format($payslip->total_deductions, 2) }}</div>
                <div class="sum-sub">Total Deductions (B)</div>
            </td>
            <td class="sum-box-net">
                <div class="sum-label">Net Pay</div>
                <div class="sum-amount-net">{{ $curr }} {{ number_format($payslip->net_salary, 2) }}</div>
                <div class="sum-sub">(A - B) Net Payable</div>
            </td>
        </tr>
    </table>
</div>

{{-- EARNINGS & DEDUCTIONS TABLE --}}
<div class="sal-section">
    <table class="sal-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th class="sal-th-earn">Earnings &amp; Reimbursements</th>
                <th class="sal-th-amt">Amount ({{ $curr }})</th>
                <th class="sal-th-div" style="border-right: 2px solid #eee;"> </th>
                <th class="sal-th-ded" style="border-left: 0;">Deductions &amp; Recoveries</th>
                <th class="sal-th-damt">Amount ({{ $curr }})</th>
            </tr>
        </thead>
        <tbody>
            @for($i = 0; $i < $maxRows; $i++)
                @php $e = $earnings->get($i); $d = $deductions->get($i); @endphp
                <tr>
                    <td class="sal-td">{{ $e?->name ?? '' }}</td>
                    <td class="sal-td-amt">{{ $e ? number_format($e->amount, 2) : '' }}</td>
                    <td style="border-right: 2px solid #eee; padding: 0;"> </td>
                    <td class="sal-td-ded">{{ $d?->name ?? '' }}</td>
                    <td class="sal-td-damt">{{ $d ? number_format($d->amount, 2) : '' }}</td>
                </tr>
            @endfor
        </tbody>
        <tfoot>
            <tr class="sal-total-row">
                <td class="sal-total-earn">Total Earnings (A)</td>
                <td class="sal-total-amt" style="color: {{ $brand }}; font-size: 9px; font-weight: 900;">{{ number_format($payslip->gross_salary, 2) }}</td>
                <td style="border-right: 2px solid #eee; background: #f9f9f9;"> </td>
                <td style="font-weight:800; font-size:9px; color: #444; padding: 7px 10px; background:#f9f9f9;">Total Deductions (B)</td>
                <td style="text-align:right; font-weight:900; font-size:9px; color:#d00; font-family:'DejaVu Sans Mono',monospace; padding: 7px 10px; background:#f9f9f9;">{{ number_format($payslip->total_deductions, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</div>

{{-- ATTENDANCE SUMMARY --}}
<div class="att-section">
    <div class="att-title">Attendance Summary</div>
    <table class="att-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="att-td">
                <div class="att-icon-box att-icon-blue">&#x1F4C5;</div>
                <div class="att-label">Paid Days</div>
                <div class="att-value">{{ $paidDays }}</div>
            </td>
            <td class="att-td">
                <div class="att-icon-box att-icon-green">&#x2714;</div>
                <div class="att-label">Present Days</div>
                <div class="att-value">{{ $presentDays }}</div>
            </td>
            <td class="att-td">
                <div class="att-icon-box att-icon-purple">&#x1F4C6;</div>
                <div class="att-label">Week Off</div>
                <div class="att-value">{{ $weekOff }}</div>
            </td>
            <td class="att-td">
                <div class="att-icon-box att-icon-orange">&#x274C;</div>
                <div class="att-label">LWP / ABS</div>
                <div class="{{ $lwp > 0 ? 'att-value-red' : 'att-value' }}">{{ number_format($lwp, 2) }}</div>
            </td>
        </tr>
    </table>
</div>

{{-- BOTTOM THREE COLUMNS --}}
<div class="info-section">
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            {{-- Bank Details --}}
            <td class="info-cell">
                <span class="info-header">&#x1F3E6; Bank Details</span>
                <div class="info-row"><span class="info-lbl">Bank Name</span><span class="info-colon">:</span><span class="info-val">{{ $employee->bank_name ?? 'HDFC Bank' }}</span></div>
                <div class="info-row"><span class="info-lbl">Account No</span><span class="info-colon">:</span><span class="info-val">{{ $employee->bank_account ? '****' . substr($employee->bank_account, -4) : '—' }}</span></div>
                <div class="info-row"><span class="info-lbl">IFSC Code</span><span class="info-colon">:</span><span class="info-val">{{ $employee->ifsc_code ?? '—' }}</span></div>
                <div class="info-row"><span class="info-lbl">Account Type</span><span class="info-colon">:</span><span class="info-val">Savings</span></div>
            </td>
            {{-- Tax Details --}}
            <td class="info-cell" style="padding-left: 16px;">
                <span class="info-header">&#x1F3E6; Tax Details (FY {{ $payroll->year }}-{{ substr($payroll->year + 1, -2) }})</span>
                <div class="info-row"><span class="info-lbl">YTD Gross Salary</span><span class="info-colon">:</span><span class="info-val">Rs. {{ number_format($ytdGross, 2) }}</span></div>
                <div class="info-row"><span class="info-lbl">YTD Taxable Salary</span><span class="info-colon">:</span><span class="info-val">Rs. {{ number_format($ytdTaxable, 2) }}</span></div>
                <div class="info-row"><span class="info-lbl">YTD Tax Paid</span><span class="info-colon">:</span><span class="info-val">Rs. {{ number_format($ytdTax, 2) }}</span></div>
                <div class="info-row"><span class="info-lbl">YTD Deductions</span><span class="info-colon">:</span><span class="info-val">Rs. {{ number_format($ytdDeductions, 2) }}</span></div>
            </td>
            {{-- Other Info --}}
            <td class="info-cell" style="padding-left: 16px; border-right: none;">
                <span class="info-header">&#x2139; Other Information</span>
                <div class="info-row"><span class="info-lbl">Working Days</span><span class="info-colon">:</span><span class="info-val">{{ $totalDays }}</span></div>
                <div class="info-row"><span class="info-lbl">Payroll Days</span><span class="info-colon">:</span><span class="info-val">{{ $paidDays }}</span></div>
                <div class="info-row"><span class="info-lbl">Payment Date</span><span class="info-colon">:</span><span class="info-val">{{ $paymentDate }}</span></div>
                <div class="info-row"><span class="info-lbl">Payment Mode</span><span class="info-colon">:</span><span class="info-val">Bank Transfer</span></div>
            </td>
        </tr>
    </table>
</div>

{{-- FOOTER --}}
<div class="footer-section">
    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            {{-- QR code placeholder --}}
            <td class="footer-qr">
                <table cellpadding="3" cellspacing="0" style="border:2px solid #111; font-size:3px; color:#111; line-height:1;">
                    @php $qr = ['111111','100001','101101','100001','111111','000000','1001001']; @endphp
                    @foreach($qr as $row)
                        <tr>@foreach(str_split($row) as $b)<td style="width:4px;height:4px;background:{{ $b==='1'?'#111':'#fff' }};"></td>@endforeach</tr>
                    @endforeach
                </table>
            </td>
            {{-- Salary Credited --}}
            <td class="footer-credited">
                <div class="credited-box">
                    <div class="credited-title">&#x2713; SALARY CREDITED</div>
                    <div class="credited-sub">Your salary for {{ $monthLabel }} has been<br>credited to your bank account.</div>
                </div>
            </td>
            {{-- Signature --}}
            <td class="footer-sig">
                <div class="sig-line"></div>
                <div class="sig-note">This is a system generated payslip.<br>No signature is required.</div>
            </td>
        </tr>
    </table>
</div>

{{-- BOTTOM BAR --}}
<div class="bottom-bar">
    <table class="bottom-bar-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="bbar-item">&#x2709; {{ $company->email ?? 'hr@conexus.com' }}</td>
            <td class="bbar-item">&#x260F; {{ $company->phone ?? '+91 22 1234 5678' }}</td>
            <td class="bbar-item" style="border-right:none;">&#x1F310; {{ $company->website ?? 'www.conexus.com' }}</td>
        </tr>
    </table>
</div>

</div>
</body>
</html>
