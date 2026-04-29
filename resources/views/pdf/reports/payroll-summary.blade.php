<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Summary — {{ $monthLabel }}</title>
    @php
        $company = \App\Models\Company::first() ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#1DB77A']);
    @endphp
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #333; margin: 0; padding: 24px; }
        h1 { font-size: 20px; color: {{ $company->primary_color }}; margin: 0 0 4px; }
        .sub { color: #666; font-size: 11px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #f1f5f1; text-align: left; padding: 7px 10px; font-size: 10px; text-transform: uppercase; border-bottom: 2px solid {{ $company->primary_color }}; }
        td { padding: 7px 10px; border-bottom: 1px solid #e5e7eb; }
        tr:last-child td { border-bottom: none; }
        .mono { font-family: 'Courier New', monospace; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-draft { background: #fef9c3; color: #854d0e; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-finalized { background: #dcfce7; color: #166534; }
        .total-row td { font-weight: bold; background: #f8fafc; border-top: 2px solid #e5e7eb; }
        .footer { margin-top: 40px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <h1>{{ $company->name ?? 'Pulse HRMS' }}</h1>
    <div class="sub">Payroll Summary — {{ $monthLabel }} &nbsp;|&nbsp; Generated {{ now()->format('d M Y H:i') }} IST</div>

    @if($payrolls->isEmpty())
        <p>No payroll runs found for {{ $monthLabel }}.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Cycle</th>
                    <th>Status</th>
                    <th>Employees</th>
                    <th class="mono">Gross Payout</th>
                    <th class="mono">OT</th>
                    <th class="mono">Incentives</th>
                    <th class="mono">Reimbursements</th>
                    <th class="mono">Deductions</th>
                    <th>Finance Approved By</th>
                    <th>Finalized At</th>
                </tr>
            </thead>
            <tbody>
                @php $grandTotal = 0; @endphp
                @foreach($payrolls as $payroll)
                    @php $grandTotal += $payroll->total_payout; @endphp
                    <tr>
                        <td>{{ strtoupper($payroll->cycle ?? 'A') }}</td>
                        <td>
                            @php
                                $badgeClass = match($payroll->status) {
                                    'finalized' => 'badge-finalized',
                                    'pending_finance' => 'badge-pending',
                                    default => 'badge-draft',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }}">{{ str_replace('_', ' ', $payroll->status) }}</span>
                        </td>
                        <td>{{ $payroll->payslips->count() }}</td>
                        <td class="mono">{{ number_format($payroll->total_payout, 2) }}</td>
                        <td class="mono">{{ number_format($payroll->ot_amount, 2) }}</td>
                        <td class="mono">{{ number_format($payroll->incentives, 2) }}</td>
                        <td class="mono">{{ number_format($payroll->reimbursements, 2) }}</td>
                        <td class="mono">{{ number_format($payroll->deductions, 2) }}</td>
                        <td>{{ $payroll->financeApprovedBy?->name ?? '—' }}</td>
                        <td>{{ $payroll->finance_approved_at?->format('d M Y') ?? '—' }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">Grand Total</td>
                    <td class="mono">{{ number_format($grandTotal, 2) }}</td>
                    <td colspan="6"></td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="footer">Pulse HRMS &mdash; Confidential &mdash; {{ $monthLabel }} Payroll Report</div>
</body>
</html>
