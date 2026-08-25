@php
    use App\Models\Attendance;
    use App\Models\LeaveRequest;
    use App\Models\OvertimeRecord;
    use App\Models\PublicHoliday;
    use App\Support\IndianCurrency;
    use Carbon\Carbon;

    $company = \App\Models\Company::first()
        ?? new \App\Models\Company(['name' => 'Conexus Network Solutions Pvt Ltd']);

    $employee = $payslip->employee;
    $payroll = $payslip->payroll;
    $user = $employee->user;
    $settings = $employee->payrollSettings;

    // ── Pay period ──────────────────────────────────────────────────────────
    $monthNum = Carbon::parse("1 {$payroll->month} {$payroll->year}")->month;
    if ($payroll->cycle === 'cycle_a') {
        $from = Carbon::create($payroll->year, $monthNum, 1)->startOfDay();
        $to   = $from->copy()->endOfMonth();
    } else {
        $from = Carbon::create($payroll->year, $monthNum, 1)->subMonth()->setDay(21)->startOfDay();
        $to   = Carbon::create($payroll->year, $monthNum, 20)->endOfDay();
    }

    // ── Attendance ──────────────────────────────────────────────────────────
    $totalDays = (int) ($from->diffInDays($to) + 1);
    $weekOff = 0;
    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
        if ($d->isSunday() || $d->isSaturday()) $weekOff++;
    }
    $workingDays = $totalDays - $weekOff;

    $presentDays = (int) Attendance::where('employee_id', $employee->id)
        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->whereNotNull('check_in')->count();

    $leaveDays = 0;
    LeaveRequest::where('employee_id', $employee->id)
        ->where('status', 'approved')
        ->where('start_date', '<=', $to->toDateString())
        ->where('end_date', '>=', $from->toDateString())
        ->get()->each(function ($lr) use ($from, $to, &$leaveDays) {
            $s = Carbon::parse($lr->start_date)->max($from);
            $e = Carbon::parse($lr->end_date)->min($to);
            if ($s->lte($e)) $leaveDays += (int) ($s->diffInDays($e) + 1);
        });

    $holidays = (int) PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->where('is_active', true)->count();

    $otHours = (float) OvertimeRecord::where('employee_id', $employee->id)
        ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
        ->sum('ot_hours');

    // LWP only means something once attendance is actually tracked.
    $lwp = ($presentDays > 0 || $leaveDays > 0)
        ? max(0, $workingDays - $presentDays - $leaveDays)
        : 0;
    $paidDays = max(0, $totalDays - $lwp);

    // ── Salary lines ────────────────────────────────────────────────────────
    $earnings = $payslip->items->where('type', 'earning')->values();
    $deductions = $payslip->items->where('type', 'deduction')->values();
    $contributions = $payslip->items->where('type', 'employer_contribution')->values();
    $rowCount = max($earnings->count(), $deductions->count(), 5);

    // ── Labels & identifiers ────────────────────────────────────────────────
    $monthLabel = $payroll->month.' '.$payroll->year;
    $periodLabel = $from->format('d M Y').' – '.$to->format('d M Y');
    $creditDate = ($payroll->finance_approved_at ?? $to)->format('d M Y');
    $slipNo = sprintf('PSL/%d/%02d/%s', $payroll->year, $monthNum, $employee->employee_id);
    $joiningLabel = $employee->joining_date ? Carbon::parse($employee->joining_date)->format('d M Y') : null;

    $acct = $settings->account_number ?? null;
    $acctMasked = $acct && strlen($acct) > 4 ? str_repeat('X', max(0, strlen($acct) - 4)).substr($acct, -4) : $acct;
    $bankLine = trim(($settings->bank_name ?? '').($acctMasked ? ' · '.$acctMasked : ''));

    // Identity pairs — blank ones drop out so the grid never shows empty cells.
    $info = array_filter([
        'Employee ID' => $employee->employee_id,
        'Designation' => $employee->jobTitle->name ?? null,
        'Department' => $employee->department->name ?? null,
        'Date of Joining' => $joiningLabel,
        'PAN' => $settings->pan_number ?? null,
        'UAN' => $settings->uan_number ?? null,
        'PF Number' => $settings->pf_number ?? null,
        'Bank Account' => $bankLine ?: null,
    ], fn ($v) => $v !== null && $v !== '');

    // ── Financial-year-to-date (Apr–Mar) ────────────────────────────────────
    $period = Carbon::create($payroll->year, $monthNum, 1);
    $fyStartYear = $monthNum >= 4 ? $payroll->year : $payroll->year - 1;
    $fyStart = Carbon::create($fyStartYear, 4, 1);

    $ytd = \App\Models\Payslip::where('employee_id', $employee->id)
        ->whereIn('status', ['paid', 'draft'])
        ->with('payroll')->get()
        ->filter(function ($s) use ($fyStart, $period) {
            try {
                $p = Carbon::parse("1 {$s->payroll->month} {$s->payroll->year}");
            } catch (\Throwable) {
                return false;
            }
            return $p->gte($fyStart) && $p->lte($period);
        });
    $ytdGross = (float) $ytd->sum('gross_salary');
    $ytdDeductions = (float) $ytd->sum('total_deductions');
    $ytdNet = (float) $ytd->sum('net_salary');
    $fyLabel = 'FY '.$fyStartYear.'-'.substr((string) ($fyStartYear + 1), 2);

    $logoSrc = $company->logo ? public_path('storage/'.$company->logo) : null;
    if ($logoSrc && ! file_exists($logoSrc)) { $logoSrc = null; }

    // QR → signed public verification URL. Null (no QR) on any failure —
    // authenticity checking must never be the reason a payslip fails to render.
    $qrDataUri = null;
    if ($payslip->exists) {
        try {
            $qrDataUri = \App\Support\QrSvg::dataUri(
                \Illuminate\Support\Facades\URL::signedRoute('payroll.payslips.verify', ['payslip' => $payslip->id]),
                120,
            );
        } catch (\Throwable) {
            $qrDataUri = null;
        }
    }
@endphp

<div class="accent-bar"></div>
<div class="sheet">

    {{-- Header --}}
    <table class="hdr">
        <tr>
            <td style="width: 58%;">
                @if($logoSrc)
                    <img src="{{ $logoSrc }}" class="co-logo" alt="">
                @endif
                <div class="co-name">{{ $company->name }}</div>
                <div class="co-meta">
                    @if($company->address){{ $company->address }}@endif
                    @if($company->address_line2)<br>{{ $company->address_line2 }}@elseif($company->city)<br>{{ $company->city }}@endif
                    @if($company->cin)<br>CIN: {{ $company->cin }}@endif
                </div>
            </td>
            <td style="width: 42%;">
                <div class="doc-title">PAYSLIP</div>
                <div class="doc-month">{{ $monthLabel }}</div>
                <div class="doc-meta">
                    Pay Period&nbsp; {{ $periodLabel }}<br>
                    Payslip No.&nbsp; {{ $slipNo }}
                </div>
            </td>
        </tr>
    </table>

    <hr class="rule">

    {{-- Employee information --}}
    <div class="sec">Employee Information</div>
    <table class="emp">
        <tr>
            <td>
                <span class="lbl">Employee Name</span>
                <span class="val val-strong">{{ $user->name ?? '—' }}</span>
            </td>
            @foreach(array_slice($info, 0, 2, true) as $label => $value)
                <td><span class="lbl">{{ $label }}</span><span class="val">{{ $value }}</span></td>
            @endforeach
        </tr>
        @foreach(array_chunk(array_slice($info, 2, null, true), 3, true) as $row)
            <tr>
                @foreach($row as $label => $value)
                    <td><span class="lbl">{{ $label }}</span><span class="val">{{ $value }}</span></td>
                @endforeach
                @for($i = count($row); $i < 3; $i++)<td></td>@endfor
            </tr>
        @endforeach
    </table>

    {{-- Attendance summary --}}
    <div class="sec">Attendance Summary &nbsp;·&nbsp; {{ $periodLabel }}</div>
    <table class="kpis">
        <tr>
            <td><span class="lbl">Days in Period</span><span class="val">{{ $totalDays }}</span></td>
            <td><span class="lbl">Working Days</span><span class="val">{{ $workingDays }}</span></td>
            <td><span class="lbl">Present</span><span class="val">{{ $presentDays }}</span></td>
            <td><span class="lbl">Paid Leave</span><span class="val">{{ $leaveDays }}</span></td>
            <td><span class="lbl">Week Off</span><span class="val">{{ $weekOff }}</span></td>
            <td><span class="lbl">Holidays</span><span class="val">{{ $holidays }}</span></td>
            <td><span class="lbl">LWP</span><span class="val">{{ $lwp }}</span></td>
            <td class="hot"><span class="lbl">Paid Days</span><span class="val">{{ $paidDays }}</span></td>
            @if($otHours > 0)
                <td><span class="lbl">Overtime (Hrs)</span><span class="val">{{ rtrim(rtrim(number_format($otHours, 2), '0'), '.') }}</span></td>
            @endif
        </tr>
    </table>

    {{-- Earnings / deductions --}}
    <div class="sec">Salary Details</div>
    <table class="pay-wrap">
        <tr>
            <td>
                <table class="pay">
                    <thead>
                        <tr><th>Earnings</th><th class="num">Amount (₹)</th></tr>
                    </thead>
                    <tbody>
                        @for($i = 0; $i < $rowCount; $i++)
                            <tr>
                                <td>{{ $earnings[$i]->name ?? '' }}</td>
                                <td class="num">{{ isset($earnings[$i]) ? IndianCurrency::format((float) $earnings[$i]->amount) : '' }}</td>
                            </tr>
                        @endfor
                        <tr class="total">
                            <td>Gross Earnings</td>
                            <td class="num">{{ IndianCurrency::format((float) $payslip->gross_salary) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
            <td>
                <table class="pay">
                    <thead>
                        <tr><th>Deductions</th><th class="num">Amount (₹)</th></tr>
                    </thead>
                    <tbody>
                        @for($i = 0; $i < $rowCount; $i++)
                            <tr>
                                <td>{{ $deductions[$i]->name ?? '' }}</td>
                                <td class="num">{{ isset($deductions[$i]) ? IndianCurrency::format((float) $deductions[$i]->amount) : '' }}</td>
                            </tr>
                        @endfor
                        <tr class="total">
                            <td>Total Deductions</td>
                            <td class="num">{{ IndianCurrency::format((float) $payslip->total_deductions) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    {{-- Employer contributions + net pay --}}
    <table class="foot-wrap">
        <tr>
            <td>
                @if($contributions->isNotEmpty())
                    <table class="contrib">
                        <tr>
                            <th>Employer Contributions</th>
                            <th style="text-align: right;">Amount (₹)</th>
                        </tr>
                        @foreach($contributions as $line)
                            <tr>
                                <td>{{ $line->name }}</td>
                                <td class="num">{{ IndianCurrency::format((float) $line->amount) }}</td>
                            </tr>
                        @endforeach
                        <tr class="total">
                            <td>Total Contribution</td>
                            <td class="num">{{ IndianCurrency::format((float) $contributions->sum('amount')) }}</td>
                        </tr>
                    </table>
                    <div class="contrib-note">Employer contributions are paid by the company in addition to gross salary and do not reduce net pay.</div>
                @endif
            </td>
            <td>
                <div class="net">
                    <div class="lbl">Net Pay</div>
                    <div class="amt">₹ {{ IndianCurrency::format((float) $payslip->net_salary) }}</div>
                    <div class="words">{{ IndianCurrency::words((float) $payslip->net_salary) }}</div>
                    <div class="credit">Salary Credit Date&nbsp; {{ $creditDate }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Financial year to date --}}
    <table class="ytd">
        <tr>
            <td><span class="lbl">{{ $fyLabel }} · Months Paid</span><span class="val">{{ $ytd->count() }}</span></td>
            <td><span class="lbl">YTD Gross Earnings</span><span class="val">₹ {{ IndianCurrency::format($ytdGross) }}</span></td>
            <td><span class="lbl">YTD Deductions</span><span class="val">₹ {{ IndianCurrency::format($ytdDeductions) }}</span></td>
            <td><span class="lbl">YTD Net Salary</span><span class="val">₹ {{ IndianCurrency::format($ytdNet) }}</span></td>
        </tr>
    </table>

    {{-- QR + note + signature --}}
    <table class="sign-wrap">
        <tr>
            @if($qrDataUri)
                <td class="qr-box">
                    <img src="{{ $qrDataUri }}" alt="">
                    <div class="qr-cap">Scan to verify this payslip</div>
                </td>
            @endif
            <td style="width: {{ $qrDataUri ? '48%' : '60%' }};">
                <div class="gen-note">
                    This is a system-generated payslip and does not require a physical signature.<br>
                    Please contact the HR / Payroll team for any discrepancy in this payslip.<br>
                    Generated on {{ now()->format('d M Y, h:i A') }} · Confidential — intended solely for the employee.
                </div>
            </td>
            <td class="sign-box">
                <div class="sign-co">For {{ $company->name }}</div>
                <span class="sign-line">Authorised Signatory</span>
            </td>
        </tr>
    </table>

    {{-- Contact strip --}}
    <div class="contact">
        {{ $company->name }}
        @if($company->phone)<span class="sep">·</span>{{ $company->phone }}@endif
        @if($company->email)<span class="sep">·</span>{{ $company->email }}@endif
        @if($company->website)<span class="sep">·</span>{{ $company->website }}@endif
    </div>

</div>
