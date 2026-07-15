<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Payslips</title>
@include('pdf.partials.payslip-styles')
<style>
/* Each payslip owns a full A4 sheet; the first must not force a leading blank page. */
.payslip-sheet { page-break-after: always; }
.payslip-sheet:last-child { page-break-after: auto; }
</style>
</head>
<body>
@foreach ($payslips as $slip)
    <div class="payslip-sheet">
        @include('pdf.partials.payslip-body', ['payslip' => $slip])
    </div>
@endforeach
</body>
</html>
