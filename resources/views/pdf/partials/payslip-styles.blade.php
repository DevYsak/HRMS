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
body    { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111827; background: #fff; line-height: 1.5; }

/* ── Sheet ─────────────────────────────────────────────────────────────── */
.sheet      { padding: 30px 42px 26px; }
.accent-bar { height: 4px; background: {{ $accent }}; }

/* ── Header ────────────────────────────────────────────────────────────── */
.hdr            { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
.hdr td         { vertical-align: top; }
.co-logo        { height: 30px; margin-bottom: 7px; }
.co-name        { font-size: 13px; font-weight: 700; letter-spacing: -0.2px; }
.co-meta        { font-size: 8px; color: #6b7280; line-height: 1.55; }
.doc-title      { font-size: 19px; font-weight: 700; letter-spacing: 2.5px; text-align: right; color: #111827; }
.doc-month      { font-size: 10.5px; font-weight: 600; text-align: right; color: {{ $accent }}; margin-top: 1px; }
.doc-meta       { font-size: 8px; color: #6b7280; text-align: right; margin-top: 3px; line-height: 1.6; }

.rule       { border: 0; border-top: 1px solid #e5e7eb; margin: 10px 0 14px; }

/* ── Section titles ────────────────────────────────────────────────────── */
.sec        { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: #6b7280; margin: 0 0 6px; }

/* ── Employee information grid ─────────────────────────────────────────── */
.emp        { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-bottom: 14px; }
.emp td     { width: 33.333%; padding: 7px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.emp tr:last-child td { border-bottom: 0; }
.emp .lbl   { display: block; font-size: 7px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.9px; color: #9ca3af; margin-bottom: 1px; }
.emp .val   { font-size: 9.5px; font-weight: 500; color: #111827; }
.emp .val-strong { font-size: 10.5px; font-weight: 700; }

/* ── Attendance strip ──────────────────────────────────────────────────── */
.att        { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-bottom: 14px; background: #f9fafb; }
.att td     { padding: 8px 6px; text-align: center; border-right: 1px solid #e5e7eb; }
.att td:last-child { border-right: 0; }
.att .lbl   { display: block; font-size: 6.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.7px; color: #6b7280; margin-bottom: 2px; }
.att .val   { font-size: 11px; font-weight: 700; color: #111827; }

/* ── Earnings / deductions ─────────────────────────────────────────────── */
.pay-wrap        { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 0 -12px 2px; }
.pay-wrap > tbody > tr > td { width: 50%; vertical-align: top; }
.pay             { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; }
.pay thead th    { font-size: 7px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; background: #f9fafb; padding: 6px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.pay thead th.num { text-align: right; }
.pay tbody td    { padding: 5.5px 12px; font-size: 9px; border-bottom: 1px solid #f3f4f6; color: #374151; }
.pay tbody td.num { text-align: right; font-weight: 500; color: #111827; }
.pay .total td   { border-top: 1px solid #e5e7eb; border-bottom: 0; background: #f9fafb; font-weight: 700; font-size: 9.5px; color: #111827; padding: 7px 12px; }

/* ── Employer contribution + net panel row ─────────────────────────────── */
.foot-wrap       { width: 100%; border-collapse: separate; border-spacing: 12px 0; margin: 12px -12px 0; }
.foot-wrap > tbody > tr > td { width: 50%; vertical-align: top; }

.contrib         { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; }
.contrib td      { padding: 5px 12px; font-size: 8.5px; border-bottom: 1px solid #f3f4f6; color: #374151; }
.contrib td.num  { text-align: right; font-weight: 500; color: #111827; }
.contrib .total td { border-top: 1px solid #e5e7eb; border-bottom: 0; font-weight: 700; color: #111827; }
.contrib-note    { font-size: 7px; color: #9ca3af; margin-top: 4px; line-height: 1.5; }

.net             { border: 1.5px solid {{ $accent }}; border-radius: 4px; padding: 12px 16px 11px; background: #fffbf7; }
.net .lbl        { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.4px; color: #6b7280; }
.net .amt        { font-size: 21px; font-weight: 700; color: #111827; letter-spacing: -0.3px; margin: 2px 0 1px; }
.net .words      { font-size: 8px; color: #6b7280; line-height: 1.5; }
.net .credit     { font-size: 8px; font-weight: 600; color: #374151; margin-top: 5px; }

/* ── YTD strip ─────────────────────────────────────────────────────────── */
.ytd        { width: 100%; border-collapse: collapse; border: 1px solid #e5e7eb; margin-top: 14px; }
.ytd td     { padding: 7px 12px; border-right: 1px solid #f3f4f6; }
.ytd td:last-child { border-right: 0; }
.ytd .lbl   { display: block; font-size: 6.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; margin-bottom: 1px; }
.ytd .val   { font-size: 9.5px; font-weight: 700; color: #111827; }

/* ── Footer ────────────────────────────────────────────────────────────── */
.sign-wrap  { width: 100%; border-collapse: collapse; margin-top: 26px; }
.sign-wrap td { vertical-align: bottom; }
.gen-note   { font-size: 7px; color: #9ca3af; line-height: 1.6; }
.sign-box   { text-align: right; }
.sign-line  { display: inline-block; border-top: 1px solid #d1d5db; padding-top: 4px; font-size: 8px; font-weight: 600; color: #374151; min-width: 150px; text-align: center; }
.sign-co    { font-size: 7px; color: #9ca3af; text-align: right; margin-bottom: 26px; }

.contact    { border-top: 1px solid #e5e7eb; margin-top: 14px; padding-top: 8px; font-size: 7.5px; color: #6b7280; text-align: center; letter-spacing: 0.2px; }
.contact .sep { color: #d1d5db; padding: 0 6px; }
</style>
