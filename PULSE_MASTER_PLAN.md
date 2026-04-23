# Pulse HRMS — Master Implementation Plan
**Version:** v3.1 | **Based on:** Pulse_by_Conexus_v3.1.pdf

This document is the single source of truth for feature status, pending work, and audit tracking.

---

## 🗺 Module Status at a Glance

| # | Module | DB Schema | Backend Logic | UI / Views | Workflows | Status |
|---|--------|-----------|---------------|------------|-----------|--------|
| 1 | Authentication & RBAC | ✅ | ✅ | ✅ | ✅ | 🟢 Complete |
| 2 | Employee Management | ✅ | ✅ | ✅ | 🟡 Partial | 🟡 In Progress |
| 3 | Attendance & Shifts | ✅ | ✅ | ✅ | ✅ | 🟢 Complete |
| 4 | Leave / Time-Off | ✅ | ✅ | ✅ | 🟡 Partial | 🟡 In Progress |
| 5 | Overtime (OT) | ✅ | ✅ | ✅ | 🟡 Partial | 🟡 In Progress |
| 6 | Payroll | ✅ | ✅ | ✅ | 🟡 Partial | 🟡 In Progress |
| 7 | Notifications | ✅ | ✅ | ✅ | ✅ | 🟢 Complete |
| 8 | Performance & Appraisal | ✅ | ✅ | ✅ | ✅ | 🟢 Complete |
| 9 | Onboarding / Offboarding | ✅ | 🟡 Partial | ✅ | 🟡 Partial | 🟡 In Progress |
| 10 | Document Management | ✅ | ✅ | ✅ | 🟡 Partial | 🟡 In Progress |
| 11 | Operations (Assets, Expenses) | ✅ | 🟡 Partial | ✅ | ❌ Missing | ⚪ Pending |
| 12 | Settings & Admin | ✅ | 🟡 Partial | 🟡 Partial | ❌ Missing | ⚪ Pending |
| 13 | Automated Backups | ✅ | ✅ | N/A | ✅ | 🟢 Complete |

---

## ✅ MODULE 1 — Authentication & RBAC

### What's Done
- Laravel Fortify authentication (login, register, password reset, 2FA)
- Role enum: `SuperAdmin`, `HrAdmin`, `Manager`, `Finance`, `Director`, `Employee`
- `Gate::define('manageFullSettings')` limiting admin settings to SuperAdmin/HrAdmin
- `User::isDepartmentHead()` helper for department-scoped access
- Email verification enforced on all protected routes

### Pending
- [ ] Settings restricted UI beyond just `settings.general` (need to gate all admin tabs)
- [ ] Role assignment UI (currently seed/tinker only — no UI for HR to change roles)

---

## ✅ MODULE 2 — Employee Management

### What's Done
- Full DB schema: `employees`, `departments`, `job_titles`, `offices`, `employee_salaries`
- CRUD: `EmployeeIndex`, `EmployeeCreate`, `EmployeeEdit` Livewire components
- Directory and Org Chart views
- Shift assignment via `shift_id` on employees
- `Employee` model with rich relationships (user, department, manager, jobTitle, shift)
- Observers: `EmployeeObserver` for audit logging

### Pending
- [ ] **Role Assignment UI** — HR should be able to change an employee's role from the Edit screen
- [x] **Onboarding auto-task seeding** — When a new employee is created, auto-seed the `onboarding_tasks` checklist (implemented in EmployeeObserver)
- [ ] **Offboarding workflow** — Exit record, equipment return, document handover steps not yet wired to a UI

---

## ✅ MODULE 3 — Attendance & Shifts

### What's Done
- `attendances` table with `check_in`, `check_out`, `break_start/end`, `is_late`, `ip_address`
- `shift_settings` table linked to `employees.shift_id`
- `MyAttendance` Livewire component: Clock In/Out, Break tracking, daily summary
- `TeamAttendance` and `AllAttendance` views (HR overview)
- Cron: `CheckLateArrivals` — shift-aware, dynamic grace periods ✅
- Cron: `FlagMissingCheckouts` — flags open records daily ✅
- Cron: `CheckExcessBreaks` — flags >60 min breaks ✅
- Comp Off auto-credit on public holidays/MDLs ✅
- `AttendanceRegularisation` model (correction requests)

### Pending
- [ ] **Attendance Regularisation UI** — employees can request corrections; manager approves
- [ ] **Geo-fencing** — IP/location validation on clock-in (office IP whitelist in `attendance_settings`)

---

## 🟡 MODULE 4 — Leave / Time-Off

### What's Done
- DB: `leave_types`, `leave_balances`, `leave_requests`, `leave_escalations`, `leave_encashments`
- `MyTimeOff` component: submit/view requests, balance display
- `TeamTimeOff` component: manager approval view with full approval/rejection logic
- `AllTimeOff` component: HR admin overview with override capability
- `TimeOffSettings` component: configure leave types
- Cron: `EscalateLeaveRequests` — escalates to HR after 24h inaction ✅
- `CarryForwardLeaves` cron for annual rollover ✅
- `LeaveEncashment` model exists
- Leave Encashment Workflow — employee requests encashment → Finance approves → added to next payroll cycle

### Pending
- [ ] **Calendar view** for leave requests (optional polish)

---

## 🟡 MODULE 5 — Overtime (OT)

### What's Done
- DB: `ot_requests`, `overtime_records`
- `MyOtRequests` Livewire: employee submits pre-approval OT request
- `ManageOtRequests` Livewire: manager approves/rejects
- Cron: `EscalateOtRequests` — escalates after 24h ✅
- `OtRequest` model with relationships
- Auto-calculation in Payroll — approved OT records auto-populate the payroll process step

### Pending
- [ ] **OT Rate configuration** — currently hardcoded; should come from `salary_components` or `attendance_settings`
- [ ] **Finance view** — OT summary widget on Finance dashboard

---

## 🟡 MODULE 6 — Payroll

### What's Done
- DB: `payrolls`, `payslips`, `payslip_items`, `salary_components`, `employee_salaries`, `incentives`, `reimbursements`
- `Process` Livewire: HR runs payroll for Cycle A or Cycle B
- `FinanceApproval` Livewire: Finance reviews & approves batch + auto-dispatches `PayslipMail` ✅
- `Incentives` Livewire: HR adds incentives per employee per cycle
- `Reimbursements` Livewire: employees claim; finance approves
- `Components` Livewire: manage salary component definitions
- `MyPayslips` Livewire: employee views/downloads payslips
- `PayslipController@download` for PDF generation via DomPDF ✅
- Payslip PDF Blade template (`resources/views/pdf/payslip.blade.php`) ✅
- LWP (Leave Without Pay) auto-deduction — payroll process counts unpaid leaves and auto-deducts
- OT auto-inclusion — approved `overtime_records` flow into the payroll `Process` step automatically
- Leave Encashment payout — encashment requests appear as a line item in payslip
- Payroll lock after Finance sign-off — finalized payrolls are locked from editing/re-processing

### Pending

---

## ⚪ MODULE 7 — Notifications

### What's Done
- `notifications` DB table (Laravel standard)
- `Notifications` Livewire component + `notifications.blade.php` (bell icon, list view)
- `LeaveRequestNotification` fires when leave submitted
- `CheckDocumentExpiry` cron notifies HR 30 days before expiry ✅
- `CheckNewHireCheckIn` cron reminds HR at 30-day milestone ✅
- `PruneNotifications` cron cleans up 90-day-old read notifications ✅

### Pending
- [ ] **Notification for OT approval** — employee should be notified when OT is approved/rejected
- [ ] **Notification for payslip generated** — employee notified when payroll batch finalised
- [ ] **Mark all as read** action on notification bell

---

## ⚪ MODULE 8 — Performance & Appraisal

### What's Done
- DB: `review_cycles`, `performance_reviews`, `review_goals`
- Models: `ReviewCycle`, `PerformanceReview`, `ReviewGoal`
- Views exist: `MyReview`, `TeamReviews`, `AllReviews`, `ReviewCycles`, `Goals`
- Cron: `SendReviewReminders` — weekly reminders to pending reviewees ✅
- Cron: `CheckProbationDue` — flags HR 10 days before probation end ✅

### Pending
- [x] **Review submission workflow** — employee submits self-assessment → manager scores → HR locks (**core flow implemented**)
- [ ] **Probation extension/confirmation** action — HR should be able to mark a probation as confirmed/extended in the UI
- [x] **KPI goals tied to review** — `ReviewGoal` records link to `PerformanceReview` with ratings/comments
- [x] **Quarter-based cycle management** — HR creates Q1/Q2/Q3/Q4 cycles in `ReviewCycles` with open/close dates

---

## ⚪ MODULE 9 — Onboarding / Offboarding

### What's Done
- DB: `onboarding_tasks` table
- `OnboardingChecklist` Livewire component exists
- `ExitRecord` model for offboarding

### Pending
- [x] **Onboarding views** (`resources/views/livewire/onboarding/onboarding-checklist.blade.php` created)
- [x] **Auto-seed onboarding tasks** when new employee is created (`EmployeeObserver` hook implemented)
- [ ] **Offboarding checklist UI** — step-by-step: clearance, equipment return, exit interview scheduling
- [ ] **ExitRecord workflow** — manager/HR submits, system sends confirmation, blocks system access on last day

---

## 🟡 MODULE 10 — Document Management

### What's Done
- DB: `documents`, `document_acknowledgements`
- `DocumentManager` Livewire component
- `Document` and `DocumentAcknowledgement` models
- `CheckDocumentExpiry` cron ✅

### Pending
- [ ] **Upload UI** — document file upload input + storage to `storage/app/documents`
- [ ] **Acknowledgement action** — employee marks a policy document as read/acknowledged
- [ ] **Role-scoped access** — employees see their own documents only; HR sees all

---

## ⚪ MODULE 11 — Operations (Assets & Expenses)

### What's Done
- DB: `assets`, `expense_claims`
- `Assets` and `Expenses` Livewire components
- `EquipmentLog` model for tracking asset assignment

### Pending
- [ ] **Asset assignment workflow** — HR assigns asset to employee; shows in employee profile
- [ ] **Expense claim approval** — employee submits → manager approves → Finance reimburses
- [ ] **Link to Reimbursements** — approved expense claims should map to a `reimbursements` record

---

## 🟡 MODULE 12 — Settings & Admin

### What's Done
- `Gate::define('manageFullSettings')` guards settings
- `AttendanceSettings` Livewire component
- `TimeOffSettings` Livewire component
- Payroll `Components` Livewire for salary definitions
- `config/backup.php` for Spatie backup

### Pending
- [ ] **General Settings UI** — currently "Coming Soon" placeholder. Needs: Company name, logo, working days, fiscal year config
- [ ] **Office/IP whitelist management** — for geo-fenced clock-in
- [ ] **Role Assignment UI** — HR Admin changes user roles from a settings panel
- [ ] **Holiday Calendar management** — HR Admin adds/removes `public_holidays` and `december_mandatory_days`

---

## 📋 Consolidated Pending Tasks (Priority Order)

### 🔴 High Priority (Core Workflow Gaps)
1. Performance Review submission workflow (DONE)
2. Onboarding: Blade views + auto-task seeding (DONE)

### 🟡 Medium Priority (Functional Completeness)
3. Attendance Regularisation UI (request + approval)
4. Offboarding UI and ExitRecord workflow
5. Document upload and acknowledgement actions

### 🟢 Low Priority (Polish & Admin)
11. Holiday Calendar management UI
12. Role Assignment UI in Settings
13. General Settings (company config)
14. Asset assignment workflow
15. Expense claim → Reimbursement linkage
16. Notification: OT approved/rejected
17. Geo-fencing / IP validation on clock-in

---

## 🧪 Test Case Registry

| ID | Module | Test Case | Status |
|---|---|---|---|
| TEST-AUTH-01 | Auth | SuperAdmin can access settings; others blocked | ✅ |
| TEST-AUTH-02 | Auth | Dept Head sees DepartmentDashboard | ✅ |
| TEST-AUTH-03 | Auth | Password reset dispatches email | ✅ |
| TEST-EMP-01 | Employees | Employee requires dept_id, manager_id, shift_id | ✅ |
| TEST-EMP-02 | Employees | New employee auto-seeds onboarding tasks | ✅ |
| TEST-ATT-01 | Attendance | Clock-in records timestamp correctly | ✅ |
| TEST-ATT-02 | Attendance | Late check uses shift grace_period_minutes | ✅ |
| TEST-ATT-03 | Attendance | Comp Off credited on >4h holiday work | ✅ |
| TEST-LEAVE-01 | Leave | Cannot request if balance insufficient | ✅ |
| TEST-LEAVE-02 | Leave | Approval deducts correct days | ✅ |
| TEST-LEAVE-03 | Leave | Escalation cron fires after 24h | ✅ |
| TEST-LEAVE-04 | Leave | Encashment flows to Finance → payroll | ✅ |
| TEST-OT-01 | Overtime | OT pre-approval required before work | ✅ |
| TEST-OT-02 | Overtime | Approved OT auto-included in payroll | ✅ |
| TEST-PAY-01 | Payroll | LWP deducted from gross correctly | ✅ |
| TEST-PAY-02 | Payroll | Finance approval triggers PayslipMail | ✅ |
| TEST-PAY-03 | Payroll | Employee downloads PDF payslip | ✅ |
| TEST-PAY-04 | Payroll | Payroll locked after Finance sign-off | ✅ |
| TEST-PERF-01 | Performance | Probation cron flags HR 10 days prior | ✅ |
| TEST-PERF-02 | Performance | Review submission routes self→manager→HR | ✅ |
| TEST-NOTIF-01 | Notifications | Manager notified on leave submission | ✅ |
| TEST-NOTIF-02 | Notifications | HR notified 30 days before doc expiry | ✅ |
| TEST-SYS-01 | System | backup:run runs daily at 01:30 | ✅ |
| TEST-SYS-02 | System | Unauthenticated users redirected to /login | ✅ |

---

*Last Updated: 2026-04-23 | Generated by Antigravity AI System*
