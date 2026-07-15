@php
    // Brand palette for the payslip stylesheet. Kept here so this partial is
    // self-contained: it renders in <head>, before the body partial's @php runs.
    $orange = $orange ?? '#f97316';
    $green = $green ?? '#16a34a';
@endphp
<style>
@page  { margin: 0; size: A4 portrait; }
*      { margin: 0; padding: 0; box-sizing: border-box; }
body   { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10px; color: #1f2937; background: #fff; line-height: 1.45; }

.top-bar  { height: 5px; background: {{ $orange }}; }
.btm-bar  { background: {{ $orange }}; padding: 9px 0; }
.page     { padding: 0 32px; }

/* ── HEADER ── */
.hdr      { padding: 15px 0 13px; border-bottom: 1.5px solid #e5e7eb; }
.hdr-tbl  { width: 100%; border-collapse: collapse; }
.h-logo   { width: 130px; vertical-align: middle; }
.h-center { vertical-align: middle; text-align: center; padding: 0 10px; }
.h-right  { width: 175px; text-align: right; vertical-align: middle; }

.co-name  { font-size: 13px; font-weight: 800; color: #111; }
.co-addr  { font-size: 8px; color: #6b7280; margin-top: 3px; line-height: 1.8; }
.co-cin   { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

.slip-ttl { font-size: 27px; font-weight: 900; color: #111; letter-spacing: 1.5px; line-height: 1; }
.slip-mo  { font-size: 15px; font-weight: 800; color: {{ $orange }}; margin-top: 3px; }
.cyc-pill {
    display: block; margin-top: 7px; background: #fff7ed;
    border: 1px solid #fed7aa; color: #9a3412;
    font-size: 7px; font-weight: 700; padding: 3px 8px; border-radius: 4px;
}

/* ── EMPLOYEE ── */
.emp      { padding: 14px 0 12px; border-bottom: 1.5px solid #e5e7eb; }
.emp-tbl  { width: 100%; border-collapse: collapse; table-layout: fixed; }
.ec-name  { width: 26%; vertical-align: top; padding-right: 12px; }
.ec-mid   { width: 37%; vertical-align: top; border-left: 1px solid #f3f4f6; padding-left: 16px; }
.ec-rgt   { width: 37%; vertical-align: top; border-left: 1px solid #f3f4f6; padding-left: 16px; }
.e-name   { font-size: 16px; font-weight: 900; color: #111; }
.e-role   { font-size: 10px; color: {{ $orange }}; font-weight: 700; margin-top: 2px; }
.e-eid    { font-size: 8px; color: #6b7280; margin-top: 7px; font-weight: 600; }

.fi           { margin-bottom: 8px; }
.fi:last-child{ margin-bottom: 0; }
.fl           { font-size: 7.5px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 1px; }
.fv           { font-size: 9px; font-weight: 700; color: #111; display: block; }

/* ── SALARY SUMMARY ── */
.sum-bg   { background: #f9fafb; padding: 13px 32px; border-bottom: 1.5px solid #e5e7eb; margin: 0 -32px; }
.sum-tbl  { width: 100%; border-collapse: separate; border-spacing: 10px 0; }

.sc {
    border: 1.5px solid #e5e7eb; background: #fff; border-radius: 10px;
    padding: 14px 16px; vertical-align: middle; width: 33%;
}
.sc-net {
    border: 1.5px solid #86efac; background: #f0fdf4; border-radius: 10px;
    padding: 14px 16px; vertical-align: middle; width: 33%;
}
.sc-ico  { display: inline-block; vertical-align: middle; }
.sc-ico-e{ width: 38px; height: 38px; border-radius: 50%; background: #fff7ed;
           text-align: center; padding-top: 10px; display: inline-block; }
.sc-ico-d{ width: 38px; height: 38px; border-radius: 50%; background: #fef2f2;
           text-align: center; padding-top: 10px; display: inline-block; }
.sc-ico-n{ width: 38px; height: 38px; border-radius: 50%; background: #dcfce7;
           text-align: center; padding-top: 10px; display: inline-block; }
.sc-body  { display: inline-block; vertical-align: middle; padding-left: 11px; }
.sc-lbl   { font-size: 7.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #6b7280; }
.sc-amt   { font-size: 17px; font-weight: 900; color: #111; line-height: 1.25; margin-top: 2px; }
.sc-amt-d { font-size: 17px; font-weight: 900; color: #dc2626; line-height: 1.25; margin-top: 2px; }
.sc-amt-n { font-size: 17px; font-weight: 900; color: {{ $green }}; line-height: 1.25; margin-top: 2px; }
.sc-sub   { font-size: 7.5px; color: #9ca3af; margin-top: 2px; }

/* ── SALARY COMPARISON (this vs previous month) ── */
.cmp-wrap  { padding: 2px 0 8px; }
.cmp-tbl   { width: 100%; border-collapse: collapse; margin-top: 8px; border: 1px solid #e5e7eb; table-layout: fixed; }
.cmp-th    { font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.4px; color: #6b7280;
             padding: 7px 12px; background: #f9fafb; border-bottom: 1.5px solid #e5e7eb; text-align: right; }
.cmp-th-l  { text-align: left; }
.cmp-th-c  { color: {{ $orange }}; }
.cmp-td    { font-size: 9.5px; padding: 6.5px 12px; text-align: right; font-weight: 700; color: #111; border-bottom: 1px solid #f9fafb; }
.cmp-td-l  { text-align: left; font-weight: 600; color: #374151; }
.cmp-td-c  { background: #fff7ed; }
.cmp-up    { color: #16a34a; }
.cmp-dn    { color: #dc2626; }
.cmp-flat  { color: #9ca3af; }
.cmp-note  { font-size: 8px; color: #9ca3af; font-style: italic; margin-top: 5px; }

/* ── EARNINGS TABLE ── */
.tbl-wrap { padding: 0 0 12px; }
.sal-tbl  { width: 100%; border-collapse: collapse; margin-top: 12px; table-layout: fixed; border: 1px solid #e5e7eb; }

.eth { width: 36%; font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.4px;
       color: {{ $orange }}; padding: 8px 13px 7px; border-bottom: 2px solid {{ $orange }};
       background: #fff7ed; text-align: left; }
.ath { width: 14%; font-size: 8px; font-weight: 900; text-transform: uppercase; color: {{ $orange }};
       padding: 8px 13px 7px; border-bottom: 2px solid {{ $orange }}; background: #fff7ed; text-align: right; }
.dth { width: 36%; font-size: 8px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.4px;
       color: {{ $orange }}; padding: 8px 13px 7px; border-bottom: 2px solid {{ $orange }};
       background: #fff7ed; text-align: left; border-left: 2px solid #e5e7eb; }
.xth { width: 14%; font-size: 8px; font-weight: 900; text-transform: uppercase; color: {{ $orange }};
       padding: 8px 13px 7px; border-bottom: 2px solid {{ $orange }}; background: #fff7ed; text-align: right; }

.etd { padding: 6.5px 13px; font-size: 9.5px; color: #374151; border-bottom: 1px solid #f9fafb; }
.atd { padding: 6.5px 13px; font-size: 9.5px; text-align: right; font-weight: 600; color: #111; border-bottom: 1px solid #f9fafb; }
.dtd { padding: 6.5px 13px; font-size: 9.5px; color: #374151; border-bottom: 1px solid #f9fafb; border-left: 2px solid #f3f4f6; }
.xtd { padding: 6.5px 13px; font-size: 9.5px; text-align: right; font-weight: 600; color: #374151; border-bottom: 1px solid #f9fafb; }

.tot-e { padding: 8px 13px; font-size: 10px; font-weight: 900; color: {{ $orange }}; background: #fff7ed; border-top: 2px solid #fed7aa; }
.tot-a { padding: 8px 13px; font-size: 10px; font-weight: 900; color: {{ $orange }}; text-align: right; background: #fff7ed; border-top: 2px solid #fed7aa; }
.tot-d { padding: 8px 13px; font-size: 10px; font-weight: 900; color: #dc2626; background: #fff7ed; border-left: 2px solid #e5e7eb; border-top: 2px solid #fed7aa; }
.tot-x { padding: 8px 13px; font-size: 10px; font-weight: 900; color: #dc2626; text-align: right; background: #fff7ed; border-top: 2px solid #fed7aa; }

/* ── ATTENDANCE ── */
.att-wrap  { padding: 0 0 12px; }
.att-head  { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
             color: #111; padding: 10px 0 8px; border-top: 1.5px solid #e5e7eb; }
.att-tbl   { width: 100%; border-collapse: separate; border-spacing: 8px 0; }

/* Attendance card */
.ac {
    border: 1.5px solid #e5e7eb; background: #fff; border-radius: 10px;
    padding: 16px 10px 14px; text-align: center; width: 25%; vertical-align: top;
}
.ac-ico-wrap { margin-bottom: 8px; }
.ac-ico {
    display: inline-block; width: 42px; height: 42px; border-radius: 12px;
    padding-top: 11px; text-align: center;
}
.aci-blue   { background: #eff6ff; }
.aci-green  { background: #f0fdf4; }
.aci-purple { background: #f5f3ff; }
.aci-red    { background: #fff7ed; }

.ac-num    { font-size: 26px; font-weight: 900; color: #111; line-height: 1; margin-bottom: 5px; }
.ac-num-o  { font-size: 26px; font-weight: 900; color: {{ $orange }}; line-height: 1; margin-bottom: 5px; }
.ac-lbl    { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #9ca3af; }

/* ── BOTTOM 3-COL ── */
.bot-wrap { padding: 12px 0; border-top: 1.5px solid #e5e7eb; }
.bot-tbl  { width: 100%; border-collapse: collapse; }
.bc1  { vertical-align: top; width: 31%; padding-right: 18px; border-right: 1px solid #f3f4f6; }
.bc2  { vertical-align: top; width: 38%; padding: 0 18px; border-right: 1px solid #f3f4f6; }
.bc3  { vertical-align: top; width: 31%; padding-left: 18px; }

/* Section header with SVG icon */
.bh-tbl   { border-collapse: collapse; margin-bottom: 10px; }
.bh-ico   { vertical-align: middle; width: 22px; }
.bh-txt   { vertical-align: middle; padding-left: 7px;
            font-size: 9px; font-weight: 900; text-transform: uppercase;
            letter-spacing: 0.4px; color: #111; white-space: nowrap; }

.br    { display: table; width: 100%; margin-bottom: 6px; }
.brl   { display: table-cell; font-size: 8.5px; color: #6b7280; width: 45%; vertical-align: top; }
.brc   { display: table-cell; font-size: 8.5px; color: #d1d5db; width: 5%; }
.brv   { display: table-cell; font-size: 9px; font-weight: 700; color: #111; }
.brv-g { display: table-cell; font-size: 9px; font-weight: 700; color: {{ $green }}; }

/* ── SALARY CREDITED ── */
.sal-wrap { padding: 10px 0; border-top: 1.5px solid #e5e7eb; }
.sal-tbl  { width: 100%; border-collapse: collapse; }
.sal-box-td  { width: 58%; vertical-align: middle; }
.sal-note-td { vertical-align: middle; text-align: right; }

.sal-box { border: 1.5px solid #86efac; background: #f0fdf4; border-radius: 10px; padding: 12px 16px; }
.sal-inner { border-collapse: collapse; }
.sal-ico-td { vertical-align: middle; width: 42px; }
.sal-txt-td { vertical-align: middle; padding-left: 12px; }
.sal-ttl { font-size: 10px; font-weight: 900; color: {{ $green }}; }
.sal-sub { font-size: 8.5px; color: #374151; margin-top: 3px; line-height: 1.6; }
.sys-note { font-size: 8.5px; color: #6b7280; line-height: 1.8; font-style: italic; }

/* ── BOTTOM BAR ── */
.btm-inner { width: 100%; border-collapse: collapse; }
.btm-td    { text-align: center; padding: 0 10px; border-right: 1px solid rgba(255,255,255,0.3); }
.btm-td:last-child { border-right: none; }
.btm-lbl   { font-size: 7px; color: rgba(255,255,255,0.75); text-transform: uppercase; letter-spacing: 0.8px; display: block; margin-bottom: 1px; }
.btm-val   { font-size: 8.5px; font-weight: 700; color: #fff; }
</style>
