# NEXCORE / Pulse HRMS — Security & Access Checklist

Execute on local/staging only, in a fresh private window for each role. Status begins **NOT TESTED**. “Deny” means safe 403/redirect/no sensitive content, not merely a hidden menu.

| Test ID | Role | Attempt | Expected result | Screenshot |
|---|---|---|---|---|
| SEC-001 | Employee | Change `/employees/{own}` to another employee profile/edit/finance profile | Deny or only authorised directory-safe fields; no salary/KYC | YES |
| SEC-002 | Employee | Change attendance/leave/OT/performance/payslip IDs to another employee | Deny; no data in HTML, modal or download | YES |
| SEC-003 | Manager | Open non-reporting Sales and Marketing employee attendance/leave/performance URLs | Deny outside direct team | YES |
| SEC-004 | Director | Open Marketing profile, attendance, leave, performance, documents and salary URLs | Deny outside assigned Sales scope (D1) | YES |
| SEC-005 | Coordinator | Open non-assigned exceptions, employee, payroll, leave and settings URLs | Deny; only assigned exception scope | YES |
| SEC-006 | Finance | Open HR employee lifecycle, KYC/document, settings and non-authorised team data URLs | Deny | YES |
| SEC-007 | HR Admin | Open Super-Admin-only purge/force-delete route/action | Deny; do not submit destructive action | YES |
| SEC-008 | All non-Finance | Open payroll process, finance approval, structures and bulk payslip URLs | Deny unless explicitly granted | YES |
| SEC-009 | All non-HR | Open role manager, module settings, approval settings and import/export URLs | Deny unless permission explicitly granted | YES |
| SEC-010 | Logged-out | Open copied payslip, document controller and `/storage/...` URLs | Deny; no file/name/net pay/metadata | YES |
| SEC-011 | Other Employee | Open copied authorised document/payslip URL | Deny; signed/self-service access is owner-scoped | YES |
| SEC-012 | Any | Submit hidden/disabled action via copied URL/request only when test tooling is approved | Server denies and does not mutate | YES |
| SEC-013 | Any | Trigger invalid route/form/session and inspect error | 403/404/419/422/500 safe: no stack trace, SQL, paths, tokens or DB details | YES |
| SEC-014 | Employee | Logout then Back / copied authenticated page | No cached sensitive page/action; re-authentication required | YES |
| SEC-015 | QA security tester | Inspect staging `/iclock` only with written approval | Endpoint must be protected/throttled/IP-restricted; never send production punches | YES |

For every FAIL preserve: full page, focused area, request URL/method/status, role, target record, and any console/network evidence. Never include credentials, tokens, real personal data, or production document contents in the bug attachment.
