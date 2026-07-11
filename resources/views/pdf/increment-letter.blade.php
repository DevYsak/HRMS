@php
    $company = \App\Models\Company::first() ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#1a202c', 'address' => '123 Business Avenue', 'city' => 'Tech City', 'email' => 'contact@pulsehrms.com', 'phone' => '+1 234 567 8900']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Increment Letter - {{ $employee->user->name }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; line-height: 1.6; margin: 40px; }
        .header { text-align: center; border-bottom: 2px solid {{ $company->primary_color }}; padding-bottom: 20px; margin-bottom: 40px; }
        .header h1 { margin: 0; color: {{ $company->primary_color }}; }
        .header p { margin: 5px 0 0; color: #718096; font-size: 14px; }
        .date { text-align: right; margin-bottom: 30px; }
        .content { font-size: 15px; }
        table.summary { width: 100%; border-collapse: collapse; margin: 25px 0; }
        table.summary th, table.summary td { border: 1px solid #cbd5e0; padding: 8px 12px; text-align: left; font-size: 14px; }
        table.summary th { background: #f7fafc; color: #4a5568; text-transform: uppercase; font-size: 11px; letter-spacing: 0.05em; }
        .signature { margin-top: 70px; }
        .signature p { margin: 0; }
        .confidential { margin-top: 40px; font-size: 11px; color: #a0aec0; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        @if($company->logo)
            <img src="{{ public_path('storage/'.$company->logo) }}" style="height: 60px; width: auto; margin-bottom: 10px;" alt="{{ $company->name }}">
        @endif
        <h1>{{ $company->name }}</h1>
        <p>{{ $company->address }}@if($company->city), {{ $company->city }}@endif · {{ $company->email }}</p>
    </div>

    <div class="date">{{ now()->format('d F Y') }}</div>

    <div class="content">
        <p><strong>Private &amp; Confidential</strong></p>
        <p>Dear {{ $employee->user->name }},</p>

        <p><strong>Subject: Salary Revision — Financial Year {{ $proposal->cycle->financial_year }}</strong></p>

        <p>
            We are pleased to inform you that, in recognition of your performance during the past
            review year, your compensation has been revised with effect from
            <strong>{{ $proposal->cycle->effective_date->format('d F Y') }}</strong>.
        </p>

        <table class="summary">
            <tr><th>Designation</th><td>{{ $proposal->new_designation ?: ($employee->jobTitle->name ?? '—') }}</td></tr>
            <tr><th>Department</th><td>{{ $employee->department->name ?? '—' }}</td></tr>
            <tr><th>Increment</th><td>{{ rtrim(rtrim(number_format($proposal->proposed_percent, 2), '0'), '.') }}%</td></tr>
            <tr><th>Revised Monthly Gross</th><td>&#8377; {{ number_format($proposal->new_gross, 2) }}</td></tr>
            <tr><th>Revised Annual Gross</th><td>&#8377; {{ number_format($proposal->new_gross * 12, 2) }}</td></tr>
        </table>

        @if($proposal->promotion_flag && $proposal->new_designation)
            <p>
                We are also delighted to confirm your promotion to
                <strong>{{ $proposal->new_designation }}</strong>, effective the same date.
                Congratulations on this well-deserved recognition.
            </p>
        @endif

        <p>
            All other terms and conditions of your employment remain unchanged. This revision is
            confidential — please do not share these details with colleagues.
        </p>

        <p>We thank you for your contribution and look forward to your continued success with us.</p>
    </div>

    <div class="signature">
        <p>Warm regards,</p>
        <br><br>
        <p><strong>Human Resources</strong></p>
        <p>{{ $company->name }}</p>
    </div>

    <div class="confidential">This is a system-generated letter from Pulse HRMS. Reference: INC/{{ $proposal->cycle->financial_year }}/{{ str_pad($proposal->id, 5, '0', STR_PAD_LEFT) }}</div>
</body>
</html>
