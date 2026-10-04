# RBAC sweep — Part B (Livewire components + Policies)

Repo: `C:\Users\91937\Desktop\HRMS\pulse` (branch `staging`, working tree as of 2026-10-04). Read-only audit; nothing executed except reads/greps.

## Ground rules verified first

- `EnsureRole` (app/Http/Middleware/EnsureRole.php:156-174) maps `role:<ability>` to `User::can*()` which call `User::hasPermission()` (app/Models/User.php:146-226) → DB `roles`/`permissions` via `assignedRole`. Super Admin always true.
- `app/Services/RolePermissionService.php` (legacy kebab keys) and the `UserRole::can*()` enum helpers are **not referenced anywhere** — dead second source of truth.
- `can:<perm>` route middleware and `$this->authorize('<perm>')` resolve through `Gate::before` (app/Providers/AppServiceProvider.php:97-103) → `hasPermission()` when a `permissions.key` row exists.
- `Livewire::addPersistentMiddleware([EnsureRole::class, Authorize::class])` (AppServiceProvider.php:78) — route `role:`/`can:` middleware is re-applied on every Livewire update, including child components embedded in that page. So route middleware *does* protect actions; holes below are about scope/self/role *inside* the allowed population.
- Default permissions come from `database/seeders/RolesAndPermissionsSeeder.php:133-190`. Notables: **director** has `manage_employees, create/edit/delete_employee, approve_finance, approve_payroll, manage_kpi_templates, manage_review_cycles, manage_warning_letters, manage_onboarding/offboarding`; **finance** has `review_performance, manage_promotions, manage_pip`; **manager** has `approve_leave, approve_overtime, approve_wfh, approve_regularisation, review_performance, manage_promotions, manage_pip`.
- Scope engine `app/Services/Approvals/ApprovalGuard.php`: company-wide = Super Admin, or `manage_employees` with no `scope_departments/scope_shifts` (:35-57). Reporting line = `employees.manager_id = users.id` + active team lead/secondary lead members + members of departments where `departments.head_id = users.id` (:172-208). `assertCanDecide` blocks self + out-of-scope (:130-145).
- `EmployeeEdit::save` only keeps scopes for `hr_admin`/`manager` buckets (app/Livewire/Employees/EmployeeEdit.php:248) → **a Director can never be scoped, so Director is always company-wide**.

Legend: PASS = correct for spec; PARTIAL = works but scope/role deviates or minor gap; HOLE = confirmed exploitable gap.

---

## Component tables

### TimeOff

| Component | Route + middleware | mount auth | Actions (auth? / scope?) | Locked props | Verdict |
|---|---|---|---|---|---|
| MyTimeOff | `/time-off/my` auth only | none needed (own) | submitRequest/submitHolidayWork/submitEncashment use `Auth::user()->employee` (:294,325,495); cancelRequest via `$employee->leaveRequests()->findOrFail` (:462); openConversation/postConversationMessage re-scoped to own (:397,423). Team availability (:871-898) = per-dept counts only, no names/reasons | conversationId not locked but re-scoped | PASS |
| MyLeaveBalances (embedded in MyTimeOff) | inherits `/time-off/my` | — | all computed from `Auth::user()->employee` (:48-144) | — | PASS |
| TeamTimeOff | `role:approve-leave` | selectRequest `canApproveLeave` + `assertCanView` (:99-102) | approve/reject → `LeaveService::reviewRequest` → `assertCanReview` (LeaveService.php:1264-1275, scope+self); requestMoreInfo → service `assertCanDecide` (:554); HR override → `hrOverridePaymentStatus` enum HR/SA + `assertCanDecide` (:691-695). List = `reportingLineIds` (:302) | selectedRequestId not locked (actions re-guarded in service) | PASS |
| AllTimeOff | `role:approve-leave` | per-action `canApproveLeave` | viewRequest/manageRequest `assertCanView`; quickApprove/quickReject/saveManage → service guard; reopening decided leave needs `canManageEmployees` (LeaveService.php:1272); submitNewRequest `coversEmployee` (:491) but does not require `apply_leave_on_behalf`. List scoped by `accessibleEmployeeIds` (:520) | viewingId, editingId #[Locked] | PASS (Director company-wide by design) |
| EmployeeLeaveDetail | `can:view_leave_management` | `authorize('view_leave_management')` (:125) | submitAction per-permission + **self blocked** (:294-304); `revokeOverride` (:378) and `reverseCarryForward` (:341) have no self check; no scope check for scoped HR | employee is model prop | PARTIAL |
| LeaveManagement | `can:view_leave_management` | `authorize` (:88) | bulk provision/add-on authorize `bulk_allocate_leave`/`add_leave_balance`; `bulkEmployees()` (:305-310) takes client `selected[]` with **no self exclusion, no scope**; rows unscoped (LeaveManagementService.php:69-85) | `selected` not locked | HOLE (self-credit, see M-5) |
| BulkLeaveAssignment | `role:manage-settings` | `authorize('manage-settings')` | apply (:92) — filters may include self | — | PARTIAL (self, M-5) |
| LeaveCarryForward | `can:view_leave_carry_forward` | authorize | apply*/reverse authorize `manage_leave_carry_forward`; no self exclusion (capped by recorded balance, LeaveCarryForwardService.php:120-127) | selected/decisions not locked (HR only) | PARTIAL |
| LeaveRegularisation | `can:view_leave_regularisation` | authorize | submitRequest `assertCanView`; approve/reject → AttendanceService `assertCanDecide` (AttendanceService.php:144,430); cancel own or `manage_leave_regularisation`; list `inReach` | reviewId #[Locked] | PASS |
| FinanceEncashments | `role:approve-finance` (Finance, Director, SA) | render checks | approve: pending → `approveEncashment` (enum HR/Director/SA + assertNotSelf, LeaveService.php:1042-1046); pending_finance → `canApproveFinance` + assertNotSelf; reject (:75) lets **Finance reject HR-stage** encashments; `openReview` (:37) claims any id without auth; list unscoped (Director sees all) | reviewingId not locked (re-guarded) | PARTIAL |
| HistoricalBalances | `can:manage_leave_balances` | authorize | all actions authorize | parsed #[Locked] | PASS |
| LeaveYearRollover | `can:run_leave_rollover` | authorize | all actions authorize | — | PASS |
| LeaveReconciliation | `can:reconcile_leave` | authorize | all actions authorize | selected (HR only) | PASS |
| LeaveAllocationPolicies | `role:manage-settings` | authorize | save/delete authorize; openEdit read-only | — | PASS |
| TimeOffSettings | `role:manage-settings` | render check | openModal/save/delete `canManageSettings` | — | PASS |

### Performance

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| Dashboard | auth | own employee | all queries `employee_id = own` | — | PASS |
| MyReview | auth | — | openReview own-scoped (:260-262); submitSelfReview writes strengths/improvements (:304) **before** service status check (:323) → can overwrite text on a locked own review | activeReview model | PARTIAL (integrity) |
| MyKpis | auth | — | open/save re-scoped to own (:156,172) | — | PASS |
| Goals | auth | — | `ownGoals()` (:92-95) | editingId #[Locked] | PASS |
| EmployeeScorecard | auth | own / reviewer / `coversEmployee` (:26-36) | — | — | PASS |
| MyPip / MyPromotions / MyWarnings | auth | — | view by id checks own employee_id but not status → own **draft** records readable (list hides drafts) | — | PARTIAL (low) |
| ReviewTasks | auth | — | `ownParticipant` (`reviewer_id = Auth::id()`) | — | PASS |
| TeamReviews | `role:review-performance` | — | `assertCanManagerReview` (self + covers/participant, ReviewWorkflowService.php:259-272) on open and submit | activeReview model | PASS |
| AllReviews | `role:manage-employees` | render `canManageEmployees` | submitHrReview/lockReview (:54-119) → ReviewWorkflowService::submitHrReview/lockReview (:158-217) **no self, no scope** | viewingReview model | HOLE (M-3) |
| PerformanceCycles | `role:manage-employees` | `canManageEmployees` | actions rely on persistent route mw | — | PASS (Director company-wide) |
| IncrementCenter | `role:manage-employees` | `canManageEmployees` | saveProposal/applyOverride (:89,121) **no self check**; approveCycle/applyCycle (:155,167) `canApproveFinance||SA` — Director approves cycle incl. own row; IncrementService has no ApprovalGuard | cycleId/overrideProposalId not locked (re-scoped by cycle) | HOLE (H-4) |
| KpiDashboard | `role:manage-employees` | render `canManageSettings||isManager` | — | — | PASS |
| KpiTemplates | `role:manage-employees` | render `canManageSettings` | writes `canManageSettings` | — | PASS |
| ManagePips | `role:review-performance` | checkPermission | create `assertCanDecide`; view `inReach` excl. own (:326-334); activate/goal/outcome `assertActiveRecordInReach` | progressGoalId #[Locked] | PASS (Finance reachable — matrix) |
| ManagePromotions | `role:review-performance` | checkPermission | create `assertCanDecide`; view blocks self + covers; approve/reject stage check + `assertCanDecide` (:239-246); list for HR/Director unscoped incl. own recs | activeRecommendation model | PASS/PARTIAL |
| WarningLetters | `role:manage-employees` | render | issue `canManageEmployees`; view own-issued or HR; **closeWarning/escalateWarning (:182-222) no self check** | activeWarning model | PARTIAL (L-2) |

### Attendance

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| AttendanceTracker | `/attendance/my` auth | own | punch/break/tasks/regularisation all `Auth::user()->employee` | todayAttendance model (untyped; service methods are typed, so tampering only errors) | PASS |
| TeamAttendance | `role:approve-leave` | render `canApproveLeave` | openReviewModal `assertCanDecide`; approve/reject via AttendanceService guard. Team = `subordinates()` direct only (:115) | activeRequest model | PASS (team def. differs) |
| AllAttendance | `role:approve-leave` | render | drawer/regularisation `coversEmployee`; mark attendance `canManageEmployees` + fastTrack `assertCanDecide`; list scoped (:583); **exportCsv (:70-101) unscoped** | activeRequest model | HOLE (M-1) |
| CommandCenter | `role:approve-leave` | `canApproveLeave` | all decide* use `inReach` + services' `assertCanDecide`; exportPending scoped (:427). OT/WFH/holiday decided with approve-leave only | — | PASS |
| AttendanceReports | `role:approve-leave` | `canApproveLeave` | builder gets `employee_ids = reach` (:73-74) | — | PASS |
| ExecutiveAttendance | `role:approve-leave` | `canApproveLeave` | **render (:102-160) company-wide, named top/bottom performers + scores** | — | HOLE (M-1) |
| BiometricSummary | `role:approve-leave` | — | **render (:102-131) company-wide per-employee punches/late/OT**; syncNow (:57) company-wide engine re-sync | — | HOLE (M-1, M-2) |
| BiometricControl | `role:approve-leave` | `canApproveLeave` | **syncNow (:53) / exportLogs (:72)** company-wide; device IPs shown | — | HOLE (M-1, M-2) |
| AttendanceSettings | `role:manage-settings` | `canManageSettings` | all writes checked | — | PASS |
| BiometricAttendance | not routed / not embedded | render `canApproveLeave` | — | — | N/A (orphan) |
| BiometricSync | not routed / not embedded | **none** | saveMapping/removeMapping/syncAll have no auth | — | Latent (L-6) |

### Overtime

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| MyOtRequests | auth | own | submit own; cancel `where employee_id = own` (:121) | — | PASS |
| ManageOtRequests | `role:approve-ot` | `checkOtPermission` | open/edit/review `assertCanView/Decide`; approve/reject service guard; list scoped (:282). saveEdit (:109) edits even approved OT; syncFromNexflow company-wide import | reviewingId #[Locked] | PASS/PARTIAL |
| NexflowOtPanel | `role:approve-ot` | `canApproveOt` | **importRecord/importAllApproved (:87-136) import from client-writable `public ?array $data` (:31); `employeeId` (:21) any employee, no scope/self** → OvertimeService::importNexflowOtRecord (:277-350) creates approved OtRequest + OvertimeRecord | **$data and employeeId NOT locked** | HOLE (H-1) |
| OvertimeSummaryWidget | not mounted | none | company-wide counts | — | N/A (orphan) |

### Payroll

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| MyPayslips | auth | own | emailPayslip own-scoped (:166-170); print delegates to controller ownership check | — | PASS |
| Overview | `role:run-payroll` | render | read-only | — | PASS |
| Process | `role:run-payroll` | render | all actions role-checked; **saveEdit (:394) edits any draft payslip incl. operator's own** | editingId not locked (run-payroll only) | PARTIAL (M-4) |
| FinanceApproval | `role:run-payroll,approve-finance` | `authorizeAccess` = finance **or run-payroll** (:32-35) | **legacy approve (:46) / confirmReject (:82)** → PayrollService::approveFinance/rejectFinance (:235-271) have no `canApproveFinance` check, only maker≠processor; step path is eligibility-checked (:330-337) | rejectingId not locked | HOLE (H-5) |
| Incentives | `role:run-payroll` | none | **submit (:47) any employee incl. self; approve/reject (:69,76) no self/maker-checker/status check** (IncentiveService.php:14-55) | — | HOLE (H-2) |
| Reimbursements | `role:run-payroll` | none | **same as Incentives** (:62-107; ReimbursementService.php:14-66) | — | HOLE (H-2) |
| SalaryStructures | `role:run-payroll` | none | assign (:133) any employee incl. self | — | PARTIAL (M-4) |
| Components / HistoricalImport | `role:run-payroll` | none (route) | config/import | — | PASS |
| AuditTrail | `role:run-payroll` | `authorize('view_payroll')` | export authorized | — | PASS |

### Operations / Onboarding / Documents

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| Expenses | auth | — | submit own; approve/reject `canReviewClaim` = not self + covers (:189-196); list scoped (:205-211) | reviewingId re-guarded | PASS |
| Assets | `role:manage-employees` | per-action `canManageEmployees` | — | — | PASS (Director company-wide) |
| MyOnboarding | auth | own | toggle only own tasks with `owner_role = employee` (:40-46) | — | PASS |
| OnboardingChecklist / OffboardingChecklist | `role:manage-employees` | none (route) | tasks re-scoped to `employeeId` | employeeId not locked (HR-only) | PASS |
| OnboardingManager | `role:manage-employees` | render | read | — | PASS |
| OffboardingManager | `role:manage-employees` | per-action | processOffboarding (:75) any employee (incl. HR/SA records → status inactive → lock-out), sets final settlement (spec: Finance) | selectedEmployeeId not locked | PARTIAL (M-6) |
| DocumentManager | auth | — | list/preview/versions via `assertCanAccess` (:98-114); **`canApproveFinance()` users see every `payslip` doc (:111,173-175) → Director**; acknowledge any id (:116); delete `canManageDocuments` | versionsFor/previewId re-checked in render | HOLE (M-7) |

### Employees

| Component | Route + middleware | mount auth | Actions | Locked | Verdict |
|---|---|---|---|---|---|
| Directory | auth | `viewAny` (true) | basic fields only (name/email/title/dept) | — | PASS |
| OrgChart | auth | `view` own | HR/SA all; dept head = own `department_id` (:104-107); others manager+reports | — | PASS |
| EmployeeIndex | auth (list) | `viewAny` | non-managers filtered `manager_id = user` (:242-245); delete/restore/forceDelete/invite via EmployeePolicy (= `canManageEmployees`, no hierarchy/self) | — | PARTIAL (M-6) |
| EmployeeCreate / EmployeeImport | `role:manage-employees` | `authorize('create')` | RoleDelegationGuard on role (:259) | — | PASS (Director can create — matrix) |
| EmployeeEdit | `role:manage-employees` | `authorize('update')` | save has RoleDelegationGuard; **saveSalary/removeSalary (:531,559) no self/finance check; submitLeaveAdjustment/submitHistoricalBalance (:769,725) no self check** | editingSalaryId #[Locked] | HOLE (H-3, M-5) |
| EmployeeDocuments (embedded in EmployeeEdit) | inherits manage-employees | — | upload/delete any employee's docs | employee model | PARTIAL (Director) |
| NexflowActivity (embedded) | inherits manage-employees | — | read | — | PASS |
| FinanceEmployeeProfile | `role:view-finance-profile` | `canViewFinanceProfile` | read | — | PASS |
| ProbationConfirmation | `role:manage-employees` | approve-leave or manage | managerConfirm enum manager/director/SA (ProbationEngine.php:80-84), **no scope/self**; hrApprove enum HR/SA, **no self**; extend no role check (route only) | employee model | PARTIAL (L-1) |
| TeamManagement | `role:manage-employees` | `canManageEmployees` | — | — | PASS |

---

## Confirmed holes (ranked High → Low)

**H-1 — NexflowOtPanel: client-forged "approved" overtime, any employee incl. self.**
`app/Livewire/Overtime/NexflowOtPanel.php:31` `public ?array $data` (not #[Locked]) feeds `importRecord()` (:87-109) / `importAllApproved()` (:111-129) → `OvertimeService::importNexflowOtRecord()` (app/Services/OvertimeService.php:277-350), which creates an `approved` OtRequest + payable OvertimeRecord, or flips an existing Nexflow OT's status (:300-306). `employeeId` (:21) is any employee, no `coversEmployee`/self check; no ApprovalGuard on this path.
Exploit: any Manager/Director/HR (`approve_overtime`) sets `$wire.data = {ot_records:[{id:…, status:'approved', date:…, ot_hours:40}]}`, picks their own employeeId, calls `importRecord` → paid OT in their own payroll.
Fix: mark `$data` (and `employeeId`) `#[Locked]` or re-fetch the record from Nexflow server-side inside `importRecord`; add `abort_unless(Auth::user()->coversEmployee($employee) && ! isSelf, 403)` in `selectedEmployee()` and filter the picker by `accessibleEmployeeIds()`.

**H-2 — Incentives / Reimbursements: self-submit + self-approve, no maker-checker.**
`app/Livewire/Payroll/Incentives.php:47-81`, `app/Livewire/Payroll/Reimbursements.php:62-107`; services `IncentiveService.php:14-55`, `ReimbursementService.php:14-66` have no self/requester≠approver/status check.
Exploit: an HR Admin or Finance user submits a ₹ incentive/reimbursement for their own employee record and approves it; it is pulled into their payslip (`includeApprovedForEmployeeMonth`). Also re-approves rejected/paid rows.
Fix: in both `approve()`/`reject()` call `app(ApprovalGuard::class)->assertNotSelf($user, $row->employee)`, reject `requested_by === approver`, and require `status === 'pending'`; exclude self from the submit employee list.

**H-3 — EmployeeEdit salary edit: own salary, and Director can edit anyone's.**
`app/Livewire/Employees/EmployeeEdit.php:531-566` (`saveSalary`, `removeSalary`) authorize only `update` = `canManageEmployees` (EmployeePolicy.php:32-35).
Exploit: a Director (seeded `manage_employees`) or HR Admin opens `/employees/{own id}/edit` and raises their own salary components; Director edits any employee's compensation (spec: Director "View dept cost", not edit).
Fix: gate salary actions with `run_payroll`/`manage_salary_components` (+ `view_finance_profile` for the tab) and block `$this->employee->user_id === Auth::id()`.

**H-4 — IncrementCenter: self-proposal and Director self-approval of increments.**
`app/Livewire/Performance/IncrementCenter.php:89-177`; `app/Services/Increments/IncrementService.php:118-222` has no self check.
Exploit: Director (manage_employees + approve_finance) overrides own band to A / sets own % (`applyOverride`, `saveProposal`), then `approveCycle` + `applyCycle` writes the raise to their salary rows. HR Admin can likewise set their own proposal.
Fix: refuse `overrideBand`/`updateProposal` when `proposal->employee->user_id === actor->id`; in `approveCycle` refuse (or exclude) proposals belonging to the approver.

**H-5 — FinanceApproval legacy path lets HR Admin (no `approve_finance`) finalize/reject payroll.**
`app/Livewire/Payroll/FinanceApproval.php:32-35,46-68,82-115`; `PayrollService::approveFinance()`/`rejectFinance()` (app/Services/PayrollService.php:235-271) only check status + maker≠processor.
Exploit: when no PayrollApprovalPolicy steps are active, an HR Admin who did not generate the run (e.g. Finance processed it, or a second HR Admin) clicks Approve → payroll finalized without Finance sign-off.
Fix: in `approve()`/`confirmReject()` (legacy branch) `abort_unless(Auth::user()->canApproveFinance(), 403)`; mirror in `approveFinance()`.

**M-1 — Manager sees company-wide attendance on four screens.**
`AllAttendance::exportCsv()` app/Livewire/Attendance/AllAttendance.php:70-101 (no `accessibleEmployeeIds`, unlike render :583); `ExecutiveAttendance::render()` app/Livewire/Attendance/ExecutiveAttendance.php:102-160 (named top/bottom performers, scores, dept stats); `BiometricSummary::render()` app/Livewire/Attendance/BiometricSummary.php:102-131; `BiometricControl::exportLogs()` app/Livewire/Attendance/BiometricControl.php:72-90. All behind `role:approve-leave` only.
Exploit: a Manager calls `exportCsv` (or opens /attendance/executive, /attendance/biometric-summary) and gets every employee's attendance.
Fix: apply `->when(($ids = Auth::user()->accessibleEmployeeIds()) !== null, fn ($q) => $q->whereIn('employee_id', $ids))` in each; or move executive/biometric routes to `role:manage-employees`/`can:manage_biometric`.

**M-2 — Manager can re-run the biometric engine sync for any date, overwriting attendance company-wide.**
`BiometricControl::syncNow()` :53-70 and `BiometricSummary::syncNow()` :57-75 (route `role:approve-leave`, `$this->date` client-set) → `EngineAttendanceSyncService::syncDate()` `Attendance::updateOrCreate(... check_in/check_out ...)` (app/Services/Biometric/EngineAttendanceSyncService.php:113-126) — can revert approved regularisations/HR corrections.
Fix: gate with `can:manage_biometric` (HR/SA), and skip rows that have an approved regularisation.

**M-3 — AllReviews: HR Admin / Director can HR-score and lock their own performance review.**
`app/Livewire/Performance/AllReviews.php:33-119` → `ReviewWorkflowService::submitHrReview()/lockReview()` (:158-217) — no self/scope check (manager stage has one at :259-272).
Fix: in `viewReview` and both actions `abort_if(app(ApprovalGuard::class)->isSelf(Auth::user(), $review->employee), 403)` and `coversEmployee`.

**M-4 — Payroll operator can edit own draft payslip / assign own salary structure.**
`app/Livewire/Payroll/Process.php:394-422` (`saveEdit` → `updatePayslipItems`, PayrollService.php:598-630) and `app/Livewire/Payroll/SalaryStructures.php:133-160` — no self check. Combined with H-5, an HR Admin can add earnings to own payslip and finalize.
Fix: block when `payslip->employee->user_id === Auth::id()` / `employee->user_id === Auth::id()`.

**M-5 — Self leave-balance credit bypasses the EmployeeLeaveDetail self-guard.**
Guard exists only at app/Livewire/TimeOff/EmployeeLeaveDetail.php:299-304. Unguarded: `LeaveManagement::confirmBulkAddOn()` :230-282 via `bulkEmployees()` :305-310 (client `selected[]`), `BulkLeaveAssignment::apply()` :92, `EmployeeEdit::submitLeaveAdjustment()` :769 / `submitHistoricalBalance()` :725, `EmployeeLeaveDetail::revokeOverride()` :378 / `reverseCarryForward()` :341, `LeaveCarryForward::applySelected()` :212 (capped). `LeaveBalanceService::adjust()` has no self check.
Exploit: HR Admin ticks own row (or posts own id in `selected`) and bulk-grants add-on CSL days to self.
Fix: put the self check in `LeaveBalanceService::adjust()/setHistoricalBalance()` and the bulk services (exclude `Auth::user()->employee?->id`).

**M-6 — Director/HR can deactivate or delete any account, including HR Admin / Super Admin employee records.**
`EmployeeIndex::deleteEmployee()` app/Livewire/Employees/EmployeeIndex.php:154-173 (policy = `canManageEmployees`), `OffboardingManager::processOffboarding()` app/Livewire/Onboarding/OffboardingManager.php:75-120 (exit record + `status inactive` → CheckActiveEmployee lock-out). No self/hierarchy guard (EmployeeEdit::save has RoleDelegationGuard only for role changes).
Fix: reuse `RoleDelegationGuard` (target outranks actor / is self → refuse) in EmployeePolicy::delete/update and in processOffboarding.

**M-7 — Director sees every payslip document.**
`app/Livewire/Documents/DocumentManager.php:111` and `:173-175` grant `category = payslip` to `canApproveFinance()` users; Director is seeded with `approve_finance`. Spec: Documents (Payslips) Director = No access.
Fix: use `canRunPayroll()`/`view_payslips`+Finance role check (or a dedicated `view_all_payslips` permission) instead of `canApproveFinance()`.

**L-1 — Probation self-approval / unscoped confirm.** `ProbationEngine::managerConfirm()` (app/Services/ProbationEngine.php:78-100) accepts any manager/director/SA with no covers/self check; `hrApprove()` (:103-129) lets an HR Admin approve their own probation. Fix: `assertCanDecide($user, $employee)` in both.

**L-2 — WarningLetters close/escalate own warning.** app/Livewire/Performance/WarningLetters.php:182-222 (no self check; viewWarning lets HR open own). Fix: `abort_if(isSelf)` in close/escalate.

**L-3 — FinanceEncashments stage leak.** app/Livewire/TimeOff/FinanceEncashments.php:75 lets a Finance user reject an HR-stage (`pending`) encashment; `openReview` (:37) claims any id with no role check. Fix: Finance may reject only `pending_finance`; authorize in `openReview`.

**L-4 — ManageOtRequests::saveEdit edits approved OT.** app/Livewire/Overtime/ManageOtRequests.php:109-134 has no `isPending()` check, so dates/times change after the OvertimeRecord exists. Fix: require pending.

**L-5 — Own draft records readable by id.** MyPip.php:99-103, MyPromotions.php:171-175, MyWarnings.php:223-227 do not exclude `draft`; MyReview.php:304 writes text before the status check (:323). DocumentManager::acknowledge (:116) accepts any document id. Fix: add the same status/visibility filters used by the list.

**L-6 — Latent: BiometricSync has zero authorization** (app/Livewire/Attendance/BiometricSync.php:99-256: syncAll, saveMapping, removeMapping). Not routed/embedded today (also BiometricAttendance, OvertimeSummaryWidget) so unreachable; add `abort_unless(can manage_biometric)` or delete before anyone mounts it.

---

## Policy consistency

1. **Policies are mostly dead.** `LeavePolicy` is never resolved (model is `LeaveRequest` → Laravel looks for `LeaveRequestPolicy`; no `Gate::policy` registration) and never called. `AttendancePolicy` and `PayrollPolicy` have no `authorize()` callers in Livewire (grep). Real enforcement = `ApprovalGuard` + inline checks. Their logic also contradicts ApprovalGuard: `LeavePolicy::approve` = `canApproveLeave()` with no scope/self; `AttendancePolicy::view` excludes managers; `AttendancePolicy::approveRegularisation` unscoped.
2. **EmployeePolicy diverges from ApprovalGuard.** `view` (EmployeePolicy.php:16-25) = enum `isManager()` + direct report only (ignores team leads, dept heads, scoped HR). `update/delete/invite` = `canManageEmployees()` with no scope, no self, no hierarchy → scoped HR and Director can edit/delete anyone.
3. **Key semantics are consistent:** `employees.manager_id → users.id` and `departments.head_id → users.id` everywhere (ApprovalGuard :184,:196; Employee::subordinates :334-337; EmployeeIndex :244; EmployeePolicy :23). Spec names `head_employee_id` (employee id) — naming deviation only.
4. **"Team" means different things per screen:** ApprovalGuard reporting line (direct + team lead/secondary + headed dept) vs TeamAttendance `subordinates()` direct only (:115) vs EmployeeIndex `manager_id` only (:242-245) vs OrgChart dept head = own `department_id`, not headed departments (:104-107) vs TeamReviews `reviewer_id` vs ProbationEngine any manager.
5. **Director can never be scoped** (EmployeeEdit.php:248 drops scopes outside hr_admin/manager) and ApprovalGuard treats unscoped `manage_employees` as company-wide → Director is company-wide on every screen.
6. **Scope is union, not intersection, for heads:** `accessibleEmployeeIds` = reporting line ∪ explicit scope (ApprovalGuard.php:84-96). A user who is `departments.head_id` cannot be narrowed to one shift — Nikita's "UK Sales shift only" can't be expressed if she heads Sales.
7. **Scoped HR is ignored** by LeaveManagement rows (LeaveManagementService.php:69-85), EmployeeLeaveDetail, EmployeeIndex/Edit, AllReviews, WarningLetters, IncrementCenter, PerformanceCycles, OffboardingManager, Assets, Incentives/Reimbursements, FinanceEncashments.
8. **Enum vs permission checks are mixed:** `hrOverridePaymentStatus` (LeaveService.php:691), `approveEncashment` (:1042), `reviewRequest` isHrReviewer (:509), `ProbationEngine` (:80,:105) check `users.role` enum; route gates check DB permissions. A custom role with the right permissions is refused, and an enum that disagrees with role_id gets the enum behaviour.
9. **Permission granularity drift:** CommandCenter decides OT/WFH/holiday-work behind `role:approve-leave` only; AllTimeOff::submitNewRequest applies leave on behalf without `apply_leave_on_behalf` (EmployeeLeaveDetail requires it); `RolePermissionService` + `UserRole::can*()` are unused duplicates.

---

## RBAC matrix compliance (§4) — cells checkable from Livewire scope

| Module | Role | Spec | Status | Evidence |
|---|---|---|---|---|
| Employee Profiles | Super Admin | Full CRUD | PASS | manage-employees routes |
| Employee Profiles | HR Admin | Full CRUD | PASS | same |
| Employee Profiles | Director | View dept | CONFLICT | seeded manage/create/edit/delete_employee; company-wide; unscopable |
| Employee Profiles | Manager | View team | PARTIAL | EmployeeIndex direct reports only; no profile view page (profile route is manage-employees) |
| Employee Profiles | Finance | View basic | PASS | Directory basic + FinanceEmployeeProfile |
| Employee Profiles | Employee | View own | PARTIAL | own profile; Directory lists all staff (basic fields) |
| Attendance | SA / HR | Full | PASS | |
| Attendance | Director | View dept | CONFLICT | company-wide incl. mark attendance |
| Attendance | Manager | View + approve regularise (team) | PARTIAL | scoped approvals OK; M-1/M-2 leaks |
| Attendance | Finance | Summary only | MISSING | no approve_leave → no attendance screen (dashboards not audited) |
| Attendance | Employee | Own + regularise | PASS | AttendanceTracker |
| Leave | SA | Full + override | PASS | |
| Leave | HR | Full | PASS | |
| Leave | Director | Approve dept | CONFLICT | company-wide approve + HR-correction rights |
| Leave | Manager | Approve team | PASS | ApprovalGuard scope + self block |
| Leave | Finance | Summary | PARTIAL | only FinanceEncashments |
| Leave | Employee | Own only | PASS | MyTimeOff/MyLeaveBalances |
| OT | SA / HR | Full | PASS | |
| OT | Director | View dept | CONFLICT | approve_overtime, company-wide |
| OT | Manager | Approve team | PARTIAL | ManageOtRequests scoped; H-1 |
| OT | Finance | OT summary | MISSING | no OT screen for Finance (dashboards not audited) |
| OT | Employee | Submit own | PASS | MyOtRequests |
| Compensation | SA | Full | PASS | |
| Compensation | HR | Configure | PARTIAL | also runs payroll + can finance-approve via legacy path (H-5) |
| Compensation | Director | View dept cost | CONFLICT | edits any salary (H-3), increments (H-4), all payslip docs (M-7), company-wide |
| Compensation | Manager | No access | PASS | |
| Compensation | Finance | Full | PASS | |
| Compensation | Employee | Own payslips | PASS | MyPayslips |
| Performance | SA / HR | Full | PASS | |
| Performance | Director | Review dept | CONFLICT | AllReviews/cycles/warnings/increments company-wide |
| Performance | Manager | Review team | PASS | TeamReviews, PIPs, promotions scoped |
| Performance | Finance | No access | CONFLICT | seeded review_performance/manage_pip/manage_promotions |
| Performance | Employee | Own reviews | PASS | (L-5 drafts) |
| Onboarding/Exit | SA / HR | Full | PASS | |
| Onboarding/Exit | Director | View dept | CONFLICT | full manage + offboard any (M-6) |
| Onboarding/Exit | Manager | Team tasks | MISSING | checklists behind manage-employees |
| Onboarding/Exit | Finance | Exit settlement | MISSING | settlement set in OffboardingManager (HR/Director only) |
| Onboarding/Exit | Employee | Own checklist | PASS | MyOnboarding |
| Documents (HR) | SA / HR | Full | PASS | manage_documents |
| Documents (HR) | Director | Policies only | CONFLICT | EmployeeDocuments upload/delete via EmployeeEdit |
| Documents (HR) | Manager / Finance / Employee | Policies only | PASS | policy + visibility 'all' + own |
| Documents (Payslips) | SA / HR | Full | PASS | |
| Documents (Payslips) | Director | No access | CONFLICT | M-7 |
| Documents (Payslips) | Manager | No access | PASS | |
| Documents (Payslips) | Finance | Full | PASS | |
| Documents (Payslips) | Employee | Own only | PASS | |
| Reports (attendance, Livewire) | Manager | Team dash | PASS | AttendanceReports scoped (ExecutiveAttendance M-1) |
| Reports (attendance, Livewire) | Director | Dept dash | CONFLICT | company-wide |
| System Settings | Manager | None | CONFLICT | BiometricControl/BiometricSummary sync (M-2) |
| System Settings | HR | Partial | PASS (in scope) | TimeOff/Attendance settings behind manage-settings |
| Special: Shivani | HR read all depts | PASS | HR Admin is company-wide |
| Special: Emad | Finance payroll approval + attendance/leave summaries | PARTIAL | approval OK; no attendance/leave summary screens |
| Special: Nikita | Dept head scoped to UK Sales shift | PARTIAL | head_id ⇒ whole department; union semantics block narrowing |
