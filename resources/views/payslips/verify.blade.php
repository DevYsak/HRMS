<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Payslip Verification</title>
<style>
    :root { color-scheme: light dark; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
        background: #f4f4f5; color: #111827; min-height: 100vh;
        display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .card {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
        padding: 36px 40px; max-width: 460px; width: 100%;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .badge {
        display: inline-flex; align-items: center; gap: 7px;
        background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;
        border-radius: 999px; padding: 5px 14px; font-size: 12px; font-weight: 700;
        letter-spacing: 0.4px; text-transform: uppercase; margin-bottom: 20px;
    }
    .badge svg { width: 14px; height: 14px; }
    h1 { font-size: 17px; font-weight: 700; letter-spacing: -0.2px; margin-bottom: 4px; }
    .sub { font-size: 12.5px; color: #6b7280; margin-bottom: 24px; line-height: 1.55; }
    dl { border-top: 1px solid #f3f4f6; }
    .row { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid #f3f4f6; }
    dt { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9ca3af; }
    dd { font-size: 13px; font-weight: 600; text-align: right; }
    .note { font-size: 11px; color: #9ca3af; margin-top: 22px; line-height: 1.6; }
    @media (prefers-color-scheme: dark) {
        body { background: #18181b; color: #f4f4f5; }
        .card { background: #27272a; border-color: #3f3f46; }
        .badge { background: rgba(4,120,87,0.15); border-color: #065f46; color: #34d399; }
        dl, .row { border-color: #3f3f46; }
    }
</style>
</head>
<body>
<div class="card">
    <span class="badge">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Verified Authentic
    </span>
    <h1>Payslip Verification</h1>
    <p class="sub">
        This payslip was issued by
        <strong>{{ $company->name ?? 'the employer' }}</strong> through its HRMS.
        The details below match the original record.
    </p>
    <dl>
        <div class="row">
            <dt>Payslip No.</dt>
            <dd>PSL/{{ $payslip->payroll->year }}/{{ str_pad((string) \Carbon\Carbon::parse('1 '.$payslip->payroll->month.' 2000')->month, 2, '0', STR_PAD_LEFT) }}/{{ $payslip->employee->employee_id }}</dd>
        </div>
        <div class="row">
            <dt>Employee</dt>
            <dd>{{ $payslip->employee->user->name ?? '—' }}</dd>
        </div>
        <div class="row">
            <dt>Pay Period</dt>
            <dd>{{ $payslip->payroll->month }} {{ $payslip->payroll->year }}</dd>
        </div>
        <div class="row">
            <dt>Net Salary</dt>
            <dd>₹ {{ \App\Support\IndianCurrency::format((float) $payslip->net_salary) }}</dd>
        </div>
        <div class="row">
            <dt>Status</dt>
            <dd>{{ ucfirst($payslip->status) }}</dd>
        </div>
    </dl>
    <p class="note">
        Verified on {{ now()->format('d M Y, h:i A') }}. This page confirms document
        authenticity only; for any query contact
        {{ $company->email ?? 'the HR department' }}.
    </p>
</div>
</body>
</html>
