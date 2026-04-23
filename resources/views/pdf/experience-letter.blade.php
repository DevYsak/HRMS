<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Experience Letter - {{ $employee->user->name }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            margin: 40px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 40px;
        }
        .header h1 {
            margin: 0;
            color: #1a202c;
        }
        .header p {
            margin: 5px 0 0;
            color: #718096;
            font-size: 14px;
        }
        .date {
            text-align: right;
            margin-bottom: 40px;
        }
        .content {
            font-size: 16px;
        }
        .signature {
            margin-top: 80px;
        }
        .signature p {
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Pulse HRMS</h1>
        <p>123 Business Avenue, Tech City, 10001</p>
        <p>contact@pulsehrms.com | +1 234 567 8900</p>
    </div>

    <div class="date">
        <p>Date: {{ now()->format('F d, Y') }}</p>
    </div>

    <div class="content">
        <p><strong>TO WHOMSOEVER IT MAY CONCERN</strong></p>
        
        <p>This is to certify that <strong>{{ $employee->user->name }}</strong> was employed with Pulse HRMS from <strong>{{ $employee->joining_date->format('F d, Y') }}</strong> to <strong>{{ \Carbon\Carbon::parse($employee->exitRecord->last_working_day)->format('F d, Y') }}</strong>.</p>
        
        <p>During their tenure, they held the position of <strong>{{ $employee->jobTitle->title ?? 'Employee' }}</strong> in the <strong>{{ $employee->department->name ?? 'General' }}</strong> department.</p>
        
        <p>They have successfully completed their notice period and all clearance formalities as part of the exit process. We found their conduct and character to be professional and satisfactory during their association with our organization.</p>
        
        <p>We wish them all the best in their future endeavors.</p>
    </div>

    <div class="signature">
        <p>Sincerely,</p>
        <br><br><br>
        <p><strong>Authorized Signatory</strong></p>
        <p>Human Resources Department</p>
        <p>Pulse HRMS</p>
    </div>
</body>
</html>
