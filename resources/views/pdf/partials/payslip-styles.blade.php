@php
    // Brand accent for the payslip; company primary colour wins when set.
    if (! isset($accent)) {
        $accentCompany = \App\Models\Company::first();
        $accent = ($accentCompany && $accentCompany->primary_color) ? $accentCompany->primary_color : '#f97316';
    }

    // DomPDF cannot fetch web fonts — Inter is embedded from local TTFs.
    // Forward slashes: backslashed Windows paths break DomPDF's url() parsing.
    $interDir = str_replace('\\', '/', public_path('fonts/inter'));
@endphp
<style>
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400;
    src: url('{{ $interDir }}/Inter-Regular.ttf') format('truetype');
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 500;
    src: url('{{ $interDir }}/Inter-Medium.ttf') format('truetype');
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 600;
    src: url('{{ $interDir }}/Inter-SemiBold.ttf') format('truetype');
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 700;
    src: url('{{ $interDir }}/Inter-Bold.ttf') format('truetype');
}

@page   { margin: 0; size: A4 portrait; }
*       { margin: 0; padding: 0; box-sizing: border-box; }
body    { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1f2937; background: #fff; line-height: 1.5; }

/* ── Sheet ─────────────────────────────────────────────────────────────── */
.sheet      { padding: 34px 48px 28px; }
.accent-bar { height: 3px; background: {{ $accent }}; }

/* ── Header ────────────────────────────────────────────────────────────── */
.hdr            { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.hdr td         { vertical-align: top; }
.co-logo        { height: 30px; margin-bottom: 8px; }
.co-name        { font-size: 13.5px; font-weight: 700; letter-spacing: -0.2px; color: #111827; }
.co-meta        { font-size: 8px; color: #9ca3af; line-height: 1.6; margin-top: 2px; }
.doc-title      { font-size: 20px; font-weight: 700; letter-spacing: 3px; text-align: right; color: #111827; }
.doc-month      { font-size: 10.5px; font-weight: 600; text-align: right; color: {{ $accent }}; margin-top: 2px; }
.doc-meta       { font-size: 8px; color: #9ca3af; text-align: right; margin-top: 5px; line-height: 1.7; }

.rule       { border: 0; border-top: 1px solid #e5e7eb; margin: 12px 0 0; }

/* ── Section titles ────────────────────────────────────────────────────── */
.sec        { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: #9ca3af; margin: 18px 0 8px; }

/* ── Employee information card ─────────────────────────────────────────── */
.emp        { width: 100%; border-collapse: collapse; background: #fafafa; border: 1px solid #e5e7eb; border-radius: 6px; }
.emp td     { width: 33.333%; padding: 9px 16px; vertical-align: top; }
.emp tr + tr td { padding-top: 0; }
.emp .lbl   { display: block; font-size: 6.8px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 2px; }
.emp .val   { font-size: 9.5px; font-weight: 500; color: #111827; }
.emp .val-strong { font-size: 11px; font-weight: 700; }

/* ── Attendance KPI cards ──────────────────────────────────────────────── */
.kpis       { width: 100%; border-collapse: separate; border-spacing: 7px 0; margin: 0 -7px; }
.kpis td    { background: #fff; border: 1px solid #e5e7eb; border-radius: 5px; padding: 8px 4px 7px; text-align: center; }
.kpis .lbl  { display: block; font-size: 6.4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; color: #9ca3af; margin-bottom: 3px; }
.kpis .val  { font-size: 12.5px; font-weight: 700; color: #111827; }
.kpis td.hot { background: #fafafa; }
.kpis td.hot .val { color: {{ $accent }}; }

/* ── Earnings / deductions ─────────────────────────────────────────────── */
.pay-wrap        { width: 100%; border-collapse: separate; border-spacing: 14px 0; margin: 0 -14px; }
.pay-wrap > tbody > tr > td { width: 50%; vertical-align: top; }
.pay             { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; border-radius: 6px; }
.pay thead th    { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; background: #fafafa; padding: 8px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.pay thead th.num { text-align: right; }
.pay tbody td    { padding: 7px 14px; font-size: 9px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }
.pay tbody td.num { text-align: right; font-weight: 500; color: #111827; }
.pay .total td   { border-top: 1px solid #e5e7eb; border-bottom: 0; background: #fafafa; font-weight: 700; font-size: 9.5px; color: #111827; padding: 8.5px 14px; }

/* ── Employer contribution + net panel row ─────────────────────────────── */
.foot-wrap       { width: 100%; border-collapse: separate; border-spacing: 14px 0; margin: 14px -14px 0; }
.foot-wrap > tbody > tr > td { width: 50%; vertical-align: top; }

.contrib         { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; border-radius: 6px; }
.contrib th      { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #6b7280; background: #fafafa; padding: 7px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.contrib td      { padding: 6px 14px; font-size: 8.5px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }
.contrib td.num  { text-align: right; font-weight: 500; color: #111827; }
.contrib .total td { border-top: 1px solid #e5e7eb; border-bottom: 0; font-weight: 700; color: #111827; background: #fafafa; }
.contrib-note    { font-size: 7px; color: #9ca3af; margin-top: 6px; line-height: 1.6; padding: 0 2px; }

.net             { border: 1px solid #e5e7eb; border-left: 3px solid {{ $accent }}; border-radius: 6px; padding: 14px 18px 13px; background: #fafafa; }
.net .lbl        { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.6px; color: #6b7280; }
.net .amt        { font-size: 22px; font-weight: 700; color: #111827; letter-spacing: -0.4px; margin: 3px 0 2px; }
.net .words      { font-size: 8px; color: #6b7280; line-height: 1.55; }
.net .credit     { font-size: 8px; font-weight: 600; color: #374151; margin-top: 7px; }

/* ── YTD strip ─────────────────────────────────────────────────────────── */
.ytd        { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; border-radius: 6px; margin-top: 16px; background: #fafafa; }
.ytd td     { padding: 9px 16px; }
.ytd .lbl   { display: block; font-size: 6.6px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.9px; color: #9ca3af; margin-bottom: 2px; }
.ytd .val   { font-size: 10px; font-weight: 700; color: #111827; }

/* ── Footer: QR / note / signature ─────────────────────────────────────── */
.sign-wrap  { width: 100%; border-collapse: collapse; margin-top: 30px; }
.sign-wrap td { vertical-align: bottom; }
.qr-box     { width: 92px; }
.qr-box img { width: 66px; height: 66px; }
.qr-cap     { font-size: 6.5px; color: #9ca3af; margin-top: 3px; line-height: 1.5; }
.gen-note   { font-size: 7px; color: #9ca3af; line-height: 1.7; padding-left: 14px; }
.sign-box   { text-align: right; }
.sign-line  { display: inline-block; border-top: 1px solid #d1d5db; padding-top: 5px; font-size: 8px; font-weight: 600; color: #374151; min-width: 150px; text-align: center; }
.sign-co    { font-size: 7px; color: #9ca3af; text-align: right; margin-bottom: 30px; }

.contact    { border-top: 1px solid #e5e7eb; margin-top: 16px; padding-top: 9px; font-size: 7.5px; color: #9ca3af; text-align: center; letter-spacing: 0.2px; }
.contact .sep { color: #d1d5db; padding: 0 7px; }
</style>
