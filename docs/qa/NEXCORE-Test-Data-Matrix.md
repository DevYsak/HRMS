# NEXCORE / Pulse HRMS — Safe QA Test Data Matrix

**Status:** Pre-execution baseline — all test accounts and records are to be created in local/staging only.  
**Build / commit:** `PENDING TESTED DEVELOPMENT BUILD`  
**Data rule:** Use fictional names, non-routable QA mailboxes, masked identifiers, and test documents only. Do not create, alter, approve, finalise, or delete production employee, attendance, leave, or payroll data.

## Department and reporting topology

```text
NEXCORE QA Company
├─ Sales A
│  ├─ Director / Department Head A
│  └─ Manager A
│     ├─ Employee A
│     └─ Coordinator (assigned to Sales A exceptions only)
└─ Marketing B
   ├─ Director / Department Head B
   └─ Manager B
      └─ Employee B
```

Finance, HR Admin and Super Admin are company-level test accounts. They must not be assigned as reporting managers for Employee A/B. Manager A must manage Employee A only; Manager B must manage Employee B only. This separation is mandatory for cross-department and direct-team isolation checks.

## Account matrix

| Test user / suggested mailbox | Role | Department | Reporting manager | Expected scope | Allowed modules (subject to final permissions) | Forbidden modules / data |
|---|---|---|---|---|---|---|
| `qa.superadmin@nexcore.test` | Super Admin | Corporate | — | All QA company departments and records | All enabled modules: dashboard, employees, attendance, leave, OT, payroll, performance, documents, reports, settings, audit, Help | None by role; destructive controls remain out of test scope |
| `qa.hradmin@nexcore.test` | HR Admin | HR | — | Company-wide HR scope | HR dashboard, employee lifecycle, onboarding/offboarding, attendance/regularisation, leave management, documents, authorised reports/settings, Help | Super-Admin-only purge/force-delete; any permission omitted in final matrix |
| `qa.sales.head@nexcore.test` | Director / Department Head A | Sales A | — | Sales A department only | **Department View** (`/dashboard/department`), Sales A attendance/leave/OT/performance/reports, configured approval work, Help | Marketing B data; global HR/settings; payroll/salary/payslips unless final permission explicitly grants them |
| `qa.marketing.head@nexcore.test` | Director / Department Head B | Marketing B | — | Marketing B department only | **Department View** (`/dashboard/department`), Marketing B attendance/leave/OT/performance/reports, configured approval work, Help | Sales A data; global HR/settings; payroll/salary/payslips unless final permission explicitly grants them |
| `qa.sales.manager@nexcore.test` | Manager A | Sales A | Director A | Direct report Employee A / configured team only | Team dashboard, Employee A attendance/leave/OT approvals, performance review, team reports, Help | Marketing B; Director A private data; non-reporting Sales data; payroll, HR settings, global operations |
| `qa.marketing.manager@nexcore.test` | Manager B | Marketing B | Director B | Direct report Employee B / configured team only | Team dashboard, Employee B attendance/leave/OT approvals, performance review, team reports, Help | Sales A; Director B private data; non-reporting Marketing data; payroll, HR settings, global operations |
| `qa.finance@nexcore.test` | Finance | Finance | — | Finance-authorised records only | Finance dashboard, payroll process/approval, eligible payslips, incentives, reimbursements, arrears, finance reports, Help | Employee lifecycle, non-authorised KYC/medical docs, HR settings, unrelated department people data |
| `qa.sales.coordinator@nexcore.test` | Coordinator | Sales A | Director A | Assigned Sales A attendance exceptions only | Exception queue, permitted reminder/escalation actions, notifications, Help | Employee lifecycle, leave/OT/payroll approval, performance, settings, Marketing B or unassigned Sales data |
| `qa.sales.employee@nexcore.test` | Employee A | Sales A | Manager A | Own record only | Own dashboard, attendance/breaks, leave/OT requests, final payslips, own documents/profile/onboarding, notifications, Help | Other employee records, team/HR/Finance/settings pages, draft payslips |
| `qa.marketing.employee@nexcore.test` | Employee B | Marketing B | Manager B | Own record only | Own dashboard, attendance/breaks, leave/OT requests, final payslips, own documents/profile/onboarding, notifications, Help | Other employee records, team/HR/Finance/settings pages, draft payslips |

## Required safe fixture records

| Fixture | Owner / department | Purpose | Safe constraints |
|---|---|---|---|
| Employee A / B profile pair | Sales A / Marketing B | Cross-department direct URL, document, leave, performance and payslip denial | Fictional personal/bank/KYC values only |
| Employee A attendance day | Sales A | Face IN/Card OUT, duplicate/stray, break, missing checkout, regularisation and OT tests | Staging device simulator only; no production `/iclock` traffic |
| Employee B attendance day | Marketing B | Isolation target for Sales A roles | No real biometric identifier |
| IT and UK shifts | Sales A/Marketing B | Late, overnight and excess-break timing | Date/time values chosen outside production feeds |
| Public holiday and MDL date | Shared QA calendar | CSL/MDL/Comp Off rule checks | QA calendar entries only |
| Leave balances | Employee A/B | CSL, MDL, Comp Off, half-day, overlap and encashment scenarios | Record opening balance and reset between cases |
| Cycle A and Cycle B payroll fixtures | Employee A/B or dedicated payroll-only test staff | Cycle move, HRA, eligibility, statutory, LOP, payslip visibility | Isolated test payroll company/period; never production finalisation |
| Closed prior / open next payroll period | Finance QA | Arrears tests for OT, reimbursement, incentive, encashment | Snapshot/reset-able; test items uniquely labelled |
| Document versions/evidence | Employee A | Version, acknowledgement, expiry and unauthorised URL tests | Harmless sample files; no medical or identity evidence |
| Performance cycle/template | Employee A/B | Self-score, manager review, scope and final score | Clearly labelled `QA ONLY` |
| Mail sink / notification inbox | All roles | Notification recipient, URL, unread/badge and email-default checks | Non-deliverable test domain |

## Setup and reset controls

1. Capture the tested commit/build, schema version, seeded data reference, and timezone before creating fixtures.
2. Grant only the final committed permissions and explicit test overrides; document every override with its expiry/reset owner.
3. Label all records `QA ONLY — DO NOT USE FOR LIVE OPERATIONS`.
4. Take a pre-wave data snapshot or use an agreed reseed script in local/staging.
5. Reset or recreate contaminated fixtures between payroll, leave, and attendance mutation scenarios; record the reset reference.
6. Do not create comparable fixtures in production. Production uses the reduced read-only smoke checklist in the execution order document.
