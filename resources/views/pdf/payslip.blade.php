<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Payslip</title>
@include('pdf.partials.payslip-styles')
</head>
<body>
@include('pdf.partials.payslip-body', ['payslip' => $payslip])
</body>
</html>
