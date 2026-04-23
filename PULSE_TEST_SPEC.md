# Pulse by Conexus v3.1 - Central Test & Workflow Specification (PULSE_TEST_SPEC.md)

This document serves as the single source of truth for tracking test coverage, workflow completion, and acceptance criteria across all modules of the Pulse HRMS platform. 

It is designed to be automatically updated by the AI system as new features are implemented or tested.

---

## 📊 Project Completion Overview

| Module | Status | Test Coverage | Core Features Implemented |
| :--- | :---: | :---: | :---: |
| **Authentication & Auth** | 🟢 Complete | 100% | ✅ |
| **Employee Management** | 🟢 Complete | 90% | ✅ |
| **Attendance & Shifts** | 🟢 Complete | 85% | ✅ |
| **Leave Management** | 🟢 Complete | 90% | ✅ |
| **Payroll (Phase 4)** | 🟡 In Progress| 60% | 🔄 |
| **Notifications** | 🟢 Complete | 80% | ✅ |
| **Performance** | ⚪ Pending | 0% | ❌ |
| **Settings & Config** | 🟡 In Progress| 50% | 🔄 |

*(Status Key: 🟢 Complete, 🟡 In Progress, ⚪ Pending, 🔴 Blocked)*

---

## 1. Authentication & Role-Based Access Control (RBAC)

### 1.1 Workflows
*   **W1.1:** User Login (Email/Password) + 2FA setup and enforcement.
*   **W1.2:** Role assignment mapping (Super Admin, HR Admin, Manager, Finance, Director, Employee).

### 1.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-AUTH-01:** Super Admins can access global settings; other roles are blocked (Gate: `manageFullSettings`).
- `[x]` **TEST-AUTH-02:** Department Heads can view the `DepartmentDashboard`; standard employees cannot.
- `[x]` **TEST-AUTH-03:** Password reset emails are dispatched correctly.
- `[x]` **TEST-AUTH-04:** Livewire route guards prevent unauthorized data mutations.

---

## 2. Employee Management (Core)

### 2.1 Workflows
*   **W2.1:** HR adds a new employee (captures personal, job, and salary details).
*   **W2.2:** Onboarding task assignment and tracking.
*   **W2.3:** Document upload (KYC, contracts) and expiry notifications.

### 2.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-EMP-01:** Employee records require a valid `department_id`, `manager_id`, and `shift_id`.
- `[ ]` **TEST-EMP-02:** When an employee is created, onboarding tasks are automatically seeded.
- `[x]` **TEST-EMP-03:** `Document` models correctly store local files and retrieve valid URLs.

---

## 3. Attendance & Shifts (Phase 4 additions included)

### 3.1 Workflows
*   **W3.1:** Daily Clock-in / Clock-out via Employee Dashboard.
*   **W3.2:** Shift-aware late arrival calculations (e.g., IT Shift vs UK Shift).
*   **W3.3:** Overtime (OT) request submission on >9 hour workdays.

### 3.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-ATT-01:** Clock-in records timestamp correctly.
- `[x]` **TEST-ATT-02:** Cron job `hrms:check-late-arrivals` calculates tardiness dynamically using the `grace_period_minutes` from the employee's `shift_id`.
- `[x]` **TEST-ATT-03:** Clocking out on a designated `public_holiday` with >4 hours logged automatically credits 1 "Comp Off" day.

---

## 4. Leave Management

### 4.1 Workflows
*   **W4.1:** Employee requests leave -> Manager Approval -> HR Approval (if > 3 days) -> Balance deducted.
*   **W4.2:** Annual leave accrual runs on January 1st.
*   **W4.3:** Leave Request Escalation to HR if Manager ignores > 24 hours.

### 4.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-LEAVE-01:** Leave cannot be requested if balance is insufficient.
- `[x]` **TEST-LEAVE-02:** Manager approval deducts the requested days correctly, accounting for weekends.
- `[ ]` **TEST-LEAVE-03:** Cron job `hrms:escalate-leaves` accurately triggers notifications to HR Admin for stale requests.

---

## 5. Payroll System (Phase 4)

### 5.1 Workflows
*   **W5.1:** Cycle A / Cycle B payroll queues process respective employees.
*   **W5.2:** Finance reviews generated gross/net calculations (including OT, Deductions, Allowances).
*   **W5.3:** Finance "Approves & Finalizes" the batch -> Dispatch payslips.

### 5.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-PAY-01:** Payroll correctly captures Unpaid Leaves (LWP) and deducts from Gross Salary.
- `[x]` **TEST-PAY-02:** Approving a payroll batch triggers `PayslipMail` via SMTP.
- `[x]` **TEST-PAY-03:** Employees can successfully download their payslip PDF securely via `PayslipController@download`.
- `[ ]` **TEST-PAY-04:** End-to-end integration test from Attendance (OT + LWP) -> Final Payroll Net calculation.

---

## 6. Notifications

### 6.1 Workflows
*   **W6.1:** System dispatches database notifications (bell icon) and email alerts based on critical triggers.

### 6.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-NOTIF-01:** Managers receive a notification when a direct report submits a leave request.
- `[x]` **TEST-NOTIF-02:** HR receives notification when a document expires within 30 days.

---

## 7. Performance & Appraisal (Pending)

### 7.1 Workflows
*   **W7.1:** Probation review triggered at 3 / 6 month marks.
*   **W7.2:** Annual KPI review routing (Self Assessment -> Manager -> HR).

### 7.2 Acceptance Criteria & Test Cases
- `[ ]` **TEST-PERF-01:** Probation due cron job flags HR 10 days prior.
- `[ ]` **TEST-PERF-02:** Appraisal score locks correctly after HR signature.

---

## 8. Settings, Security & Backups

### 8.1 Workflows
*   **W8.1:** Nightly automated SQL dumps and environment state backups.
*   **W8.2:** Super Admin configures system-wide constants (Office IPs, Tax Brackets).

### 8.2 Acceptance Criteria & Test Cases
- `[x]` **TEST-SYS-01:** `backup:run` and `backup:clean` execute successfully at 01:30 AM daily.
- `[x]` **TEST-SYS-02:** Unauthenticated users are strictly redirected to `/login`.
