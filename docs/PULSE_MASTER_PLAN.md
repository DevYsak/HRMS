# Pulse HRMS — Master Plan & Live-Readiness Tracker

**Spec version**: Pulse by Conexus v3.1  
**FY**: July 1 – June 30  
**Last updated**: 2026-04-29  
**Status**: Active development → staging

---

## Sitemap (by role)

### All authenticated users
| Path | Component | Notes |
|------|-----------|-------|
| `/` | `Dashboard` | Role-dispatches to the correct sub-dashboard |
| `/attendance/my` | `Attendance\AttendanceTracker` | Clock-in/out, breaks, regularisation |
| `/time-off/my` | `TimeOff\MyTimeOff` | Leave request, encashment, balances |
| `/overtime/my` | `Overtime\MyOtRequests` | Submit + view OT requests |
| `/payroll/my-payslips` | `Payroll\MyPayslips` | View + download payslips |
| `/performance/my` | `Performance\MyReview` | Self-assessment |
| `/performance/goals` | `Performance\Goals` | Personal goals |
| `/documents` | `Documents\DocumentManager` | View and acknowledge documents |
| `/operations/expenses` | `Operations\Expenses` | Submit expense claims |

### Manager (`approve-leave`, `approve-ot`)
| Path | Component | Notes |
|------|-----------|-------|
| `/attendance/team` | `Attendance\TeamAttendance` | Team attendance + regularisation review |
| `/time-off/team` | `TimeOff\TeamTimeOff` | Approve/reject team leave |
| `/overtime/manage` | `Overtime\ManageOtRequests` | Approve/reject OT requests |
| `/performance/team` | `Performance\TeamReviews` | Manager review step |

### HR Admin / Director / Super Admin (`manage-employees`, `manage-settings`)
| Path | Component | Notes |
|------|-----------|-------|
| `/employees` | `Employees\EmployeeIndex` | Full employee list |
| `/employees/create` | `Employees\EmployeeCreate` | Create employee + user |
| `/employees/{id}/edit` | `Employees\EmployeeEdit` | Edit profile |
| `/employees/directory` | `Employees\Directory` | Org directory |
| `/employees/org-chart` | `Employees\OrgChart` | Hierarchy chart |
| `/employees/{id}/onboarding` | `Onboarding\OnboardingChecklist` | Onboarding tasks |
| `/employees/offboarding-manager` | `Onboarding\OffboardingManager` | Offboarding + equipment |
| `/attendance/employees` | `Attendance\AllAttendance` | All attendance + regularisation |
| `/time-off/employees` | `TimeOff\AllTimeOff` | All leave requests |
| `/time-off/settings` | `TimeOff\TimeOffSettings` | Leave type config |
| `/attendance/settings` | `Attendance\AttendanceSettings` | Shift / grace config |
| `/performance/employees` | `Performance\AllReviews` | All reviews + lock |
| `/performance/cycles` | `Performance\ReviewCycles` | Cycle management |
| `/operations/assets` | `Operations\Assets` | Asset create/assign/return |
| `/documents/experience-letter/{emp}` | `DocumentController` | Generate experience letter |

### Finance (`run-payroll`, `approve-finance`)
| Path | Component | Notes |
|------|-----------|-------|
| `/payroll/overview` | `Payroll\Overview` | Summary + export |
| `/payroll/process` | `Payroll\Process` | Generate draft + submit |
| `/payroll/finance-approve` | `Payroll\FinanceApproval` | Approve / reject |
| `/payroll/incentives` | `Payroll\Incentives` | Incentive management |
| `/payroll/reimbursements` | `Payroll\Reimbursements` | Reimbursement management |
| `/payroll/components` | `Payroll\Components` | Salary components config |
| `/reports/payroll-summary.pdf` | `ReportController` | PDF export |
| `/reports/attendance-summary.csv` | `ReportController` | CSV export |
| `/reports/ot-records.csv` | `ReportController` | CSV export |

---

## Phase 1 — Core (Spec §§2–3.3, §3.6)

### Status Legend
- ✅ Done & verified  
- ⚠ Partial / needs fix  
- ❌ Missing

| Feature | Spec Ref | Status | Notes |
|---------|----------|--------|-------|
| Auth (login/logout/password reset) | §2.4 | ✅ | Fortify |
| User roles (6 types) | §2.4, §4 | ✅ | UserRole enum |
| Employee profile full fields (name, email, phone, dob, gender, address, emergency_contact, photo) | §3.1 | ✅ | Migration 2026_04_28_192752; EmployeeCreate updated |
| Employment record (shift, work_mode, salary_cycle, probation) | §3.1 | ✅ | EmployeeCreate form now includes all fields |
| Org chart from manager_id | §3.1 | ✅ | |
| Attendance clock-in/out (work mode, IP) | §3.2 | ✅ | AttendanceService |
| Break start/end (repeatable, informational) | §3.2 | ✅ | break_logs table |
| Late flag (shift start + 5 min grace) | §3.2 | ✅ | AttendanceService |
| Excess break flag (>60 min) + notify employee + manager | §3.2, §3.6 | ✅ | ExcessBreakNotification dispatched in CheckExcessBreaks |
| Missing checkout flag + notify employee + manager | §3.2, §3.6 | ✅ | MissingCheckoutNotification dispatched in FlagMissingCheckouts |
| Attendance regularisation (missed-punch) | §3.2 | ✅ | |
| Leave types (CSL 12d, MDL 6d, Comp Off) | §3.3 | ✅ | |
| Leave balance (no-lapse, comp_off_credits) | §3.3 | ✅ | |
| Half-day leave request | §3.3 | ✅ | Migration 2026_04_28_192951; LeaveService 0.5d; MyTimeOff toggle |
| Leave approval/reject + balance sync | §3.3 | ✅ | LeaveService |
| Carry-forward (no lapse) | §3.3 | ✅ | CarryForwardLeaves (scheduled 1 Jan) |
| Leave escalation (24 hr) | §3.3 | ✅ | |
| Leave encashment | §3.3 | ✅ | |
| Comp Off credit for MDL / UK holiday work | §3.3 | ✅ | AttendanceService::checkOut |
| Public holidays calendar | §3.3 | ✅ | |
| MDL days management | §3.3 | ✅ | |
| In-app notification centre (bell + unread badge) | §3.6 | ✅ | |
| Leave request notifications (manager/employee) | §3.6 | ✅ | LeaveRequestNotification |
| Regularisation notification | §3.6 | ✅ | AttendanceRegularisationNotification |
| Probation due notification | §3.6 | ✅ | ProbationDueNotification |
| New-hire 30-day notification | §3.6 | ✅ | NewHireCheckInNotification |
| Document expiry notification | §3.6 | ✅ | DocumentExpiryNotification |

### Phase 1 Open Gaps (remaining after FIX 1–4)
- attendance_settings still models a global grace; shift_settings is the spec-correct location ✅ (shift_settings has grace_minutes)
- Comp Off on UK public holidays requires UK staff mapping (no blocker; UK shift employees covered)

---

## Phase 2 — Finance (Spec §3.4, §3.5)

| Feature | Spec Ref | Status | Notes |
|---------|----------|--------|-------|
| OT pre-approval request | §3.4 | ✅ | |
| OT rate fixed ₹100/hr | §3.4 | ✅ | OvertimeService |
| OT record only from approved request | §3.4 | ✅ | OvertimeService |
| OT escalation (24 hr) | §3.4 | ✅ | |
| OT notification to manager + employee | §3.6 | ✅ | OtRequestNotification |
| Salary structure (gross = basic + HRA + special) | §3.5 | ✅ | EmployeeSalary + SalaryComponent |
| Payroll Cycle A (1–31) and Cycle B (21–20) | §3.5 | ✅ | PayrollService |
| Draft → pending_finance → finalized flow | §3.5 | ✅ | PayrollService |
| OT marked paid only after finance finalization | §3.5 | ✅ | |
| Incentives (types + Director approval) | §3.5 | ✅ | IncentiveService |
| Reimbursements (receipt upload, manager approve) | §3.5 | ✅ | ReimbursementService |
| Expense claim → reimbursement e2e | §3.5 | ✅ | ExpenseClaimService |
| Leave encashment in payslip | §3.5 | ✅ | |
| Payslip PDF (dompdf) | §3.5 | ✅ | PayslipController |
| Payslip email (only for payslip) | §3.5 | ✅ | PayslipMail |
| Finance approval notification | §3.6 | ✅ | PayrollApprovalNotification |
| Payslip issued notification (in-app + email) | §3.6 | ✅ | PayslipGeneratedNotification |
| Incentive approved notification to employee | §3.6 | ⚠ | Notification class exists; wire-up check needed |
| Reimbursement approved/rejected notification | §3.6 | ⚠ | Notification class exists; wire-up check needed |

---

## Phase 3 — People Ops (Spec §3.7, §3.8, §3.9)

| Feature | Spec Ref | Status | Notes |
|---------|----------|--------|-------|
| Quarterly review cycles (Conexus FY Q1–Q4) | §3.7 | ✅ | PerformanceService validates |
| Employee self-assessment | §3.7 | ✅ | MyReview |
| Manager review step | §3.7 | ✅ | TeamReviews |
| HR lock after completion | §3.7 | ✅ | AllReviews::lockReview |
| Goals per employee per quarter | §3.7 | ✅ | ReviewGoal |
| KPI definitions per department | §3.7 | ❌ | Spec mentions kpis table; not implemented |
| Review reminder notification (7 days) | §3.6 | ✅ | ReviewReminderNotification |
| Onboarding checklist (auto-seeded) | §3.8 | ✅ | EmployeeObserver |
| Offboarding (exit record, equipment return) | §3.8 | ✅ | AssetAssignmentService |
| Experience letter generation | §3.8, §3.9 | ✅ | DocumentController |
| Asset create/assign/return + equipment_logs | §3.8 | ✅ | AssetAssignmentService |
| Documents (company + employee, versioned) | §3.9 | ✅ | DocumentManager |
| Document acknowledgement tracking | §3.9 | ✅ | DocumentAcknowledgement |
| Document expiry → HR notification 30d | §3.9 | ✅ | CheckDocumentExpiry |
| Expense rejection modal + reason display | §3.5 | ✅ | (Phase 3 pass) |
| Performance quarter validation | §3.7 | ✅ | |

### Phase 3 Open Gaps
- KPI table (per-department KPI definitions + scoring) not implemented — spec §3.7 references kpis, kpi_scores
- Expense claim notifications (submitted/approved/rejected) — notification classes exist but not dispatched from ExpenseClaimService

---

## Phase 4 — Polish (Spec §8, §9)

| Feature | Spec Ref | Status | Notes |
|---------|----------|--------|-------|
| Route-level role middleware | §2.4 | ✅ | EnsureRole middleware |
| Audit log observers (all sensitive models) | §9 | ✅ | 11 observers registered |
| Scheduled jobs (all pulse: commands) | §7 | ✅ | All commands exist; carry-forward scheduled |
| PDF/CSV report exports | §8 | ✅ | ReportController |
| Audit log viewer (HR Admin only) | §8.4 | ❌ | No UI for audit_logs table |
| System settings UI (shift config, MDL days) | §8.4 | ⚠ | AttendanceSettings partial; no MDL day UI |
| Backup (spatie/laravel-backup) | §8.2 | ✅ | Scheduled daily |
| HTTPS enforcement | §9 | ✅ | AppServiceProvider + .env |
| Payslip signed URLs (5 min expire) | §9 | ✅ | middleware('signed') |
| Mobile responsiveness QA | §8 | ⚠ | Tailwind responsive classes used; not QA'd |
| Notification bell polling (30 sec) | §3.6 | ⚠ | Check Livewire polling config |

---

## Remaining Gaps (Priority Order for Live)

### P0 — Blocking for go-live ✅ ALL RESOLVED
1. **Employee profile fields** — migration `2026_04_28_192752` adds phone, dob, gender, address, emergency_contact, photo. Model + EmployeeCreate form updated. ✅
2. **Half-day leave** — migration `2026_04_28_192951` adds `is_half_day`. LeaveService counts 0.5 days. MyTimeOff UI has toggle. ✅
3. **EmployeeCreate form** — shift_id, salary_cycle, probation_end_date, all personal fields now in form. ✅
4. **Excess break + missing checkout notifications** — `CheckExcessBreaks` and `FlagMissingCheckouts` commands now dispatch `ExcessBreakNotification` and `MissingCheckoutNotification` to employee + manager. ✅

### P1 — Important for completeness
5. **Expense claim notifications** — `ExpenseClaimService::approve()` and `reject()` must dispatch in-app notifications
6. **Audit log viewer** — HR Admin needs UI at `/settings/audit-logs`
7. **KPI definitions UI** — per-department KPI management as per §3.7
8. **Notification bell polling** — verify 30-second Livewire poll on notification component

### P2 — Polish / non-blocking
9. **MDL day management UI** — currently seeded; need admin UI to add/remove MDL days per year
10. **Finance rejection note** — make mandatory in UI rather than optional default text
11. **Incentive/reimbursement approved notifications** — wire notification dispatch in service classes

---

## Command ↔ Spec Alignment (§7)

| Spec Command | Implementation | Schedule | Status |
|---|---|---|---|
| `pulse:check-missing-checkouts` | `hrms:flag-missing-checkouts` | Daily 21:00 | ✅ |
| `pulse:check-late-arrivals` | `hrms:check-late-arrivals` | 10:45 + 13:15 | ✅ |
| `pulse:check-excess-breaks` | `hrms:check-excess-breaks` | Daily 20:00 | ✅ |
| `pulse:check-leave-escalations` | `hrms:escalate-leaves` | Hourly | ✅ |
| `pulse:check-ot-escalations` | `hrms:escalate-ot` | Hourly | ✅ |
| `pulse:check-document-expiry` | `hrms:check-document-expiry` | Daily 08:00 | ✅ |
| `pulse:check-probation-due` | `hrms:check-probation-due` | Daily 08:00 | ✅ |
| `pulse:check-newhire-checkin` | `hrms:check-newhire-checkin` | Daily 08:00 | ✅ |
| `pulse:check-review-reminders` | `hrms:send-review-reminders` | Monday 09:00 | ✅ |
| `pulse:prune-notifications` | `hrms:prune-notifications` | Sunday 00:00 | ✅ |
| `pulse:generate-attendance-summary` | `hrms:generate-attendance-summary` | 1st of month 01:00 | ✅ |
| *(not in spec §7)* | `hrms:carry-forward-leaves` | 1 Jan 02:00 | ✅ |

*Note: Command prefix `hrms:` vs spec `pulse:` is an internal naming decision and does not affect functionality.*

---

## Deployment Checklist (Spec §8.2)

- [ ] VPS: Ubuntu 22.04, Nginx, PHP 8.3-FPM, MySQL 8, Composer, Node
- [ ] `composer install --no-dev` + `npm run build`
- [ ] `.env` configured (DB, SMTP, APP_KEY, APP_URL)
- [ ] `php artisan migrate`
- [ ] `php artisan db:seed` (shift_settings, leave_types, public_holidays, december_mandatory_days, departments)
- [ ] Cron entry: `* * * * * cd /var/www/pulse && php artisan schedule:run >> /dev/null 2>&1`
- [ ] Nginx serve `public/` with correct headers
- [ ] HTTPS via Certbot + Let's Encrypt
- [ ] `php artisan pulse:create-admin`
- [ ] Test notification flow (leave request → manager notification)
- [ ] Test payslip email end-to-end
- [ ] Test half-day leave (after FIX 2)
- [ ] Test OT report export
- [ ] Mobile responsiveness check

---

## Notification Coverage Matrix (Spec §3.6)

| Event | Recipient | Class | Status |
|---|---|---|---|
| Leave request submitted | Manager | `LeaveRequestNotification` | ✅ |
| Leave approved/rejected | Employee | `LeaveRequestNotification` | ✅ |
| Leave escalation (24h) | HR Admin | `LeaveRequestNotification` | ✅ |
| OT request submitted | Manager | `OtRequestNotification` | ✅ |
| OT approved/rejected | Employee | `OtRequestNotification` | ✅ |
| Regularisation submitted | Manager | `AttendanceRegularisationNotification` | ✅ |
| Regularisation reviewed | Employee | `RegularisationReviewedNotification` | ✅ |
| Leave encashment submitted | Director + HR | `LeaveEncashmentNotification` | ✅ |
| Leave encashment reviewed | Employee | `LeaveEncashmentNotification` | ✅ |
| Excess break flag | Employee + Manager | `ExcessBreakNotification` | ✅ |
| Missing checkout | Employee + Manager | `MissingCheckoutNotification` | ✅ |
| Payroll ready for approval | Finance | `PayrollApprovalNotification` | ✅ |
| Payslip issued | Employee (in-app + email) | `PayslipGeneratedNotification` | ✅ |
| Review due (7 days) | Employee + Manager | `ReviewReminderNotification` | ✅ |
| Document requires acknowledgement | Employee | *(inline)* | ✅ |
| Document expiring 30d | HR Admin | `DocumentExpiryNotification` | ✅ |
| Probation due (10d) | Manager + HR | `ProbationDueNotification` | ✅ |
| New hire 30d check-in | Manager | `NewHireCheckInNotification` | ✅ |
| Incentive approved | Employee | *(inline)* | ⚠ P1 |
| Reimbursement reviewed | Employee | *(inline)* | ⚠ P1 |
| Expense claim reviewed | Employee | *(inline)* | ⚠ P1 |
