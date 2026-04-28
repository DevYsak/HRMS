<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip</title>
    @php
        $company = \App\Models\Company::first() ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#1DB77A']);
    @endphp
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .header { margin-bottom: 30px; border-bottom: 2px solid {{ $company->primary_color }}; padding-bottom: 10px; }
        .header h1 { margin: 0; color: {{ $company->primary_color }}; font-size: 24px; }
        .header p { margin: 5px 0; color: #666; }
        .company-logo { font-size: 24px; font-weight: bold; color: {{ $company->primary_color }}; float: right; }
        .details-box { display: inline-block; width: 48%; vertical-align: top; }
        .details-box table { width: 100%; border: none; }
        .details-box table td { border: none; padding: 4px; }
        .total-row th, .total-row td { background-color: #f8f9fa; font-weight: bold; font-size: 14px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .footer { text-align: center; font-size: 10px; color: #888; margin-top: 40px; border-top: 1px solid #ddd; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-logo">
            @if($company->logo)
                <img src="{{ public_path('storage/'.$company->logo) }}" style="height: 40px; width: auto;" alt="{{ $company->name }}">
            @else
                {{ $company->name }}
            @endif
        </div>
        <h1>Payslip</h1>
        <p>Payslip for the period of {{ $payslip->payroll->cycle === 'cycle_a' ? '1st - 15th' : '16th - End of' }} {{ \Carbon\Carbon::parse($payslip->payroll->month)->format('F Y') }}</p>
    </div>

    <div>
        <div class="details-box">
            <table>
                <tr><td><strong>Employee Name:</strong></td><td>{{ $payslip->employee->user->name }}</td></tr>
                <tr><td><strong>Employee ID:</strong></td><td>{{ $payslip->employee->employee_id }}</td></tr>
                <tr><td><strong>Department:</strong></td><td>{{ $payslip->employee->department->name ?? 'N/A' }}</td></tr>
            </table>
        </div>
        <div class="details-box">
            <table>
                <tr><td><strong>Designation:</strong></td><td>{{ $payslip->employee->jobTitle->title ?? 'N/A' }}</td></tr>
                <tr><td><strong>Date of Joining:</strong></td><td>{{ $payslip->employee->joining_date->format('d M Y') }}</td></tr>
                <tr><td><strong>Payslip Date:</strong></td><td>{{ now()->format('d M Y') }}</td></tr>
            </table>
        </div>
    </div>

    <h3>Earnings</h3>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payslip->items->where('type', 'earning') as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ number_format($item->amount, 2) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td class="text-right">Gross Earnings</td>
                <td class="text-right">{{ number_format($payslip->gross_salary, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h3>Deductions</h3>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-right">Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payslip->items->where('type', 'deduction') as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-right">{{ number_format($item->amount, 2) }}</td>
            </tr>
            @endforeach
            @if($payslip->items->where('type', 'deduction')->isEmpty())
            <tr>
                <td colspan="2" class="text-center text-muted">No deductions for this period</td>
            </tr>
            @endif
            <tr class="total-row">
                <td class="text-right">Total Deductions</td>
                <td class="text-right">{{ number_format($payslip->total_deductions, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 30px;">
        <table style="width: 50%; float: right;">
            <tr class="total-row">
                <td>Net Salary Payable</td>
                <td class="text-right" style="color: {{ $company->primary_color }}; font-size: 16px;">₹ {{ number_format($payslip->net_salary, 2) }}</td>
            </tr>
        </table>
        <div style="clear: both;"></div>
    </div>

    <div class="footer">
        <p>This is a computer-generated document. No signature is required.</p>
    </div>
</body>
</html>
