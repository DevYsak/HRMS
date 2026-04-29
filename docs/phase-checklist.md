# Pulse HRMS Phase-Wise Implementation Checklist

Source basis: `Pulse_by_Conexus_v3.1.pdf`, `PULSE_MASTER_PLAN.md`, `REQUIREMENTS_CROSS_CHECK.md`, `PULSE_TEST_SPEC.md`, and current Laravel code audit as of 2026-04-28.

## Phase 1: Core

### Features List
- Employee master data, org chart, directory, manager hierarchy, shift assignment, probation metadata
- Attendance clock-in/out, work mode, IP/geo capture, break tracking, late marking, missing checkout flagging
- Attendance regularisation request and review flow
- Leave request, leave balance tracking, encashment request, carry-forward processing, holiday calendar, mandatory day handling
- In-app notifications for leave, OT, attendance regularisation, probation, document reminders, payslips

### Required Database Tables
- `users`
- `companies`
- `offices`
- `departments`
- `job_titles`
- `employees`
- `shift_settings`
- `attendance_settings`
- `attendances`
- `break_logs`
- `attendance_regularisations`
- `leave_types`
- `leave_balances`
- `leave_requests`
- `leave_escalations`
- `leave_encashments`
- `public_holidays`
- `december_mandatory_days`
- `notifications`
- `audit_logs`

### Business Logic Conditions
- Attendance grace period must be 5 minutes per assigned shift, not a global 15-minute fallback.
- IT shift: 10:30 to 19:30 IST. UK Sales shift: 13:00 to 22:00 IST.
- Late flag = check-in after shift start plus grace.
- Excess break flag = total completed break time strictly greater than 60 minutes.
- Missing checkout flag = checked in but not checked out by scheduled end-of-day audit.
- Regularisation must support missed-punch days where no attendance row exists yet.
- Leave approvals must update balances atomically and reject approval when balance is insufficient.
- Carry-forward must use year-specific balances and stored carry-forward values, not a non-existent entitlement field.
- Leave encashment must reserve balance and prevent duplicate pending or approved requests in the same year.
- Holiday and mandatory-day work must remain available for comp-off automation; current implementation is only partially wired.

### APIs / Services Needed
- `AttendanceService`
  Handles check-in, check-out, break start/end, regularisation approval/rejection.
- `LeaveService`
  Handles leave submission, approval/rejection, balance sync, and validation.
- `CheckLateArrivals`, `CheckExcessBreaks`, `FlagMissingCheckouts`, `CarryForwardLeaves`, `EscalateLeaveRequests`
- Notification classes:
  `LeaveRequestNotification`, `AttendanceRegularisationNotification`, `RegularisationReviewedNotification`, `ProbationDueNotification`, `ProbationExtendedNotification`

### UI Components (Livewire)
- `Employees\EmployeeIndex`
- `Employees\EmployeeCreate`
- `Employees\EmployeeEdit`
- `Employees\Directory`
- `Employees\OrgChart`
- `Attendance\AttendanceTracker`
- `Attendance\TeamAttendance`
- `Attendance\AllAttendance`
- `Attendance\AttendanceSettings`
- `TimeOff\MyTimeOff`
- `TimeOff\TeamTimeOff`
- `TimeOff\AllTimeOff`
- `TimeOff\TimeOffSettings`
- `Notifications`

### Notifications
- New leave request to manager
- Leave approval or rejection to employee
- Leave escalation to HR Admin
- Attendance regularisation review result to employee
- Probation due reminder to manager and HR
- New-hire milestone reminder to manager
- Document expiry reminder to HR

### Open Gaps
- `attendance_settings` still models a global grace configuration that conflicts with shift-based spec logic.
- Time-off settings UI does not yet expose full leave-type policy fields like category, carry-forward limit, and encashability.
- Comp-off auto-credit for public holidays and mandatory days is still not explicit in the active attendance workflow.

## Phase 2: Finance

### Features List
- OT request, pre-approval, approval/rejection, escalation
- Payroll draft generation for cycle A and cycle B
- Finance approval workflow and finalization
- Payslip generation and signed download
- Incentives, reimbursements, LWP deduction, leave encashment inclusion, final settlement inclusion

### Required Database Tables
- `ot_requests`
- `overtime_records`
- `salary_components`
- `employee_salaries`
- `payrolls`
- `payslips`
- `payslip_items`
- `incentives`
- `reimbursements`
- `exit_records`

### Business Logic Conditions
- OT must only become payable when a pre-approved OT request exists.
- OT rate is fixed at 100 per hour unless the spec is revised.
- Cycle A payroll window = 1st through last day of selected month.
- Cycle B payroll window = 21st of previous month through 20th of selected month.
- Payroll status flow must be `draft -> pending_finance -> finalized`.
- Employees must not be notified of payroll completion before finance approval.
- OT records must not be marked paid during draft generation; they become paid only after finance finalization.
- LWP deduction must be calculated inside the payroll window only.
- Leave encashment payout must use a consistent daily rate rule.
- Final settlement rows must be linked to exactly one payroll run.

### APIs / Services Needed
- `OTService` and `OvertimeService`
- `PayrollService`
  Generates draft payroll, submits to finance, approves/rejects finance, dispatches finalized notifications.
- `LwpService`
- `EscalateOtRequests`
- `PayrollApprovalNotification`
- `PayslipGeneratedNotification`
- `PayslipMail`

### UI Components (Livewire)
- `Overtime\MyOtRequests`
- `Overtime\ManageOtRequests`
- `Payroll\Overview`
- `Payroll\Process`
- `Payroll\FinanceApproval`
- `Payroll\MyPayslips`
- `Payroll\Components`
- `Payroll\Incentives`
- `Payroll\Reimbursements`
- `FinanceDashboard`

### Notifications
- New OT request to manager or HR approver
- OT approval or rejection to employee
- Escalated OT request to HR
- Payroll pending finance approval to finance roles
- Finalized payroll and payslip availability to employees

### Open Gaps
- Payroll policy coverage is defined, but several payroll-side screens still need full route or component-level policy enforcement beyond the patched areas.
- Finance rejection note capture is still optional in UI and should be made mandatory if strict audit policy requires it.

## Phase 3: People Ops

### Features List
- Review cycles, goals, self-review, manager review, HR overview
- Onboarding task generation and tracking
- Offboarding, exit checklist, equipment return, final settlement preparation
- Document upload, acknowledgement, secure download, experience letter

### Required Database Tables
- `review_cycles`
- `performance_reviews`
- `review_goals`
- `onboarding_tasks`
- `equipment_logs`
- `assets`
- `documents`
- `document_acknowledgements`
- `exit_records`

### Business Logic Conditions
- Review workflow must remain step-based: self-assessment, manager review, HR oversight or lock.
- New employees should auto-receive onboarding tasks at creation time.
- Offboarding must support last working day, final settlement, clearance, exit interview, and access cutoff.
- Documents must enforce role-aware access and acknowledgement tracking.
- Expiring documents must notify HR 30 days in advance.

### APIs / Services Needed
- Employee observer for onboarding seeding
- `CheckDocumentExpiry`
- `CheckProbationDue`
- `CheckNewHireCheckIn`
- `SendReviewReminders`
- Document download and experience letter controllers

### UI Components (Livewire)
- `Performance\MyReview`
- `Performance\TeamReviews`
- `Performance\AllReviews`
- `Performance\ReviewCycles`
- `Performance\Goals`
- `Onboarding\OnboardingChecklist`
- `Onboarding\OffboardingManager`
- `Documents\DocumentManager`

### Notifications
- Review due reminders to employee and manager
- Document expiry reminders to HR
- Probation extension notice to line manager
- New-hire milestone reminder to manager

### Open Gaps
- Asset assignment and equipment return flow are service-driven and linked to offboarding equipment task completion. ✓
- Expense claim to reimbursement workflow is end-to-end including a rejection reason modal and inline rejection reason display. ✓
  - Remaining: notification templates for claim submitted, approved, and rejected states.
- Performance quarter windows (Conexus FY: Q1 Jul–Sep, Q2 Oct–Dec, Q3 Jan–Mar, Q4 Apr–Jun) and lock enforcement are implemented. ✓
  - Remaining: automated test coverage for quarter validation and review lock immutability.

## Phase 4: Polish

### Features List
- Audit logging
- Dashboards and reports
- Settings hardening
- Scheduled job coverage
- Backup and retention policies

### Required Database Tables
- `audit_logs`
- `notifications`
- reporting can be derived from existing transactional tables; no dedicated report tables are currently required

### Business Logic Conditions
- Create, update, delete, and review actions on sensitive HR objects must be audit logged.
- Notification retention currently prunes read notifications older than 90 days.
- All cron jobs must be mapped to explicit business rules and execution times.
- Settings access must distinguish HR Admin and Super Admin carefully.

### APIs / Services Needed
- `PruneNotifications`
- `GenerateAttendanceSummary`
- Spatie backup commands
- Gate and policy coverage across settings, finance, attendance, leave, and documents

### UI Components (Livewire)
- `Dashboard`
- `HrAdminDashboard`
- `ManagerDashboard`
- `ExecutiveDashboard`
- `FinanceDashboard`
- `DepartmentDashboard`
- settings pages under `resources/views/pages/settings`

### Notifications
- None new by phase; this phase hardens retention, routing, and observability for existing notifications.

### Open Gaps
- Route-level `role` middleware is now applied to all restricted modules. ✓
- Five missing audit observers (AttendanceRegularisation, Payroll, Incentive, Reimbursement, DocumentAcknowledgement) are now registered. ✓
- `hrms:carry-forward-leaves` is now scheduled annually (1 Jan 02:00). ✓
- Downloadable reports added: payroll summary PDF, attendance summary CSV, OT records CSV. ✓
- Backup configuration exists as package dependency but still needs production storage validation.
- Finance UI could expose rejection notes more prominently for audit visibility.
- The older route and settings architecture still contains starter-kit behaviors that do not always match HRMS expectation paths.
