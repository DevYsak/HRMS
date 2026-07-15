@php
    use Carbon\Carbon;
    use App\Models\Attendance;
    use App\Models\LeaveRequest;

    $orange = '#f97316';
    $green  = '#16a34a';

    $company  = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Conexus Network Solutions Pvt Ltd']);

    $employee = $payslip->employee;
    $payroll  = $payslip->payroll;
    $user     = $employee->user;

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
    $workingDays = $totalDays - $weekOff;
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
            if ($s->lte($e)) $leaveDays += (int)($s->diffInDays($e) + 1);
        });
    // LWP: absent working days (only meaningful if attendance is tracked)
    $lwp = ($presentDays > 0 || $leaveDays > 0)
        ? max(0, $workingDays - $presentDays - $leaveDays)
        : 0;

    $earnings   = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $maxRows    = max($earnings->count(), $deductions->count(), 4);

    $monthLabel   = $payroll->month . ' ' . $payroll->year;
    $cycleLabel   = $payroll->month . ' ' . $payroll->year . ' - Cycle ' . strtoupper(str_replace('cycle_', '', $payroll->cycle ?? 'a'));
    $joiningLabel = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('F Y') : '-';
    $paymentDate  = $to->copy()->addDay()->format('d M Y');

    $coName  = $company->name ?? 'Company Name';
    $coAddr1 = $company->address ?? '';
    $coAddr2 = $company->address_line2 ?? ($company->city ?? '');
    $coCIN   = $company->cin ?? '';
    $coPhone = $company->phone ?? '';
    $coEmail = $company->email ?? '';
    $coWeb   = $company->website ?? '';

    // Logo: use company logo from storage if set, otherwise fall back to the inline Conexus SVG
    $logoSrc = $company->logo ? asset('storage/' . $company->logo) : null;
    $logoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="54" viewBox="0 0 140 54" fill="none"><g clip-path="url(#a)"><path d="M18.894 33.599c-1.776 2.396-4.33 4.141-8.092 4.141-6.115 0-10.925-4.36-10.925-10.743S4.687 16.26 10.802 16.26c3.762 0 6.373 1.742 8.063 4.108l-2.2 1.212a7.7 7.7 0 0 0-2.778-2.031 7.74 7.74 0 0 0-3.085-.639c-4.65 0-8.187 3.457-8.187 8.44 0 4.92 3.552 8.44 8.187 8.44a7.76 7.76 0 0 0 3.086-.607 7.73 7.73 0 0 0 2.802-2.026l2.204 1.202Z" fill="#000"/><path d="M42.567 27c0 6.131-4.301 10.739-10.579 10.739S21.457 33.13 21.457 27c0-6.131 4.268-10.742 10.543-10.742S42.567 20.864 42.567 27Zm-2.741 0c0-4.847-3.09-8.437-7.838-8.437-4.748 0-7.805 3.578-7.805 8.437 0 4.86 3.028 8.447 7.805 8.447 4.777 0 7.838-3.62 7.838-8.447Z" fill="#000"/><path d="M62.705 16.616v20.767H60.156L47.763 20.818v16.565H45.117V16.616h2.709l12.236 16.22V16.616h2.643Z" fill="#000"/><path d="M79.175 16.616v2.302H67.898v4.436H65.252v-6.738h13.923Z" fill="#000"/><path d="m78.953 27.92-13.701.003v-2.311l13.701.003v2.305Z" fill="#7E1F24"/><path d="M67.898 35.078h11.277v2.305H65.252V30.18h2.646v4.898Z" fill="#000"/><path d="m90.071 27.089-8.329-10.57h3.368l6.63 8.349" fill="#7E1F24"/><path d="m94.932 28.507 6.734 8.867-3.35.009-4.988-6.68" fill="#000"/><path d="m93.389 22.672 4.88-6.153h3.398l-6.589 8.283-1.69-2.13Z" fill="#000"/><path d="m91.7 24.866 1.628 1.948-8.255 10.57-3.35-.01L91.7 24.866Z" fill="#7E1F24"/><path d="M104.219 25.857V16.6h2.664v9.257h-2.664Zm14.687 1.954h2.664v1.46c0 5.171-2.931 8.482-8.699 8.482s-8.664-3.332-8.664-8.437v-1.505h2.664v1.43c0 3.8 2.072 6.196 6 6.196s6.007-2.396 6.007-6.196l.028-1.43ZM118.906 25.857V16.6h2.664v9.257h-2.664Z" fill="#000"/><path d="M140.121 31.642c0 2.987-2.101 6.101-7.835 6.101-3.664 0-6.405-1.37-8.157-3.3l1.592-1.993a10.5 10.5 0 0 0 3.4 2.227 10.56 10.56 0 0 0 4.072.597c3.792 0 5.002-1.993 5.002-3.614 0-5.353-12.585-2.366-12.585-9.776 0-3.423 3.123-5.789 7.328-5.789 3.218 0 5.734 1.091 7.519 2.927l-1.593 1.899c-1.592-1.745-3.824-2.49-6.147-2.49-2.519 0-4.366 1.339-4.366 3.299 0 4.672 12.57 1.994 12.57 9.912Z" fill="#000"/></g><defs><clipPath id="a"><rect width="140" height="54" fill="#fff"/></clipPath></defs></svg>';
    $logoB64 = 'data:image/svg+xml;base64,' . base64_encode($logoSvg);

    // SVG Icon helpers — all 18x18, inline-friendly for DomPDF via base64 img
    if (!function_exists('svgIcon')) {
        function svgIcon(string $paths, string $color = '#374151', int $sz = 16): string {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$sz.'" height="'.$sz.'" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</svg>';
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
    }

    // Section header icons
    $icoBank  = svgIcon('<rect x="3" y="9" width="18" height="12" rx="1"/><path d="M3 9L12 3l9 6"/><line x1="8" y1="13" x2="8" y2="17"/><line x1="12" y1="13" x2="12" y2="17"/><line x1="16" y1="13" x2="16" y2="17"/>', $orange, 17);
    $icoTax   = svgIcon('<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><polyline points="8,14 10,16 14,12"/>', '#3b82f6', 17);
    $icoInfo  = svgIcon('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><circle cx="12" cy="8" r="1" fill="#8b5cf6" stroke="none"/>', '#8b5cf6', 17);

    // Attendance icons
    $icoCalendar  = svgIcon('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/>', '#1d4ed8', 20);
    $icoPresent   = svgIcon('<circle cx="12" cy="12" r="10"/><polyline points="9,12 11,14 15,10"/>', '#15803d', 20);
    $icoWeekoff   = svgIcon('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><path d="M8 15h8" stroke-dasharray="2 2"/>', '#6d28d9', 20);
    $icoLwp       = svgIcon('<circle cx="12" cy="12" r="10"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>', '#c2410c', 20);

    // Salary Credited check icon
    $icoCheck = svgIcon('<circle cx="12" cy="12" r="10" fill="#16a34a" stroke="none"/><polyline points="8,12 11,15 16,9" stroke="#fff" stroke-width="2.5"/>', '#16a34a', 30);

    // YTD
    $ytdSlips = \App\Models\Payslip::where('employee_id', $employee->id)
        ->where('status', 'paid')
        ->whereHas('payroll', fn ($q) => $q->where('year', $payroll->year))
        ->with('items')->get();
    $ytdGross = $ytdSlips->sum('gross_salary');
    $ytdDed   = $ytdSlips->sum('total_deductions');
    $ytdTax   = $ytdSlips->flatMap->items->where('name', 'Income Tax (TDS)')->sum('amount');
    $ytdTaxbl = max(0, $ytdGross - $ytdSlips->flatMap->items->where('name', 'Provident Fund (PF)')->sum('amount'));
@endphp


<div class="top-bar"></div>

<div class="page">

{{-- ══ HEADER ══ --}}
<div class="hdr">
<table class="hdr-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="h-logo">
        <img src="{{ $logoSrc ?? $logoB64 }}" alt="{{ $coName }}" style="width:118px;height:auto;display:block;" />
    </td>
    <td class="h-center">
        <div class="co-name">{{ $coName }}</div>
        <div class="co-addr">{{ $coAddr1 }}<br>{{ $coAddr2 }}</div>
        <div class="co-cin">@if($coCIN)CIN: {{ $coCIN }}&nbsp;&nbsp;|&nbsp;&nbsp;@endif{{ $coWeb }}</div>
    </td>
    <td class="h-right">
        <div class="slip-ttl">PAYSLIP</div>
        <div class="slip-mo">{{ $monthLabel }}</div>
        <div class="cyc-pill">Payroll Cycle: {{ $cycleLabel }}</div>
    </td>
</tr>
</table>
</div>

{{-- ══ EMPLOYEE ══ --}}
<div class="emp">
<table class="emp-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="ec-name">
        <div class="e-name">{{ $user->name }}</div>
        <div class="e-role">{{ $employee->jobTitle?->name ?? 'Employee' }}</div>
        <div class="e-eid">Employee ID: {{ $employee->employee_id ?? '-' }}</div>
    </td>
    <td class="ec-mid">
        <div class="fi"><span class="fl">Department</span><span class="fv">{{ $employee->department?->name ?? '-' }}</span></div>
        <div class="fi"><span class="fl">Date of Joining</span><span class="fv">{{ $joiningLabel }}</span></div>
        <div class="fi"><span class="fl">Location</span><span class="fv">{{ $employee->office?->name ?? '-' }}</span></div>
        <div class="fi"><span class="fl">Gender</span><span class="fv">{{ ucfirst($employee->gender ?? '-') }}</span></div>
    </td>
    <td class="ec-rgt">
        <div class="fi"><span class="fl">PAN</span><span class="fv">{{ $employee->pan_number ?? '-' }}</span></div>
        <div class="fi"><span class="fl">UAN</span><span class="fv">{{ $employee->uan_number ?? '-' }}</span></div>
        <div class="fi"><span class="fl">PF Account No</span><span class="fv">{{ $employee->pf_account ?? '-' }}</span></div>
        <div class="fi"><span class="fl">ESI Number</span><span class="fv">{{ $employee->esi_number ?? '-' }}</span></div>
    </td>
</tr>
</table>
</div>

</div>{{-- end .page --}}

{{-- ══ SALARY SUMMARY — full bleed bg ══ --}}
<div class="sum-bg">
<div class="page">
<table class="sum-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="sc">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico-e"><img src="{{ svgIcon('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>', $orange, 16) }}" width="16" height="16"/></div></td>
            <td class="sc-body"><div class="sc-lbl">Gross Salary</div><div class="sc-amt">Rs.{{ number_format($payslip->gross_salary,2) }}</div><div class="sc-sub">Total Earnings (A)</div></td>
        </tr></table>
    </td>
    <td class="sc">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico-d"><img src="{{ svgIcon('<circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/>', '#ef4444', 16) }}" width="16" height="16"/></div></td>
            <td class="sc-body"><div class="sc-lbl">Total Deductions</div><div class="sc-amt-d">Rs.{{ number_format($payslip->total_deductions,2) }}</div><div class="sc-sub">Total Deductions (B)</div></td>
        </tr></table>
    </td>
    <td class="sc-net">
        <table cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;"><div class="sc-ico-n"><img src="{{ svgIcon('<circle cx="12" cy="12" r="10"/><polyline points="8,12 11,15 16,9"/>', $green, 16) }}" width="16" height="16"/></div></td>
            <td class="sc-body"><div class="sc-lbl" style="color:#15803d;">Net Pay</div><div class="sc-amt-n">Rs.{{ number_format($payslip->net_salary,2) }}</div><div class="sc-sub">(A - B) Net Payable</div></td>
        </tr></table>
    </td>
</tr>
</table>
</div>
</div>

<div class="page">

{{-- ══ SALARY COMPARISON — this month vs previous month ══ --}}
@php
    $curDate  = Carbon::parse("1 {$payroll->month} {$payroll->year}");
    $prevDate = $curDate->copy()->subMonth();
    $prevPayslip = \App\Models\Payslip::whereHas('payroll', fn ($q) => $q
            ->where('month', $prevDate->format('F'))
            ->where('year', $prevDate->year)
            ->where('cycle', $payroll->cycle))
        ->where('employee_id', $employee->id)
        ->latest('id')->first();
    $curLabel  = $curDate->format('M Y');
    $prevLabel = $prevDate->format('M Y');
    $cmpRows = [
        ['Gross Earnings',   (float) ($prevPayslip->gross_salary ?? 0),    (float) $payslip->gross_salary,    false],
        ['Total Deductions', (float) ($prevPayslip->total_deductions ?? 0),(float) $payslip->total_deductions, true],
        ['Net Pay',          (float) ($prevPayslip->net_salary ?? 0),      (float) $payslip->net_salary,      false],
    ];
@endphp
<div class="cmp-wrap">
    <div class="att-head" style="border-top:none;padding-top:2px;">Salary Comparison &mdash; {{ $prevLabel }} vs {{ $curLabel }}</div>
    <table class="cmp-tbl" cellpadding="0" cellspacing="0">
        <thead><tr>
            <th class="cmp-th cmp-th-l">Particulars</th>
            <th class="cmp-th">{{ $prevLabel }}</th>
            <th class="cmp-th cmp-th-c">{{ $curLabel }}</th>
            <th class="cmp-th">Change</th>
        </tr></thead>
        <tbody>
        @foreach($cmpRows as [$label, $prev, $cur, $deductionRow])
            @php
                $delta = round($cur - $prev, 2);
                // For deductions a rise is unfavourable (red); for earnings/net a rise is favourable (green).
                $cls = $delta == 0 ? 'cmp-flat' : (($deductionRow ? $delta < 0 : $delta > 0) ? 'cmp-up' : 'cmp-dn');
                $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '');
            @endphp
            <tr>
                <td class="cmp-td cmp-td-l">{{ $label }}</td>
                <td class="cmp-td">{{ $prevPayslip ? 'Rs.'.number_format($prev, 2) : '—' }}</td>
                <td class="cmp-td cmp-td-c">Rs.{{ number_format($cur, 2) }}</td>
                <td class="cmp-td {{ $prevPayslip ? $cls : 'cmp-flat' }}">{{ $prevPayslip ? $sign.'Rs.'.number_format(abs($delta), 2) : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @unless($prevPayslip)<div class="cmp-note">No payslip on record for {{ $prevLabel }} — comparison will populate from next cycle.</div>@endunless
</div>

{{-- ══ EARNINGS & DEDUCTIONS ══ --}}
<div class="tbl-wrap">
<table class="sal-tbl" cellpadding="0" cellspacing="0">
    <thead><tr>
        <th class="eth">Earnings &amp; Reimbursements</th>
        <th class="ath">Amount (Rs.)</th>
        <th class="dth">Deductions &amp; Recoveries</th>
        <th class="xth">Amount (Rs.)</th>
    </tr></thead>
    <tbody>
    @for($i = 0; $i < $maxRows; $i++)
    @php $e = $earnings->get($i); $d = $deductions->get($i); @endphp
    <tr>
        <td class="etd">{{ $e?->name ?? '' }}</td>
        <td class="atd">{{ $e ? number_format($e->amount,2) : '' }}</td>
        <td class="dtd">{{ $d?->name ?? '' }}</td>
        <td class="xtd">{{ $d ? number_format($d->amount,2) : '' }}</td>
    </tr>
    @endfor
    </tbody>
    <tfoot><tr>
        <td class="tot-e">Total Earnings (A)</td>
        <td class="tot-a">{{ number_format($payslip->gross_salary,2) }}</td>
        <td class="tot-d">Total Deductions (B)</td>
        <td class="tot-x">{{ number_format($payslip->total_deductions,2) }}</td>
    </tr></tfoot>
</table>
</div>

{{-- ══ ATTENDANCE ══ --}}
<div class="att-wrap">
<div class="att-head">Attendance Summary</div>
<table class="att-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="ac">
        <div class="ac-ico-wrap"><div class="ac-ico aci-blue"><img src="{{ $icoCalendar }}" width="20" height="20"/></div></div>
        <div class="ac-num">{{ $workingDays }}</div>
        <div class="ac-lbl">Working Days</div>
    </td>
    <td class="ac">
        <div class="ac-ico-wrap"><div class="ac-ico aci-green"><img src="{{ $icoPresent }}" width="20" height="20"/></div></div>
        <div class="ac-num">{{ $presentDays }}</div>
        <div class="ac-lbl">Present Days</div>
    </td>
    <td class="ac">
        <div class="ac-ico-wrap"><div class="ac-ico aci-purple"><img src="{{ $icoWeekoff }}" width="20" height="20"/></div></div>
        <div class="ac-num">{{ $weekOff }}</div>
        <div class="ac-lbl">Week Off</div>
    </td>
    <td class="ac">
        <div class="ac-ico-wrap"><div class="ac-ico aci-red"><img src="{{ $icoLwp }}" width="20" height="20"/></div></div>
        <div class="{{ $lwp > 0 ? 'ac-num-o' : 'ac-num' }}">{{ number_format($lwp,2) }}</div>
        <div class="ac-lbl">LWP / ABS</div>
    </td>
</tr>
</table>
</div>

{{-- ══ BOTTOM 3-COL ══ --}}
<div class="bot-wrap">
<table class="bot-tbl" cellpadding="0" cellspacing="0">
<tr>
    <td class="bc1">
        <table class="bh-tbl" cellpadding="0" cellspacing="0"><tr>
            <td class="bh-ico"><img src="{{ $icoBank }}" width="17" height="17"/></td>
            <td class="bh-txt">Bank Details</td>
        </tr></table>
        <div class="br"><span class="brl">Bank Name</span><span class="brc">:</span><span class="brv">{{ $employee->bank_name ?? 'HDFC Bank' }}</span></div>
        <div class="br"><span class="brl">Account No</span><span class="brc">:</span><span class="brv">{{ $employee->bank_account ? '5020 **** **** '.substr($employee->bank_account,-4) : '-' }}</span></div>
        <div class="br"><span class="brl">IFSC Code</span><span class="brc">:</span><span class="brv">{{ $employee->ifsc_code ?? '-' }}</span></div>
        <div class="br"><span class="brl">Account Type</span><span class="brc">:</span><span class="brv">Savings</span></div>
    </td>
    <td class="bc2">
        <table class="bh-tbl" cellpadding="0" cellspacing="0"><tr>
            <td class="bh-ico"><img src="{{ $icoTax }}" width="17" height="17"/></td>
            <td class="bh-txt">Tax Details (FY {{ $payroll->year }}-{{ substr($payroll->year+1,-2) }})</td>
        </tr></table>
        <div class="br"><span class="brl">YTD Gross Salary</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdGross,2) }}</span></div>
        <div class="br"><span class="brl">YTD Taxable Salary</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdTaxbl,2) }}</span></div>
        <div class="br"><span class="brl">YTD Tax Paid</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdTax,2) }}</span></div>
        <div class="br"><span class="brl">YTD Deductions</span><span class="brc">:</span><span class="brv-g">Rs.{{ number_format($ytdDed,2) }}</span></div>
    </td>
    <td class="bc3">
        <table class="bh-tbl" cellpadding="0" cellspacing="0"><tr>
            <td class="bh-ico"><img src="{{ $icoInfo }}" width="17" height="17"/></td>
            <td class="bh-txt">Other Information</td>
        </tr></table>
        <div class="br"><span class="brl">Working Days</span><span class="brc">:</span><span class="brv">{{ $totalDays }}</span></div>
        <div class="br"><span class="brl">Payroll Days</span><span class="brc">:</span><span class="brv">{{ $workingDays }}</span></div>
        <div class="br"><span class="brl">Payment Date</span><span class="brc">:</span><span class="brv">{{ $paymentDate }}</span></div>
        <div class="br"><span class="brl">Payment Mode</span><span class="brc">:</span><span class="brv">Bank Transfer</span></div>
    </td>
</tr>
</table>
</div>

{{-- ══ SALARY CREDITED ══ --}}
<div class="sal-wrap">
<table class="sal-tbl" cellpadding="0" cellspacing="0"><tr>
    <td class="sal-box-td">
        <div class="sal-box">
        <table class="sal-inner" cellpadding="0" cellspacing="0"><tr>
            <td class="sal-ico-td"><img src="{{ $icoCheck }}" width="30" height="30"/></td>
            <td class="sal-txt-td">
                <div class="sal-ttl">SALARY CREDITED</div>
                <div class="sal-sub">Your salary for {{ $monthLabel }} has been credited to your bank account.</div>
            </td>
        </tr></table>
        </div>
    </td>
    <td class="sal-note-td">
        <div class="sys-note">This is a system generated payslip.<br>No signature is required.</div>
    </td>
</tr></table>
</div>

</div>{{-- end .page --}}

{{-- ══ BOTTOM BAR ══ --}}
<div class="btm-bar" style="padding: 9px 32px;">
<table class="btm-inner" cellpadding="0" cellspacing="0"><tr>
    @if($coEmail)<td class="btm-td"><span class="btm-lbl">Email</span><span class="btm-val">{{ $coEmail }}</span></td>@endif
    @if($coPhone)<td class="btm-td"><span class="btm-lbl">Phone</span><span class="btm-val">{{ $coPhone }}</span></td>@endif
    @if($coWeb)<td class="btm-td" style="border-right:none;"><span class="btm-lbl">Website</span><span class="btm-val">{{ $coWeb }}</span></td>@endif
</tr></table>
</div>

