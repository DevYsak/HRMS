# NEXCORE / Pulse HRMS — Role Journey Checklist

All items start **NOT TESTED**. Use staging test accounts from the Master Plan; record screenshot names and bug IDs beside each item.

| Role | Login / scope | Expected navigation and dashboard | Allowed journey | Forbidden / direct URL checks | Approval & notifications |
|---|---|---|---|---|---|
| Super Admin | `sa.qa@…`; all departments | Global dashboard; Employees, Attendance, Leave, OT, Payroll, Performance, Documents, Reports, Settings, Audit, Help | Configure authorised staging data; view global reports/audit; assign roles | None except production-destructive actions never tested | May administer configured flows; receives global operational alerts |
| HR Admin | `hr.qa@…`; company HR scope | HR dashboard; HR operations, attendance, leave, onboarding/offboarding, documents, authorised settings | Create/manage employees; approve regularisation; HR leave/encashment action; HR operations | Super-Admin-only purge/force-delete; confirm 403 by URL | HR queues, document/probation/onboarding, HR final action where policy says so |
| Director / Department Head | `sales.head.qa@…`; Sales only | Sales dashboard; scoped team/department attendance, leave, OT, performance, reports | Sales approvals, Sales review/reports, Director encashment final approval | Marketing employee/profile/attendance/leave/performance/documents; salary/payroll unless explicitly granted; settings | Sales approval alerts; no company-wide leakage (D1) |
| Manager | `sales.manager.qa@…`; direct reports | Team dashboard; team attendance/leave/OT/performance | Approve standard leave and team OT; manager reviews; team reports | Other team/department, payroll/finance/settings/HR admin pages | Direct-report leave/OT/performance notifications; standard leave decision final (D2) |
| Finance | `finance.qa@…`; finance-authorised people | Finance dashboard; payroll, approvals, incentives, reimbursement, encashment, authorised summaries | Reconcile, Finance approve/finalise/lock payroll, view finance profiles | HR settings, employee lifecycle, unrelated KYC/documents, non-finance team data | Finance queue; Finance step mandatory in payroll; no encashment final step (D3) |
| Coordinator | `coord.sales.qa@…`; assigned Sales people only | Exception dashboard/menu only | Review/remind/escalate assigned attendance exceptions | Employee records, payroll, leave decisions, other departments, settings | Assigned exception notifications only |
| Employee | `sales.emp.qa@…`; self | Employee dashboard; My Attendance, Leave, OT, Payslips (final only), Profile, Documents, Notifications, Onboarding, Help | Clock, breaks, request leave/OT, view own final payslip/docs, update own permitted profile | HR/Finance/manager/team pages, other employee data/payslip/docs | Own workflow notifications; no draft payslip |

## Complete UAT journeys

- **Super Admin:** Login → global dashboard → role/permission view → company report → audit log → settings read-only review → Help → logout.
- **HR Admin:** Login → HR dashboard → create/edit staging employee → onboarding → attendance exception/regularisation → leave management → document → HR notification → logout.
- **Director:** Login → department dashboard → Sales attendance → Sales leave approval → Sales OT approval → performance review → department report → attempt Marketing URL (deny) → logout.
- **Manager:** Login → team dashboard → direct report attendance → standard leave approval → OT decision → performance review → team report → notifications → logout.
- **Finance:** Login → finance dashboard → Cycle A/B draft reconciliation → Finance approval → finalisation/lock in staging → eligible payslip review → reimbursement/incentive/arrears → logout.
- **Coordinator:** Login → assigned exception queue → remind/escalate one staged exception → attempt non-assigned/HR URL (deny) → notifications → logout.
- **Employee:** Login → dashboard → clock/attendance → break → leave balance/request → OT request → notifications → final payslip → profile/documents/onboarding → Help → logout.

For every journey capture dashboard and sidebar screenshots. Mark completion only when every required page loads, expected scope is correct, and forbidden direct URLs are denied without data leakage.
