# Pulse by Conexus — Test Cases & Workflow Verification
> Version: v3.1 | Stack: Laravel 11 + MySQL 8 + Livewire 3 + Blade + Tailwind CSS
> Last updated: synced with Product Specification v3.1
> **Antigravity instruction:** Read this file at the start of every session. When a module is built or updated, run the relevant test cases below and confirm each one PASS or FAIL. Update the status column. If logic changes, add new test cases at the end of the relevant section.

---

## How to use this file (for Antigravity)

1. Before writing code for any feature, find the matching section below.
2. After building, run every test case in that section.
3. Mark each as `PASS`, `FAIL`, or `PARTIAL`.
4. If a new edge case is discovered, add it as a new row under that section.
5. Do not remove old test cases — only add or update status.

---

## Module 1 — Authentication & RBAC

### Roles defined
| Role | Who |
|---|---|
| super_admin | Mazhar |
| hr_admin | Shivani |
| director | Rustom, Nick, Nikita |
| manager | Team leads |
| finance | Emad |
| employee | All staff |

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| AUTH-01 | Employee logs in with correct credentials | Session created, redirected to employee dashboard | - |
| AUTH-02 | Employee logs in with wrong password | Error shown, no session created | - |
| AUTH-03 | Password reset email sent | Email received, link expires after use | - |
| AUTH-04 | Employee tries to access `/hr/employees` (HR-only route) | Redirected with 403 Forbidden | - |
| AUTH-05 | Finance user tries to access payroll approval | Access granted | - |
| AUTH-06 | Manager tries to access system settings | Blocked | - |
| AUTH-07 | Session expires after 8 hours inactivity | User logged out automatically | - |
| AUTH-08 | CSRF token missing on form submit | Laravel rejects request with 419 | - |
| AUTH-09 | Shivani (hr_admin) has manager-level read access across all departments | Can view all dept records | - |
| AUTH-10 | Nikita (director) is scoped to UK Sales shift team only | Cannot see IT shift team data | - |

---

## Module 2 — Employee Management

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| EMP-01 | HR Admin creates a new employee profile | Employee saved with emp_code auto-generated | - |
| EMP-02 | Assign employee to IT Shift | shift = 'it' saved on employment_details | - |
| EMP-03 | Assign employee to UK Sales Shift | shift = 'uk' saved on employment_details | - |
| EMP-04 | Assign salary_cycle = A to employee | Appears in Cycle A payroll run | - |
| EMP-05 | Assign salary_cycle = B to employee | Appears in Cycle B payroll run | - |
| EMP-06 | Move employee from Cycle A to Cycle B | Change takes effect from NEXT cycle start, not current | - |
| EMP-07 | Employee views their own profile | Can see own data only | - |
| EMP-08 | Employee tries to edit their own profile | Cannot edit (read-only for employee role) | - |
| EMP-09 | HR Admin archives an employee (offboarding) | Status set to 'inactive', record NOT deleted from DB | - |
| EMP-10 | Org chart renders from manager_id hierarchy | Correct hierarchy shown visually | - |
| EMP-11 | Probation end date set on hire | Auto-notification sent to manager 10 days before | - |
| EMP-12 | Employee on notice_period status | Status visible, accessible for exit workflow | - |

---

## Module 3 — Attendance

### Shift timing reference
| Shift | Start | End | Grace | Late flag after |
|---|---|---|---|---|
| IT Shift | 10:30 AM IST | 7:30 PM IST | 5 min | 10:36 AM |
| UK Sales Shift | 1:00 PM IST | 10:00 PM IST | 5 min | 1:06 PM |

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| ATT-01 | IT employee clocks in at 10:35 AM | late_flag = false (within grace) | - |
| ATT-02 | IT employee clocks in at 10:36 AM | late_flag = true | - |
| ATT-03 | UK Sales employee clocks in at 1:05 PM | late_flag = false | - |
| ATT-04 | UK Sales employee clocks in at 1:06 PM | late_flag = true | - |
| ATT-05 | Employee selects WFH at clock-in | work_mode = 'wfh' saved on attendance_log | - |
| ATT-06 | IP address logged on clock-in | ip_address saved, no hard block on mismatch | - |
| ATT-07 | Employee presses Start Break, then End Break | break_log entry saved with duration_minutes | - |
| ATT-08 | Employee takes multiple breaks in one day | All break segments recorded separately | - |
| ATT-09 | Total break time = 60 minutes exactly | excess_break_flag = false | - |
| ATT-10 | Total break time = 61 minutes | excess_break_flag = true, in-app notification sent to employee + manager | - |
| ATT-11 | Employee does not clock out by shift end + 1 hour | missing_checkout_flag = true, notification sent to employee | - |
| ATT-12 | total_hours = clock_out − clock_in (break NOT deducted) | Correct total_hours regardless of break duration | - |
| ATT-13 | Employee submits regularisation request | Status = pending, manager notified in-app | - |
| ATT-14 | Manager approves regularisation | attendance_log updated, original values in audit trail, employee notified | - |
| ATT-15 | Manager rejects regularisation with comment | Employee notified in-app with comment | - |
| ATT-16 | Employee works 11 hours WITHOUT approved OT request | ot_hours NOT created in overtime_records | - |
| ATT-17 | Employee works 11 hours WITH approved OT request | ot_hours = 2, overtime_records entry created | - |
| ATT-18 | Scheduler runs pulse:check-missing-checkouts at 21:00 IST | Flags all employees with no clock-out | - |
| ATT-19 | Scheduler runs pulse:check-late-arrivals at 10:45 + 13:15 IST | Correct shift late flags raised | - |
| ATT-20 | Scheduler runs pulse:check-excess-breaks at 20:00 IST | Flags employees with > 60 min break | - |

---

## Module 4 — Leave Management

### Policy reference
| Type | Code | Days | Half-day | Carry forward | Encashable |
|---|---|---|---|---|---|
| Casual/Sick Leave | CSL | 12 | Yes | Yes, no lapse | Yes, on approval |
| Mandatory December Leave | MDL | 6 | No | N/A | No |
| Comp Off | CO | Earned | No | Indefinite | No |

### Financial year: July 1 – June 30

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| LEA-01 | Employee applies for 1 CSL day | Balance checked, request sent to manager | - |
| LEA-02 | Employee applies for CSL on a date that overlaps existing approved leave | System rejects with error | - |
| LEA-03 | Employee applies for half-day CSL | half_day = true, 0.5 days deducted from balance on approval | - |
| LEA-04 | Employee applies for half-day MDL | System rejects — MDL does not allow half days | - |
| LEA-05 | Employee tries to apply leave on an MDL day | System blocks — MDL days cannot have other leave | - |
| LEA-06 | Employee applies leave on a public holiday | System blocks — cannot apply leave on public holiday | - |
| LEA-07 | Manager approves leave within 24 hours | Employee notified in-app, balance updated | - |
| LEA-08 | Manager takes no action for 24 hours | Escalation notification sent to HR Admin | - |
| LEA-09 | Manager rejects leave with comment | Employee notified in-app | - |
| LEA-10 | CSL balance not reset at July 1 (financial year rollover) | Unused CSL carries forward — NO reset | - |
| LEA-11 | financial_year column stores '2024-25' format | Correct scoping of leave balances | - |
| LEA-12 | Employee works on an MDL day | 1 Comp Off credit earned, no expiry on credit | - |
| LEA-13 | Employee applies leave on UK public holiday | Non-working day, no leave consumed | - |
| LEA-14 | Employee submits CSL encashment request | Director + HR Admin notified in-app | - |
| LEA-15 | Director approves encashment | CSL balance reduced, amount added as line item in current payroll | - |
| LEA-16 | Employee tries to encash more CSL than they have | System blocks with error | - |
| LEA-17 | Comp Off credits carry forward across financial years | No expiry date on comp_off_credits | - |

---

## Module 5 — Overtime

### Policy reference
- Pre-approval required before working late
- Flat rate: ₹100/hr
- OT hours = total_hours − 9 (standard hours)
- Only created if approved ot_request exists for that date

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| OT-01 | Employee submits OT request with date, estimated hours, reason | Status = pending, manager notified in-app | - |
| OT-02 | Manager approves OT request | Employee notified in-app | - |
| OT-03 | Manager rejects OT request | Employee notified in-app with comment | - |
| OT-04 | Employee works 11.5 hours on an approved OT date | ot_hours = 2.5, ot_amount = ₹250, overtime_records created | - |
| OT-05 | Employee works 11.5 hours WITHOUT approved OT request | No overtime_records entry created | - |
| OT-06 | OT hours appear in monthly OT summary for Finance | Finance dashboard shows correct totals | - |
| OT-07 | Finance verifies OT before payslip generation | OT records have verified_by set | - |
| OT-08 | OT amount (₹100/hr) included in net pay calculation | payslip.ot_amount = ot_hours × 100 | - |
| OT-09 | OT request pending > 24 hours | Escalation notification sent to HR Admin | - |

---

## Module 6 — Compensation & Payroll

### Salary cycles
| Cycle | Period | Pay date |
|---|---|---|
| A | 1st – 31st of month | 1st of following month |
| B | 21st – 20th of month | 21st of following month |

### Net pay formula
`net_pay = gross + ot_amount + incentives + reimbursements + encashment_amount − deductions`

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| PAY-01 | HR Admin locks attendance for Cycle A period | Attendance data frozen for that period | - |
| PAY-02 | System generates draft payslips for all Cycle A employees | Each employee in Cycle A gets a draft payslip | - |
| PAY-03 | System generates draft payslips for all Cycle B employees | Each employee in Cycle B gets a draft payslip | - |
| PAY-04 | Finance receives notification: payroll ready for review | In-app notification, not email | - |
| PAY-05 | Finance reviews and approves payroll run | Status changes to 'approved' | - |
| PAY-06 | Payslip PDF generated via dompdf after approval | PDF saved to file storage, path in payslips.pdf_path | - |
| PAY-07 | Payslip emailed to employee | Email sent with PDF attached (one of the 3 email triggers) | - |
| PAY-08 | Employee downloads payslip via signed URL | URL expires in 5 minutes | - |
| PAY-09 | Net pay = gross + OT + incentives + reimbursements + encashment − deductions | Correct arithmetic verified | - |
| PAY-10 | Incentive requires Director approval before inclusion | Incentive with status='pending' NOT included in payslip | - |
| PAY-11 | Director approves incentive | Included in next payslip | - |
| PAY-12 | Employee submits reimbursement claim with receipt | File upload validated (PDF/image only, stored outside web root) | - |
| PAY-13 | Manager approves reimbursement | Finance includes in payslip or separate payment | - |
| PAY-14 | Leave encashment amount appears in payslip | encashment_amount included in net_pay | - |
| PAY-15 | Employee views last 12 months payslips | All 12 available for PDF download | - |

---

## Module 7 — In-App Notifications

### Email is sent ONLY for:
- Payslip issued (PDF attached)
- Account created / password reset
- Final settlement on offboarding

### Everything else = in-app only

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| NOT-01 | Bell icon shows unread count badge | Badge updates every 30 seconds via Livewire polling | - |
| NOT-02 | Employee clicks bell icon | Dropdown shows recent notifications with timestamps | - |
| NOT-03 | Employee clicks "Mark all read" | All read_at set to current timestamp, badge clears | - |
| NOT-04 | Leave request submitted | Manager receives in-app notification (not email) | - |
| NOT-05 | Leave approved | Employee receives in-app notification | - |
| NOT-06 | Leave rejected | Employee receives in-app notification | - |
| NOT-07 | OT request submitted | Manager receives in-app notification | - |
| NOT-08 | Attendance regularisation submitted | Manager receives in-app notification | - |
| NOT-09 | Leave encashment submitted | Director + HR Admin receive in-app notification | - |
| NOT-10 | Excess break flag raised | Employee + Manager receive in-app notification | - |
| NOT-11 | Missing clock-out | Employee receives in-app notification | - |
| NOT-12 | Payroll ready | Finance (Emad) receives in-app notification | - |
| NOT-13 | Payslip issued | Employee receives in-app notification + email with PDF | - |
| NOT-14 | Performance review due in 7 days | Employee + Manager receive in-app notification | - |
| NOT-15 | Document requires acknowledgement | Employee receives in-app notification | - |
| NOT-16 | Document expiring in 30 days | HR Admin receives in-app notification | - |
| NOT-17 | Probation review due in 10 days | Manager + HR Admin receive in-app notification | - |
| NOT-18 | 30-day new hire check-in due | Manager receives in-app notification | - |
| NOT-19 | Notification older than 90 days | Pruned by pulse:prune-notifications scheduler | - |
| NOT-20 | No Slack notification sent for any event | Slack integration is out of scope — must never trigger | - |

---

## Module 8 — Performance Reviews

### Quarter mapping (July–June year)
| Quarter | Period |
|---|---|
| Q1 | July – September |
| Q2 | October – December |
| Q3 | January – March |
| Q4 | April – June |

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| PERF-01 | Director defines KPIs for a department at quarter start | KPIs saved with weights for that quarter and financial_year | - |
| PERF-02 | Employee submits self-assessment on each KPI | self_rating + self_comment saved | - |
| PERF-03 | Manager completes review with ratings and feedback | manager_rating, manager_comment, overall_rating saved | - |
| PERF-04 | Manager flags promotion | promotion_flag = true on performance_reviews | - |
| PERF-05 | Composite rating calculated from KPI weights | Weighted average computed correctly | - |
| PERF-06 | Review due in 7 days — reminder sent | Employee + Manager notified in-app (scheduler: Monday 09:00) | - |
| PERF-07 | Employee views full review history | All past quarters visible | - |
| PERF-08 | Finance user tries to access performance reviews | Access blocked (Finance has no access to Performance module) | - |

---

## Module 9 — Onboarding & Offboarding

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| ONB-01 | New employee created — onboarding checklist generated | All 10 tasks created with correct owner_role | - |
| ONB-02 | HR Admin marks task as complete | completed_at and completed_by saved | - |
| ONB-03 | Employee hits 30-day mark | Manager receives in-app notification for check-in | - |
| ONB-04 | Employee hits 90-day mark (probation end) | Manager + HR Admin receive in-app notification | - |
| ONB-05 | Equipment issued to employee | equipment_log entry created with serial_no, issued_at | - |
| ONB-06 | Employee equipment returned on offboarding | returned_at and condition_on_return updated | - |
| ONB-07 | HR Admin initiates offboarding | exit_records entry created with exit_type, last working day | - |
| ONB-08 | Pulse access revoked on last working day | Employee cannot log in after last working day | - |
| ONB-09 | Final settlement generated | Fed into Compensation module, email sent to employee | - |
| ONB-10 | Employee archived | status = 'inactive', record still in DB — never deleted | - |
| ONB-11 | Exit interview notes recorded | Stored in exit_records.interview_notes | - |

---

## Module 10 — Document Management

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| DOC-01 | HR Admin uploads company policy | Visible to all staff | - |
| DOC-02 | HR Admin uploads employee contract | Scoped to that employee + HR Admin + Finance only | - |
| DOC-03 | Employee tries to view another employee's contract | Access blocked | - |
| DOC-04 | HR Admin re-uploads a document | New version created, old version retained | - |
| DOC-05 | HR Admin flags document as requiring acknowledgement | Employee receives in-app notification | - |
| DOC-06 | Employee acknowledges document | document_acknowledgements record created with acknowledged_at | - |
| DOC-07 | Document with expiry_date set — 30 days before expiry | HR Admin receives in-app notification | - |
| DOC-08 | Payslip download link clicked | Temporary signed URL generated, expires in 5 minutes | - |
| DOC-09 | File upload with disallowed MIME type | Rejected — only PDF and images allowed | - |
| DOC-10 | File stored inside public web root | FAIL — must be stored outside web root | - |

---

## Module 11 — Dashboards

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| DASH-01 | Mazhar (super_admin) opens executive dashboard | Headcount, attendance %, leave overview, payroll status, performance visible | - |
| DASH-02 | Department head opens dept dashboard | Only their department's data shown | - |
| DASH-03 | Finance (Emad) opens finance dashboard | Payroll queue, OT records, incentives, reimbursements visible | - |
| DASH-04 | Employee opens self-service dashboard | Clock-in widget, leave balances, OT requests, last 12 payslips | - |
| DASH-05 | Employee sees team leave calendar | Only colleagues' names + dates, no salary/personal data | - |
| DASH-06 | Finance dashboard does NOT show individual salary structures to non-Finance | Salary data blocked for other roles | - |

---

## Module 12 — Security & Audit

### Test cases

| ID | Test | Expected result | Status |
|---|---|---|---|
| SEC-01 | HTTP request made to app | Redirected to HTTPS by Nginx | - |
| SEC-02 | SQL injection attempt via form input | Eloquent parameterised query blocks it | - |
| SEC-03 | XSS payload submitted in form | Blade `{{ }}` escapes output | - |
| SEC-04 | Employee record updated by HR Admin | audit_logs entry created with old_values + new_values JSON | - |
| SEC-05 | Leave request updated | audit_logs entry created | - |
| SEC-06 | Payslip record updated | audit_logs entry created | - |
| SEC-07 | Salary structure changed | audit_logs entry created | - |
| SEC-08 | Employee tries to view audit_logs | Access blocked — only Super Admin + HR Admin | - |
| SEC-09 | Daily backup runs | MySQL dump saved, 30-day retention via spatie/laravel-backup | - |
| SEC-10 | audit_logs records pruned | FAIL — audit_logs must NEVER be pruned (HR compliance) | - |

---

## Module 13 — Scheduled Jobs

| ID | Command | Schedule | Expected result | Status |
|---|---|---|---|---|
| CRON-01 | pulse:check-missing-checkouts | Daily 21:00 IST | All employees without clock-out flagged + notified | - |
| CRON-02 | pulse:check-late-arrivals | Daily 10:45 + 13:15 IST | Late flags raised per shift | - |
| CRON-03 | pulse:check-excess-breaks | Daily 20:00 IST | Employees with > 60 min break flagged | - |
| CRON-04 | pulse:check-leave-escalations | Hourly | Leave requests pending > 24 hrs escalated to HR Admin | - |
| CRON-05 | pulse:check-ot-escalations | Hourly | OT requests pending > 24 hrs escalated to HR Admin | - |
| CRON-06 | pulse:check-document-expiry | Daily 08:00 | Docs expiring in 30 days — HR Admin notified | - |
| CRON-07 | pulse:check-probation-due | Daily 08:00 | Probation ending in 10 days — manager + HR Admin notified | - |
| CRON-08 | pulse:check-newhire-checkin | Daily 08:00 | 30-day employees — manager notified | - |
| CRON-09 | pulse:check-review-reminders | Monday 09:00 | QBR reviews due in 7 days — employee + manager notified | - |
| CRON-10 | pulse:prune-notifications | Sunday | Notifications older than 90 days deleted | - |
| CRON-11 | pulse:generate-attendance-summary | 1st of month 01:00 | Monthly attendance summary generated per employee | - |

---

## Out of scope — do NOT build or test

These are explicitly excluded. If any of the below appears in a PR, it should be rejected.

- Third-party payroll platforms (Keka, greytHR)
- Slack, ClickUp, Google Calendar integrations
- Recruitment / ATS module
- Native mobile app (PWA-ready responsive only)
- UK statutory payroll (PAYE, NI)
- Bulk email blasts
- Redis (database queue driver used throughout)
- WebSockets (Livewire polling used for notifications)

---

## How Antigravity should confirm completion

When a module is completed, Antigravity should reply with:

```
MODULE: [Name]
TEST CASES RUN: [count]
PASS: [count]
FAIL: [count] — [brief reason]
PARTIAL: [count] — [brief reason]
REQUIREMENT STATUS: FULFILLED / NOT FULFILLED / PARTIALLY FULFILLED
NOTES: [anything that needs HR / client attention]
```

---

*End of TESTCASES.md — do not delete this file. Add new test cases at the bottom of each section as the product evolves.*
