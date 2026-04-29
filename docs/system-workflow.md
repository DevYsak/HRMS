# Pulse HRMS System Workflow

This document describes the current target workflow after the 2026-04-28 to 2026-04-29 Phase 1, Phase 2, and Phase 3 fixes.

## 1. Employee Lifecycle

1. HR creates an employee through `Employees\EmployeeCreate`.
2. The employee record is stored in `employees` and linked to `users`, department, manager, office, job title, and shift.
3. `EmployeeObserver` seeds onboarding tasks in `onboarding_tasks`.
4. Notifications triggered:
   None immediately by default.
5. Scheduled follow-up:
   `hrms:check-newhire-checkin` reminds manager at the 30-day milestone.
   `hrms:check-probation-due` reminds manager and HR before probation review.

## 2. Attendance Flow

1. Employee opens `Attendance\AttendanceTracker`.
2. `AttendanceService::checkIn()` creates an `attendances` row with date, IP, geo, work mode, late status, and late minutes.
3. Late logic:
   Shift start plus `grace_minutes` determines lateness.
   Current target rule is 5 minutes per shift.
4. Break flow:
   `AttendanceService::startBreak()` creates `break_logs`.
   `AttendanceService::endBreak()` closes the active break and syncs `attendances.break_minutes`.
5. Check-out flow:
   `AttendanceService::checkOut()` stamps checkout metadata and total hours.
   It also evaluates comp-off eligibility for MDL days and UK public holidays.
6. Scheduled enforcement:
   `hrms:check-late-arrivals` confirms late flags.
   `hrms:check-excess-breaks` sets `excess_break_flag` when break time exceeds 60 minutes.
   `hrms:flag-missing-checkouts` marks incomplete attendance rows.
7. Database updates:
   `attendances`
   `break_logs`
8. Notifications:
   None for standard punches.
9. Comp-off auto-credit:
   If the day is a `december_mandatory_days` date, or a UK holiday for UK-mapped staff, `LeaveService::creditCompOff()` is called.
   Balance updates include both `leave_balances.allocated_days` and `leave_balances.comp_off_credits`.

## 3. Attendance Regularisation Flow

1. Employee submits a correction request from `Attendance\AttendanceTracker`.
2. A row is created in `attendance_regularisations`.
3. Manager or HR reviews from `Attendance\TeamAttendance` or `Attendance\AllAttendance`.
4. `AttendanceService::approveRegularisation()`:
   Creates an `attendances` row if the day was a missed punch.
   Applies requested check-in and check-out.
   Resets lateness and missing checkout flags.
5. `AttendanceService::rejectRegularisation()` stores reviewer metadata only.
6. Notifications:
   `RegularisationReviewedNotification` to employee.
7. Audit:
   `AuditLog::record()` stores the regularisation action.

## 4. Leave Flow

1. Employee submits leave from `TimeOff\MyTimeOff`.
2. `LeaveService::submitRequest()` validates dates and available balance.
3. A row is stored in `leave_requests`.
4. Manager reviews team leave in `TimeOff\TeamTimeOff`.
5. HR can review all leave in `TimeOff\AllTimeOff`.
6. `LeaveService::reviewRequest()`:
   Reverts prior balance usage if an already-approved request is edited.
   Applies the new request state.
   Increments `leave_balances.used_days` only on approval.
7. Escalation:
   `hrms:escalate-leaves` creates `leave_escalations` after 24 hours of inaction.
8. Year rollover:
   `hrms:carry-forward-leaves` now writes year-specific rows using `LeaveService::carryForwardBalances()`.
   Carry forward uses remaining days (`allocated_days - used_days`) and supports no-lapse behavior.
9. Notifications:
   `LeaveRequestNotification` to manager or employee depending on state.
10. Leave policy administration:
   `TimeOff\TimeOffSettings` now manages leave type policy fields:
   `category`
   `allow_carry_forward`
   `carry_forward_limit`
   `allow_encashment`

## 5. Leave Encashment Flow

1. Employee submits encashment from `TimeOff\MyTimeOff`.
2. System validates:
   Leave type allows encashment.
   Sufficient balance exists.
   No duplicate pending or approved encashment exists in the same year.
3. A row is created in `leave_encashments`.
4. Reserved days are reflected in `leave_balances.encashed_days`.
5. During payroll generation, approved encashments are converted into payslip earnings and moved to `processed`.
6. Notifications:
   `LeaveEncashmentNotification` where configured.

## 6. OT Flow

1. Employee submits OT from `Overtime\MyOtRequests`.
2. `OvertimeService::submitRequest()` stores a pending `ot_requests` row.
3. Manager or approved reviewer reviews from `Overtime\ManageOtRequests`.
4. Approval path:
   `OvertimeService::approve()` marks the request approved.
   `OvertimeService::createOvertimeRecordFromApprovedRequest()` creates a durable `overtime_records` row with OT hours and amount only for approved requests.
   Duplicate overtime record creation for the same `ot_request_id` is blocked.
5. Rejection path:
   `OvertimeService::reject()` stores reviewer data and rejection status.
6. Escalation:
   `hrms:escalate-ot` notifies HR if a pending request sits longer than 24 hours.
7. Notifications:
   `OtRequestNotification` to reviewer on submit and to employee on review.

## 7. Payroll Flow

1. HR or finance opens `Payroll\Process`.
2. `PayrollService::generateDraft()` creates or refreshes the payroll batch for the selected month, year, and cycle.
   Draft generation is blocked when the batch is already `pending_finance` or `finalized`.
3. Included earnings and deductions:
   Salary components
   Approved incentives
   Approved reimbursements
   Approved OT records inside the cycle window
   Approved leave encashments
   Final settlement
   LWP deductions
4. Database updates during draft:
   `payrolls` stays in `draft`
   `payslips` and `payslip_items` are recreated for the run
   included incentives and reimbursements are linked to the payroll
   OT records are linked to payslips but are not marked paid yet
5. HR submits the draft.
6. `PayrollService::submitForFinanceApproval()` changes status from `draft` to `pending_finance`.
7. `PayrollApprovalNotification` is sent to finance-capable approvers.
8. Finance reviews in `Payroll\FinanceApproval`.
9. `PayrollService::approveFinance()`:
   Changes status from `pending_finance` to `finalized`
   Stamps finance approver fields
   Marks payslips `paid`
   Marks linked OT records `is_paid = true`
10. `PayrollService::dispatchFinalizedPayrollNotifications()`:
   Sends `PayslipGeneratedNotification`
   Sends `PayslipMail` with PDF attachment
11. Finance rejection:
   `PayrollService::rejectFinance()` returns the batch to `draft`, stores the finance note, and releases previously included incentives and reimbursements back to `approved`.

## 8. Incentive and Reimbursement Service Flow

1. HR/finance submits incentives from `Payroll\Incentives`.
2. `IncentiveService::submit()` creates a pending `incentives` row.
3. Reviewer actions:
   `IncentiveService::approve()` stamps approver metadata and sets status to `approved`.
   `IncentiveService::reject()` stamps reviewer data and sets status to `rejected`.
4. During payroll draft:
   `IncentiveService::includeApprovedForEmployeeMonth()` links eligible rows to payroll and moves status to `included`.
5. On finance rejection:
   `IncentiveService::releaseIncludedForPayroll()` restores included rows to `approved` for rerun safety.

6. HR/finance submits reimbursements from `Payroll\Reimbursements`.
7. `ReimbursementService::submit()` creates a pending `reimbursements` row.
8. Reviewer actions:
   `ReimbursementService::approve()` stamps approver metadata and sets status to `approved`.
   `ReimbursementService::reject()` stamps reviewer data and sets status to `rejected`.
9. During payroll draft:
   `ReimbursementService::includeApprovedForEmployeeMonth()` links eligible rows to payroll and moves status to `included`.
10. On finance rejection:
    `ReimbursementService::releaseIncludedForPayroll()` restores included rows to `approved`.

## 9. Payslip Access Flow

1. Employee opens `Payroll\MyPayslips`.
2. Download routes hit `PayslipController`.
3. Access is allowed to:
   The owning employee
   Payroll-capable users
   Any actor passing the payslip view gate
4. PDF resolution:
   Use stored `pdf_path` when available
   Otherwise generate via Dompdf from `resources/views/pdf/payslip.blade.php`

## 10. Document Flow

1. HR uploads company or employee documents in `Documents\DocumentManager`.
2. Files are stored privately and metadata is written to `documents`.
3. Employees acknowledge required documents through `document_acknowledgements`.
4. Signed download and experience-letter routes are handled by `DocumentController`.
5. `hrms:check-document-expiry` notifies HR for expiring documents.

## 11. Operations Asset Assignment Flow

1. HR/operations manages assets in `Operations\Assets`.
2. `AssetAssignmentService::createAsset()` creates inventory entries.
3. `AssetAssignmentService::assignAsset()`:
   Assigns asset to employee
   Sets status to `assigned`
   Creates `equipment_logs` action `issued`
4. Return flow:
   `AssetAssignmentService::returnAsset()`:
   Sets asset `available`
   Stores condition and return date
   Creates `equipment_logs` action `returned`
5. Offboarding integration:
   When no assigned assets remain, `AssetAssignmentService` marks matching offboarding equipment tasks complete.

## 12. Operations Expense Claim to Reimbursement Flow

1. Employee submits expense claim from `Operations\Expenses`.
2. Validation enforces:
   receipt required
   receipt MIME is PDF/image (`pdf,jpg,jpeg,png,webp`)
   file stored on `private` disk (`expense-claims/`)
3. `ExpenseClaimService::submit()` creates pending `expense_claims`.
4. Manager/HR reviewer approves or rejects:
   `ExpenseClaimService::approve()` creates reimbursement via `ReimbursementService::submit()`, then sets reimbursement `approved`.
   Claim status is updated to `approved`.
5. Payroll inclusion:
   Approved reimbursements are included by `ReimbursementService::includeApprovedForEmployeeMonth()` during draft payroll.
6. Rejection path:
   `ExpenseClaimService::reject()` sets claim status to `rejected` and stores reason.

## 13. Offboarding Flow

1. HR or operations manages offboarding through `Onboarding\OffboardingManager`.
2. Equipment return, exit interview, last working day, and settlement readiness are recorded.
3. Equipment return from this screen routes through `AssetAssignmentService::returnAsset()` to keep `equipment_logs` in sync.
3. `CheckActiveEmployee` middleware blocks system access after the employee is no longer active.
4. Final settlement can be included in payroll when `final_settlement_done` is true.

## 14. Performance Flow

1. HR manages cycles in `Performance\ReviewCycles`.
2. Quarter validation for named quarter cycles:
   Q1 = July 1 to September 30
   Q2 = October 1 to December 31
   Q3 = January 1 to March 31
   Q4 = April 1 to June 30
   based on Conexus financial year July 1 to June 30.
3. Employee self-review in `Performance\MyReview` is editable only in `draft` state.
4. Manager review in `Performance\TeamReviews` is editable only in `submitted` state.
5. HR completion in `Performance\AllReviews`:
   `PerformanceService::lockReview()` moves `manager_reviewed -> locked`.
6. Locked reviews are immutable in self and manager flows.

## 15. Audit and Scheduled Jobs

### Audit
- `EmployeeObserver`
- `UserObserver`
- `LeaveRequestObserver`
- `OtRequestObserver`
- `PayslipObserver`
- `EmployeeSalaryObserver`

### Scheduled Jobs
- `hrms:escalate-leaves`
- `hrms:flag-missing-checkouts`
- `hrms:check-late-arrivals`
- `hrms:check-excess-breaks`
- `hrms:escalate-ot`
- `hrms:check-document-expiry`
- `hrms:check-probation-due`
- `hrms:check-newhire-checkin`
- `hrms:send-review-reminders`
- `hrms:prune-notifications`
- `hrms:generate-attendance-summary`
- `backup:clean`
- `backup:run`

## 16. Data Model Notes

1. `leave_balances` is now year-scoped for uniqueness by:
   `employee_id + leave_type_id + year`
2. `leave_balances.comp_off_credits` tracks earned comp-off credits separately from generic allocation.
3. `attendance_regularisations.attendance_id` is nullable to support missed-punch regularisation.
