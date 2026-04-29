# Pulse HRMS Missing Features and Gap Report

Audit date: 2026-04-29

## Summary

The codebase already contains most of the major HRMS modules described by the Pulse v3.1 specification, but it still had several correctness and workflow defects that made the system look more complete than it actually was. The most important issues found were in payroll approval sequencing, attendance regularisation schema design, carry-forward logic, and weak RBAC enforcement on some Livewire management screens.

## Fixed In This Pass

### 1. Payroll approval flow was inconsistent
- Problem:
  `Payroll\Process::finalize()` was directly marking payroll finalized and notifying employees before finance approval, while `Payroll\FinanceApproval` expected a `pending_finance` status.
- Risk:
  Payslips could be exposed before the finance gate defined by the spec.
- Fix:
  Added `PayrollService`.
  Draft generation, finance submission, finance approval, rejection, and final notification dispatch are now separated.
  OT records are now marked paid only after finance finalization.

### 2. Attendance regularisation schema did not support missed punches
- Problem:
  `attendance_regularisations.attendance_id` was non-nullable, but the workflow supports regularising days where no attendance row exists.
- Risk:
  Missed-punch regularisation could fail at insert time.
- Fix:
  Added forward-fix migration `2026_04_28_130000_fix_attendance_regularisations_nullable_attendance_id.php`.
  Added `AttendanceService` and moved approval logic there.

### 3. Leave carry-forward command referenced a field that does not exist
- Problem:
  `CarryForwardLeaves` used `leave_types.base_entitlement`, but no such column exists.
- Risk:
  Year-end leave rollover would be wrong or fatal.
- Fix:
  Carry-forward now uses year-specific `leave_balances`, preserves base allocation by removing prior carry-forward, and writes a fresh row for the target year.

### 4. Leave and attendance review logic was duplicated and weakly guarded
- Problem:
  Balance sync and regularisation logic were duplicated across multiple Livewire screens.
  Some review screens lacked explicit authorization checks.
- Risk:
  Logic drift and accidental privilege exposure.
- Fix:
  Added `LeaveService` and `AttendanceService`.
  Added explicit role checks to leave review, attendance review, payroll processing, finance approval, and settings management screens.

## Still Missing or Incomplete

### Core
- Global attendance settings still keep a `late_grace_period = 15` model that conflicts with shift-based 5-minute grace rules.
- Attendance settings UI is still global-first, while the spec is shift-first.
- Comp-off credit on public holidays and mandatory days is not clearly enforced in the active attendance service path.
- Time-off settings UI does not manage leave policy metadata fully:
  `category`
  `allow_carry_forward`
  `carry_forward_limit`
  `allow_encashment`

### Finance
- Payroll summary PDF export added (`/reports/payroll-summary.pdf?month=&year=`) — accessible to payroll-capable roles.
- Attendance summary CSV export added (`/reports/attendance-summary.csv`) — accessible to leave-approver roles.
- OT records CSV export added (`/reports/ot-records.csv`) — accessible to OT-approver roles.
- Finance notes and rejection reasons exist structurally but need stronger UI exposure and audit visibility.

### People Ops
- Asset assignment workflow is now operational with service-based create, assign, return, and equipment log writes.
- Offboarding asset returns now route through the same service flow and can auto-complete matching equipment offboarding tasks.
- Expense claims now run end-to-end:
  employee submit with private receipt storage and PDF/image MIME validation
  manager or HR approval
  auto-conversion to approved reimbursement for payroll inclusion
- Performance workflow now validates quarter windows against Conexus FY (July-June) for Q1-Q4 and enforces locked review immutability after HR completion.
- Expense rejection now captures the reason via a dedicated modal; the reason is surfaced in the claim list for rejected records.
- Remaining work is deeper regression testing for People Ops edge cases and notification coverage for expense claim transitions.

### Polish
- Backup package presence does not equal production-ready backup validation.
- Audit logging is present, but not every important policy-controlled business action is yet guaranteed to emit a domain-level audit event.
- Notifications are mostly in-app; some workflows still need explicit mail or escalation verification.

## RBAC Findings

### Fixed
- Leave review screens now require leave-approval capability.
- Attendance review screens now require leave-approval capability.
- Payroll processing screens now require payroll capability.
- Finance approval screen now requires finance-approval capability.
- Attendance and leave settings screens now require settings-management capability.

### Remaining Risks
- Route-level `role` middleware now protects: employee management, time-off team/all, attendance team/all/settings, overtime manage, payroll admin, finance approval, performance team/cycles/all, operations assets, document experience-letter, and report downloads. ✓
- Expense claims route remains open to all authenticated users (employees submit their own); approval is still component-side only.
- Some modules such as incentives and reimbursements Livewire actions should still be reviewed for explicit action-level policy guards.

## Data Inconsistencies Found

- `attendance_regularisations.attendance_id` non-nullable despite missed-punch workflow.
- `leave_balances` has year-aware fields, but older logic ignored them.
- `attendance_settings` still represents a global grace model while `shift_settings` has the actual spec-aligned grace field.
- Payroll status model existed, but the state transitions did not reflect the real approval workflow.

## Module-by-Module Status

### Employee Management
- Mostly implemented.
- Remaining work is around richer settings governance and regression coverage of manager and probation flows.

### Attendance
- Implemented with improved service structure.
- Remaining gaps are shift-vs-global settings cleanup and comp-off automation clarity.

### Leave
- Implemented with improved approval and rollover logic.
- Remaining gap is full policy administration UI and stronger overlap-edge-case tests.

### Overtime
- Implemented.
- Remaining work is reviewer scoping refinement and expanded test coverage for approval edge cases.

### Payroll
- Implemented with corrected approval sequencing.
- Remaining work is broader reporting, more policy coverage, and additional finance UX polish.

## Finance Updates In 2026-04-29 Pass

- Added strict payroll sequencing guards so payroll draft generation is blocked once status becomes `pending_finance` or `finalized`.
- Preserved spec order: notifications are dispatched only after `approveFinance()` finalizes payroll.
- Added `IncentiveService` and `ReimbursementService` and moved submit, approve, reject, inclusion, and rollback behavior into service layer.
- Updated payroll draft generation to include incentives and reimbursements through service methods.
- Added rejection rollback so finance rejection resets included incentives and reimbursements back to `approved` for safe reprocessing.
- Hardened OT policy in `OvertimeService` so overtime records are created only from approved OT requests and duplicate materialization is prevented.

## People Ops Updates In 2026-04-29 Pass

- Added `AssetAssignmentService` for asset create/assign/return workflows and consistent `equipment_logs` audit trail updates.
- Updated `Operations\Assets` Livewire UI to fully handle create, assign, and return actions through service layer.
- Updated `Onboarding\OffboardingManager` to use service-driven asset return and keep equipment workflow aligned.
- Added `ExpenseClaimService` for employee claim submission and manager or HR review actions.
- Updated `Operations\Expenses` to support private receipt upload validation (`pdf/jpg/jpeg/png/webp`) and claim approval path into payroll reimbursements via `ReimbursementService`.
- Added `PerformanceService` with Conexus FY quarter validation (`Q1 Jul-Sep`, `Q2 Oct-Dec`, `Q3 Jan-Mar`, `Q4 Apr-Jun`) and strict lock-state enforcement after HR completion.

### Performance
- Implemented.
- Quarter windows match Conexus FY (Q1 Jul–Sep, Q2 Oct–Dec, Q3 Jan–Mar, Q4 Apr–Jun).
- Lock enforcement prevents edits after HR marks a review complete.
- Remaining work is automated test coverage for quarter validation and lock immutability.

### Onboarding / Offboarding
- Present and usable.
- Final settlement linkage is in place, but offboarding still depends on surrounding payroll and operations maturity.

### Documents
- Present and usable.
- Should be revalidated for all role-scoped access scenarios and acknowledgement edge cases.

### Operations
- Implemented.
- Asset create/assign/return flow is fully service-driven through `AssetAssignmentService`.
- Equipment logs are written on every assign and return action.
- Offboarding asset return routes through the same service and auto-completes matching offboarding tasks.
- Expense claim submission requires a receipt (PDF/image only, stored on private disk).
- Manager/HR can approve (auto-converts to reimbursement for payroll inclusion) or reject (with mandatory reason captured via modal).
- Rejection reason is surfaced in the expense list for rejected claims.

## Phase 4 Updates In 2026-04-29 Pass

- Added `EnsureRole` middleware with alias `role` and ability strings: `manage-employees`, `approve-leave`, `approve-ot`, `run-payroll`, `approve-finance`, `manage-settings`, `manage-documents`.
- Applied route-level middleware to all restricted modules: employees (create/edit/onboarding), time-off (team/all/settings), attendance (team/all/settings), overtime (manage), payroll (admin group + finance-approve), performance (team, cycles, all-reviews), operations (assets), documents (experience-letter), and all report download routes.
- Added five missing audit observers: `AttendanceRegularisationObserver`, `PayrollObserver`, `IncentiveObserver`, `ReimbursementObserver`, `DocumentAcknowledgementObserver`. All registered in `AppServiceProvider`.
- Added `hrms:carry-forward-leaves` to the cron schedule (1 Jan 02:00 annually) — was previously implemented but never scheduled.
- Added `ReportController` with three export endpoints:
  - `GET /reports/payroll-summary.pdf` — dompdf PDF with all payroll runs for the month
  - `GET /reports/attendance-summary.csv` — CSV of all attendance records for the month
  - `GET /reports/ot-records.csv` — CSV of all OT records for the month
- Export buttons added to: payroll overview (PDF), all-attendance (CSV), manage-OT-requests (CSV).

## Recommended Next Steps

1. Unify attendance configuration so shift-level settings are the single source of truth.
2. Add automated tests for incentive/reimbursement service edge cases.
3. Expand tests around payroll cycle state changes, carry-forward behavior, and missed-punch regularisation.
4. Add automated tests for performance quarter validation and review lock immutability.
5. Add notification templates for expense claim submitted, approved, and rejected states.
6. Add route-level middleware segmentation for finance, HR, and settings modules to complement component checks.
