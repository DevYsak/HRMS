# Pulse by Conexus — Requirements & Workflow Cross-Check (v3.1)

This document maps the **Pulse by Conexus Product Specification (v3.1)** against the development work completed so far. It is the canonical source of truth for cross-checking completed work and pending items.

**Last Updated:** 2026-04-21 (Phase 1 CSV implementation complete)

---

## 1. System Architecture & Tech Stack

| Requirement | Status | Notes |
|-------------|--------|-------|
| Laravel 13 (PHP 8.3) | ✅ Completed | |
| MySQL 8 Database | ✅ Completed | |
| Livewire 4 + Blade + Tailwind | ✅ Completed | Fully responsive UI. |
| In-App Notifications (Database) | ✅ Completed | Livewire polling bell-icon dropdown, mark-read, click-to-navigate. |
| Payslip PDF Generation (Dompdf) | ⏳ Pending | Currently browser `@media print`. Spec requires `barryvdh/laravel-dompdf` for email attachment + signed URL. |
| SMTP Email Delivery (Payslip) | ⏳ Pending | Mail configured; automated payslip PDF email dispatch needs `barryvdh/laravel-dompdf` first. |
| DB Backups (spatie/laravel-backup) | ❌ Not Started | `composer require spatie/laravel-backup`; configure disk (S3/GCS); scheduler daily 02:00. |

---

## 2. Role-Based Access Control (RBAC)

| Role / Feature | Status | Notes |
|----------------|--------|-------|
| Roles Configured | ✅ Completed | `super_admin`, `hr_admin`, `director`, `manager`, `finance`, `employee` in `UserRole` enum. |
| Laravel Policies & Gates | ✅ Completed | `EmployeePolicy`, `LeavePolicy`, `AttendancePolicy`, `PayrollPolicy` all registered. |
| Role helpers on `User` model | ✅ Completed | `canManageEmployees()`, `canApproveLeave()`, `canRunPayroll()`, `canApproveOt()`, `canApproveFinance()`, `canManageDocuments()`, `canManageSettings()`. |
| System Config Settings UI | ⏳ Pending | Full/Partial access scoping for Super Admin vs HR Admin in the settings panel (Gate: `manageFullSettings`). |

---

## 3. Core Modules Workflow & Status

### 3.1 Employee Management
* **Status:** ✅ Complete.
* **Completed:** CRUD, directory, org-chart, manager assignment, soft-delete, `EmployeeStatus` / `EmploymentType` enums.
* `salary_cycle` field (`cycle_a` / `cycle_b`) — ✅ Added (migration run, model updated, **used in payroll split**).

### 3.2 Attendance Management
* **Status:** ✅ Core Implemented, ⏳ Extensions Pending.
* **Completed:** Clock-in/out, IP logging, geo-fencing, break tracking (`startBreak` / `endBreak`), missing-checkout flags, regularisation request modal.
* **Pending:**
  - `shift_settings` table — migration needed; seed IT Shift (09:00) and UK Sales Shift (13:30); link via `employment_details.shift_id`.

### 3.3 Leave Management
* **Status:** ✅ Core Implemented, ⏳ Extensions Pending.
* **Completed:** Annual/Sick/Casual requests, manager approval/rejection, 24-hr HR escalation cron, balance tracking.
* **Pending:**
  - `public_holidays` table — ✅ **NOW ADDED** (19 holidays seeded for IN + UK 2026).
  - `december_mandatory_days` table — ✅ **NOW ADDED** (6 MDL days seeded for 2025–2027).
  - Comp Off auto-credit logic in `AttendanceService` when working MDL/holiday.
  - Leave encashment UI flow (model/migration present) — encashment **now wired into payroll run**.

### 3.4 Overtime (OT)
* **Status:** ✅ Completed.
* **Completed:** Pre-approval, manager approve/reject, OT immutable record, ₹100/hr rate, service class, Livewire UI.

### 3.5 Compensation & Finance
* **Status:** ✅ Mostly Complete.
* **Completed:**
  - Payroll generation per cycle (**now Cycle A / Cycle B split**).
  - Payslip line items, salary components, Finance approval sign-off workflow.
  - `Incentive` model + migration + `Incentives` Livewire component (submit → approve → include in payroll run).
  - `Reimbursement` model + migration + `Reimbursements` Livewire component (receipt upload → approve → include in payroll run).
  - Approved incentives + reimbursements + leave encashments **auto-included** when running payroll for a cycle.
* **Pending:**
  - `barryvdh/laravel-dompdf` for PDF payslip generation + email dispatch.

### 3.6 Performance Management
* **Status:** ✅ Completed.
* **Completed:** Quarterly cycles, self-reviews, manager evaluations, OKR/goal tracking, all-reviews HR view.

### 3.7 Onboarding & Offboarding
* **Status:** ✅ Completed.
* **Completed:** Task checklists, equipment logs, exit records.

### 3.8 Document Management
* **Status:** ✅ Completed.
* **Completed:** Upload, versioning, category/dept scoping, acknowledgement, soft-delete.

---

## 4. Dashboards

| Dashboard | Status | Notes |
|-----------|--------|-------|
| Employee Self-Service (`/`) | ✅ Built | Attendance widget, Leave balances, OT requests, Payslip history. |
| HR Admin Dashboard (`/dashboard/hr-admin`) | ✅ Built | Headcount KPIs, Attendance exceptions, Pending leaves/OT, Audit log. |
| Manager Dashboard (`/dashboard/manager`) | ✅ Built | Team attendance table, Pending leave + OT approvals, Performance review status. |
| Executive Dashboard (`/dashboard/executive`) | ✅ Built | Headcount KPIs, today's attendance, pending leaves/OT, Cycle A/B payroll status, avg performance rating, dept headcount breakdown. |
| Finance Dashboard (`/dashboard/finance`) | ✅ Built | Cycle A/B payout totals + status, approved incentives/reimbursements, OT financial summary, per-payslip breakdown table. |
| Operations/BD (Department Head) | ⏳ Pending | Scoped to `department_id`, team calendars — next sprint. |

---

## 5. Scheduled Jobs (Laravel Cron)

| Job | Spec Requirement | Status |
|-----|------------------|--------|
| `hrms:flag-missing-checkouts` | Daily 08:00 | ✅ Implemented |
| `hrms:escalate-leaves` | Hourly | ✅ Implemented |
| `hrms:check-late-arrivals` | Daily 09:30 | ✅ Implemented |
| `hrms:check-excess-breaks` | Daily 20:00 | ✅ Implemented |
| `hrms:escalate-ot` | Hourly | ✅ Implemented |
| `hrms:check-document-expiry` | Daily 08:00 | ✅ Implemented |
| `hrms:check-probation-due` | Daily 08:00 | ✅ Implemented |
| `hrms:check-newhire-checkin` | Daily 08:00 | ✅ Implemented |
| `hrms:send-review-reminders` | Weekly Mon 09:00 | ✅ Implemented |
| `hrms:prune-notifications` | Weekly Sun 00:00 | ✅ Implemented |
| `hrms:generate-attendance-summary` | 1st of month 01:00 | ✅ Implemented |

---

## 6. Security & Audit

| Requirement | Status | Notes |
|-------------|--------|-------|
| `audit_logs` table + Observers | ✅ Implemented | `AuditLog` model + `EmployeeObserver`, `PayslipObserver`, `UserObserver` registered in `AppServiceProvider`. |
| DB Backups (`spatie/laravel-backup`) | ❌ Not Started | Requires installing the package and configuring cloud storage (S3/GCS). |
| Signed URL PDF Access | ⏳ Pending | Requires server-side PDF generation first (`barryvdh/laravel-dompdf`). |

---

## 7. New Tables & Models (Phase 1 CSV — Implemented)

| Table / Model | Description | Status |
|---------------|-------------|--------|
| `public_holidays` | India + UK calendars (19 rows seeded for 2026) | ✅ Done |
| `december_mandatory_days` | 6 MDL days per year (2025–2027 seeded) | ✅ Done |
| `incentives` | Employee incentive requests → Director approval → payroll inclusion | ✅ Done |
| `reimbursements` | Expense claims + receipt upload → Finance approval → payroll inclusion | ✅ Done |
| `payrolls.cycle` | New column to separate Cycle A vs Cycle B payroll runs | ✅ Done |

---

## 8. Remaining Pending Items (Next Sprint)

| Priority | Item | Effort |
|----------|------|--------|
| Critical | Server-side PDF payslips (`barryvdh/laravel-dompdf`) + email dispatch + signed URL | Medium |
| High | `shift_settings` table + seed IT/UK shifts + link to `employment_details.shift_id` | Low |
| High | Comp Off auto-credit in `AttendanceService@clockOut` when working on MDL/public holiday | Medium |
| High | Operations/BD Department Head dashboard | Medium |
| Medium | Settings UI for Super Admin (full) vs HR Admin (partial) — Gate: `manageFullSettings` | Medium |
| Low | `spatie/laravel-backup` for daily DB dumps to S3/GCS | Low |
