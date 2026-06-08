<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $warning->warningTypeLabel() }} — {{ $warning->employee->user->name }}</title>
    @php
        $company = \App\Models\Company::first() ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#f97316']);
        $refNumber = 'WL-' . $warning->issue_date->format('Y') . '-' . str_pad($warning->id, 5, '0', STR_PAD_LEFT);
        $reportingManager = $warning->employee->manager?->name ?? $warning->issuedBy->name;
    @endphp
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 32px 36px; }

        .header { display: table; width: 100%; border-bottom: 3px solid {{ $company->primary_color ?? '#f97316' }}; padding-bottom: 14px; margin-bottom: 18px; }
        .header-logo { display: table-cell; width: 70px; vertical-align: middle; }
        .header-logo img { max-height: 52px; max-width: 60px; object-fit: contain; }
        .header-info { display: table-cell; vertical-align: middle; padding-left: 14px; }
        .header-info .company-name { font-size: 18px; font-weight: bold; color: {{ $company->primary_color ?? '#f97316' }}; margin: 0 0 2px; }
        .header-info .company-address { font-size: 9px; color: #666; line-height: 1.5; }
        .header-badge { display: table-cell; vertical-align: middle; text-align: right; width: 160px; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; background: #fee2e2; color: #991b1b; }
        .ref-number { font-size: 9px; color: #888; margin-top: 4px; }
        .issued-date { font-size: 9px; color: #888; margin-top: 2px; }

        h2 { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; color: {{ $company->primary_color ?? '#f97316' }}; margin: 20px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td { padding: 5px 8px; border-bottom: 1px solid #f0f0f0; vertical-align: top; font-size: 10.5px; }
        td.label { width: 170px; font-weight: 600; color: #555; white-space: nowrap; }

        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px 14px; margin-bottom: 4px; background: #fafafa; }
        .box p { margin: 4px 0; line-height: 1.6; }

        .timeline-table { width: 100%; border-collapse: collapse; }
        .timeline-table th { background: {{ $company->primary_color ?? '#f97316' }}; color: #fff; padding: 6px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; }
        .timeline-table td { padding: 6px 10px; border-bottom: 1px solid #e5e7eb; font-size: 10.5px; }
        .timeline-table tr:last-child td { border-bottom: none; }

        .ack-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 14px; margin-bottom: 4px; }
        .ack-text { font-size: 10.5px; line-height: 1.7; color: #444; font-style: italic; }

        .sig-grid { display: table; width: 100%; margin-top: 32px; }
        .sig-cell { display: table-cell; width: 50%; padding-right: 20px; vertical-align: bottom; }
        .sig-cell:last-child { padding-right: 0; padding-left: 20px; }
        .sig-line { border-top: 1px solid #999; padding-top: 6px; margin-top: 48px; font-size: 10px; color: #555; }
        .sig-name { font-weight: bold; font-size: 10px; color: #333; }
        .sig-role { font-size: 9px; color: #888; }

        .footer { margin-top: 36px; border-top: 1px solid #e5e7eb; padding-top: 8px; font-size: 8.5px; color: #aaa; text-align: center; }
        .confidential { display: inline-block; border: 1px solid #fca5a5; color: #ef4444; font-size: 8px; font-weight: bold; text-transform: uppercase; padding: 1px 6px; border-radius: 3px; letter-spacing: 0.5px; margin-bottom: 4px; }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div class="header">
        <div class="header-logo">
            @if($company->logo)
                <img src="{{ storage_path('app/public/' . $company->logo) }}" alt="{{ $company->name }}">
            @else
                <div style="width:52px;height:52px;border-radius:8px;background:{{ $company->primary_color ?? '#f97316' }};display:flex;align-items:center;justify-content:center;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="white"><path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/></svg>
                </div>
            @endif
        </div>
        <div class="header-info">
            <div class="company-name">{{ $company->name }}</div>
            <div class="company-address">
                @if($company->address){{ $company->address }}@endif
                @if($company->address && ($company->city || $company->country)), @endif
                @if($company->city){{ $company->city }}@endif
                @if($company->city && $company->country), @endif
                @if($company->country){{ $company->country }}@endif
                @if($company->phone)<br>{{ $company->phone }}@endif
                @if($company->email)<br>{{ $company->email }}@endif
            </div>
        </div>
        <div class="header-badge">
            <span class="badge">{{ $warning->warningTypeLabel() }}</span>
            <div class="ref-number">Ref: {{ $refNumber }}</div>
            <div class="issued-date">Issued: {{ $warning->issue_date->format('d M Y') }}</div>
        </div>
    </div>

    {{-- EMPLOYEE DETAILS --}}
    <h2>Employee Details</h2>
    <table>
        <tr>
            <td class="label">Employee Name</td>
            <td>{{ $warning->employee->user->name }}</td>
            <td class="label">Employee ID</td>
            <td>{{ $warning->employee->employee_id }}</td>
        </tr>
        <tr>
            <td class="label">Department</td>
            <td>{{ $warning->department?->name ?? $warning->employee->department?->name ?? '—' }}</td>
            <td class="label">Designation</td>
            <td>{{ $warning->employee->jobTitle?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Reporting Manager</td>
            <td>{{ $reportingManager }}</td>
            <td class="label">Employment Type</td>
            <td>{{ $warning->employee->employmentType?->name ?? '—' }}</td>
        </tr>
    </table>

    {{-- WARNING INFORMATION --}}
    <h2>Warning Information</h2>
    <table>
        <tr>
            <td class="label">Warning Type</td>
            <td>{{ $warning->warningTypeLabel() }}</td>
            <td class="label">Reference Number</td>
            <td>{{ $refNumber }}</td>
        </tr>
        <tr>
            <td class="label">Issue Date</td>
            <td>{{ $warning->issue_date->format('d M Y') }}</td>
            <td class="label">Issued By</td>
            <td>{{ $warning->issuedBy->name }}</td>
        </tr>
        @if($warning->previous_warning_id)
        <tr>
            <td class="label">Escalated From</td>
            <td colspan="3">
                Prior warning on record — Ref: WL-{{ $warning->previousWarning?->issue_date?->format('Y') }}-{{ str_pad($warning->previous_warning_id, 5, '0', STR_PAD_LEFT) }}
            </td>
        </tr>
        @endif
    </table>

    {{-- INCIDENT DETAILS --}}
    <h2>Incident Details</h2>
    <div class="box">
        <p><strong>Violation / Reason:</strong></p>
        <p>{{ $warning->reason }}</p>
        @if($warning->description)
            <p style="margin-top:10px;"><strong>Detailed Description:</strong></p>
            <p>{{ $warning->description }}</p>
        @endif
        <p style="margin-top:10px;"><strong>Violation Category:</strong> {{ $warning->warningTypeLabel() }}</p>
    </div>

    {{-- IMPROVEMENT TIMELINE --}}
    <h2>Improvement Timeline &amp; Corrective Expectations</h2>
    <table class="timeline-table">
        <thead>
            <tr>
                <th style="width:28%">Milestone</th>
                <th style="width:22%">Target Date</th>
                <th>Expectation</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Acknowledgement</td>
                <td>Within 48 hrs of {{ $warning->issue_date->format('d M Y') }}</td>
                <td>Sign and return this letter confirming receipt</td>
            </tr>
            <tr>
                <td>Improvement Commencement</td>
                <td>{{ $warning->issue_date->addDays(7)->format('d M Y') }}</td>
                <td>Demonstrate measurable improvement in the identified areas</td>
            </tr>
            @if($warning->next_review_date)
            <tr>
                <td>Formal Review</td>
                <td>{{ $warning->next_review_date->format('d M Y') }}</td>
                <td>Review by {{ $warning->issuedBy->name }} and HR. Continued non-compliance may result in escalation.</td>
            </tr>
            @endif
            <tr>
                <td>Resolution Target</td>
                <td>{{ ($warning->next_review_date ?? $warning->issue_date->addDays(90))->format('d M Y') }}</td>
                <td>Full compliance maintained with no further incidents</td>
            </tr>
        </tbody>
    </table>

    @if($warning->manager_comments || $warning->hr_comments)
    {{-- MANAGEMENT COMMENTS --}}
    <h2>Management Comments</h2>
    <div class="box">
        @if($warning->manager_comments)
            <p><strong>Manager ({{ $warning->issuedBy->name }}):</strong></p>
            <p>{{ $warning->manager_comments }}</p>
        @endif
        @if($warning->hr_comments)
            <p style="margin-top:8px;"><strong>HR Department:</strong></p>
            <p>{{ $warning->hr_comments }}</p>
        @endif
    </div>
    @endif

    {{-- EMPLOYEE ACKNOWLEDGEMENT --}}
    <h2>Employee Acknowledgement</h2>
    <div class="ack-box">
        <p class="ack-text">
            I, <strong>{{ $warning->employee->user->name }}</strong>, acknowledge that I have received, read, and understood
            the contents of this {{ $warning->warningTypeLabel() }} dated {{ $warning->issue_date->format('d M Y') }}.
            My signature below confirms receipt of this document only. It does not necessarily indicate agreement with
            the stated findings, but confirms my understanding that failure to meet the stated improvement expectations
            may result in further disciplinary action up to and including termination of employment.
        </p>
    </div>

    {{-- DIGITAL SIGNATURES --}}
    <div class="sig-grid">
        <div class="sig-cell">
            <div class="sig-name">{{ $warning->employee->user->name }}</div>
            <div class="sig-role">{{ $warning->employee->jobTitle?->name ?? 'Employee' }} &middot; {{ $warning->employee->employee_id }}</div>
            <div class="sig-line">Employee Signature &amp; Date</div>
        </div>
        <div class="sig-cell">
            <div class="sig-name">{{ $warning->issuedBy->name }}</div>
            <div class="sig-role">{{ $warning->department?->name ?? 'HR / Management' }}</div>
            <div class="sig-line">Authorised Signatory &amp; Date</div>
        </div>
    </div>

    {{-- FOOTER --}}
    <div class="footer">
        <div class="confidential">Confidential</div><br>
        This document was generated by {{ $company->name }} on {{ now()->format('d M Y, H:i') }} IST &middot; Ref: {{ $refNumber }} &middot; Pulse HRMS
    </div>

</body>
</html>
