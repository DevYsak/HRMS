# Pulse v3.1 gap report — full findings (8 October 2026)

Companion to [gap-report-2026-10-08.md](gap-report-2026-10-08.md), which has the method, status meanings, the override register (R1–R22), decisions D1–D10 and the priority list.

Code: `staging` @ `10b6125`. Evidence is `path:line` at that commit. Risk: H / M / L. "Uncommitted" marks working-tree changes by other sessions that were not judged.

Every row that is not PASS is listed in full. PASS rows are listed compactly at the end of each group, with their evidence.

---

## EMP — Employee management, departments, onboarding, offboarding, documents (65 rows)

PASS 33 · PARTIAL 24 · MISSING 5 · BUG 2 · INTENTIONALLY CHANGED 1

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| EMP-04 | §3.5 | Cycle move takes effect at next cycle start | PARTIAL | app/Observers/EmployeeObserver.php:25-40 | Change applies immediately, no effective date; next run of either cycle uses the new cycle. Fix: pending cycle + effective date. See PAY-26. | M |
| EMP-07 | §3.1, §4.1 | Department head can be assigned | PARTIAL | app/Livewire/Settings/DepartmentManager.php:52-72 (HEAD) | Nothing in UI or seeders sets `head_id`, so dept-head scoping, DepartmentDashboard and org-chart head branch are dormant. Uncommitted: head selector + audit + `department_head` role. | M |
| EMP-09 | §3.1 | Lifecycle status validated and transitions enforced | PARTIAL | EmployeeCreate.php:231; EmployeeStatus.php:82-99; EmployeeLifecycleService.php:15-45 | Create validates only `required\|string` (tampered value → 500). `allowedTransitions()` is enforced only in a service no UI calls; Edit can jump any state to any state. Fix: `Rule::enum` + route status changes through the lifecycle service. | L |
| EMP-11 | §3.1 "never deleted" | No hard delete | INTENTIONALLY CHANGED | EmployeePolicy.php:79-95; EmployeeIndex.php:131-152; migration 2026_10_06_150527:46 | R12: force-delete is Super-Admin-only and only on an already-trashed record. Flag: a purge cascades payslips, exit records, onboarding tasks and equipment logs. | — |
| EMP-12 | §3.1, §3.8 #8 | Inactive status and archived_at stamped | PARTIAL | EmployeeLifecycleService.php:73-114; OffboardingManager.php:113-121 | `inactive` only if the last working day had already passed when HR clicked Process; no job flips it later; `archived_at` never written. Fix: daily lifecycle step. | M |
| EMP-15 | §4 Profiles | Director: view department only | BUG | RolesAndPermissionsSeeder.php:166-168 (HEAD); ApprovalGuard.php:35-43 | Director defaults include manage/create/edit/delete employee; unscoped = company-wide full CRUD, invite, soft-delete. Decision D1. | H |
| EMP-16 | §4 Profiles | Manager: view team | PARTIAL | EmployeeIndex.php:242-245; EmployeePolicy.php:24-33; routes/web.php:225 | List shows direct reports, but the profile page needs manage-employees. Fix: read-only team profile route using the policy. | L |
| EMP-19 | §3.8 on #1 | Create login + role (task) | PARTIAL | EmployeeCreate.php:328; OnboardingService.php:22-34 | Works, but no checklist task. | L |
| EMP-20 | §3.8 on #2 | Set shift, work mode, salary cycle (task) | PARTIAL | Employee.php missingHrFields(); OnboardingService.php:22-34 | Tracked only via the incomplete-profile queue. | L |
| EMP-21 | §3.8 on #3 | Company email account | PARTIAL | OnboardingService.php:23,27 | "Email & Slack setup" and "Complete personal profile" auto-complete on account creation — false completion. Fix: no auto trigger. | L |
| EMP-23 | §3.8 on #5 | Contract for signature, uploaded | MISSING | OnboardingService.php:22-34 | No task. Fix: HR task "Contract signed & uploaded". | L |
| EMP-24 | §3.8 on #6 | Policy docs marked for acknowledgement | PARTIAL | OnboardingService.php:29 | Generic induction task, not linked to acknowledgements. | L |
| EMP-25 | §3.8 on #7 | Add to department / reporting structure | PARTIAL | Employee.php missingHrFields() | No task. | L |
| EMP-26 | §3.8 on #8 | Buddy + Day-1 orientation (manager) | PARTIAL | OnboardingService.php:30-31; routes/web.php:227 | Manager tasks exist but managers cannot open the checklist; no buddy field. | M |
| EMP-29 | §3.8 on #10 | Probation review by manager + HR | PARTIAL | ProbationEngine.php:78-89; ProbationConfirmation.php:24-31; EmployeeEdit.php:601-613 | Route is manage-employees (managers can't do step 1); EmployeeEdit confirm skips the manager step and sets Active (engine sets Confirmed). | M |
| EMP-34 | §3.8 off #3 | Revoke access on last working day | PARTIAL | CheckActiveEmployee.php:28,36-47 (HEAD) | Lock-out works via exit records, but status check covers only `inactive`; resigned/terminated/absconded/archived users without an exit record keep access. Fix: block archived statuses (audit live data first — lock-out risk). | M |
| EMP-36 | §3.8 off #5 | Finance does F&F, fed into compensation | PARTIAL | OffboardingManager.php:102-107; SalaryCalculationService.php:201-215 | Only `run_payroll` holders write F&F, inside a page Finance cannot open; HR enters it for them. | M |
| EMP-39 | §3.8 off #8 | Archive: inactive, never deleted | PARTIAL | see EMP-12 | | M |
| EMP-41 | §3.8 table | exit_records incl. archived_at | PARTIAL | app/Models/ExitRecord.php:9-14 | No `archived_at`; `clearance_done` / `exit_interview_done` never written. | L |
| EMP-42 | §3.8 off | Owners complete their own exit tasks | MISSING | MyOnboarding.php:81-85; SendOnboardingReminders.php:78-93 | Checklist is HR-only; reminders go to managers/Finance who can't open the task. | M |
| EMP-44 | §4 Onboarding/Exit | Director: view department only | BUG | seeder:168; OffboardingManager.php:148-152 | Unscoped Director can offboard anyone (sets last working day → lock-out, releases biometric code), edit checklists, manage assets. Decision D1. | H |
| EMP-45 | §4 Onboarding/Exit | Manager: team tasks | MISSING | routes/web.php:227-228 | No manager task screen. | M |
| EMP-46 | §4 Onboarding/Exit | Finance: exit settlement | MISSING | see EMP-36 | | M |
| EMP-47 | §4 Onboarding/Exit | Employee: own checklist | PARTIAL | MyOnboarding.php:30,81-85 | Own offboarding tasks not shown. | L |
| EMP-50 | §3.9 | Versioning; prior versions kept | PARTIAL | DocumentUploadController.php:34-41; DocumentManager.php:122-132,155; document-manager.blade.php:144,150 | Library shows roots and links to the **oldest** version; acknowledgements attach to the root (new version can't be re-acknowledged); delete orphans versions. | M |
| EMP-52 | §3.9 | Acknowledgement tracking per audience | PARTIAL | DocumentManager.php:260-264 | Employee docs treated as all-staff; audience filtered to `status=active` (probation/notice never counted). | L |
| EMP-53 | §3.9, §2.2 | "Document to acknowledge" notification | MISSING | DocumentUploadController.php:60-62 | Company-wide docs needing acknowledgement notify nobody. | M |
| EMP-60 | §9 | Uploads outside public web root | PARTIAL | config/filesystems.php:32-38; MyTimeOff.php:345,437; AttendanceTracker.php:2304 | Documents/KYC private; photos, leave and regularisation attachments on the public disk. See SEC-08. | M |
| EMP-62 | R17 | KYC limited to `view_kyc_documents` | PARTIAL | EmployeeDocuments.php:95-111 | Employee Documents tab lists and deletes KYC for any manage_documents holder. | L |
| EMP-63 | §3.1 | "Current staff" includes probation/notice | PARTIAL | Directory.php:24; DocumentManager.php:179-181,262-263 | `status='active'` hides probation/confirmed/notice staff from the directory, doc picker and ack audience. | L |
| EMP-64 | §2.2 | Email only for critical events | PARTIAL | NewHireCheckInNotification.php:17-20; ProbationDueNotification.php:21-24; DocumentExpiryNotification.php:17-20 | Database + mail, gate fails open → emails by default. Decision D5. | L |
| EMP-65 | §4 | Lists respect a scoped HR/Director's reach | PARTIAL | EmployeeIndex.php:242-245; OnboardingManager.php:35-42 | Scoped users still see the whole company in these lists (record actions are guarded). | L |

PASS: EMP-01 profile fields (Employee.php:22; EmployeeCreate.php:223,251,287) · EMP-02 employment record (Employee.php:26-38; EmployeeEdit.php:231-235) · EMP-03 salary cycle per employee (SalaryCycleSeeder.php:13-14; EmployeeObserver.php:25-40) · EMP-05 org chart from manager_id (OrgChart.php:37-83) · EMP-06 departments table (migrations 2026_04_14_131032, 2026_04_23_000003) · EMP-08 lifecycle states (EmployeeStatus.php:8-25) · EMP-10 soft delete + restore (Employee.php:45; EmployeeIndex.php:105-173) · EMP-13 employees indexes · EMP-14 SA/HR full CRUD (EmployeePolicy.php:40-68) · EMP-17 Finance basic view (Directory.php:17-53) · EMP-18 own profile · EMP-22 equipment issue/log (AssetAssignmentService.php:33-59) · EMP-27 30-day check-in (CheckNewHireCheckIn.php:36-75) · EMP-28 probation due 10 days (CheckProbationDue.php:33-65) · EMP-30 onboarding_tasks · EMP-31 checklist authorised server-side · EMP-32 exit type + LWD · EMP-33 equipment return · EMP-35 revoke email/tools task · EMP-37 experience letter (DocumentController.php:13-30) · EMP-38 exit interview notes · EMP-40 equipment_log · EMP-43 SA/HR onboarding full · EMP-48 company docs visible to all · EMP-49 employee docs scoped (DocumentPolicy.php:38-77) · EMP-51 acknowledgement flag + guard · EMP-54 document expiry 30 days (CheckDocumentExpiry.php:27-46) · EMP-55 documents schema · EMP-56 controller enforces policy + signed URL · EMP-57 Director/Manager/Finance policies only · EMP-58 payslip docs access · EMP-59 upload MIME · EMP-61 safe file serving.

---

## ATT — Attendance, breaks, regularisation (44 rows)

PASS 26 · PARTIAL 8 · MISSING 1 · BUG 3 · INTENTIONALLY CHANGED 6

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| ATT-05 | §3.2 | Work mode chosen at clock-in | INTENTIONALLY CHANGED | AttendanceTracker.php:2033-2044; AttendanceService.php:74 | R20: 7 modes, WFH needs approved request. Separate defect: engine sync forces `work_mode='office'` on rows with device punches (EngineAttendanceSyncService.php:189). | L |
| ATT-10 | §3.2 | Open break closed at clock-out | PARTIAL | AttendanceService.php:81-97,112,140 | Clocking out mid-break leaves `break_end` NULL forever; break under-counted, excess can be hidden. Fix: `endBreak()` at start of `checkOut()`. | L |
| ATT-12 | §3.2 | Excess-break flag > 60 min | PARTIAL | CheckExcessBreaks.php:20-62; routes/console.php:71-75 | Single 20:00 run: UK shift (to 22:00) breaks after 20:00 never flagged; `endBreak`/rebuilder never set the flag column. Fix: evaluate in endBreak/checkOut or add a late run. | M |
| ATT-16 | §3.2 | Missing-checkout flag cleared when a real OUT arrives | BUG | EngineAttendanceSyncService.php:176-191 | Engine-sync upsert never clears `missing_checkout`; a late OUT picked up at 23:50 leaves the day flagged; wrong counts reach the monthly summary and reports. Fix: write the calculator's flag, or rebuild via AttendanceDayRebuilder. | L |
| ATT-18 | §3.2 | Shift values configurable | PARTIAL | AttendanceSettings.php:167-186 | `break_duration` not editable; `ot_threshold_hours` forced equal to `standard_hours`. | L |
| ATT-19 | §3.2 | attendance_logs incl. ot_hours | PARTIAL | attendances migrations | No `ot_hours` column; OT lives in overtime_records / calculator. Acceptable if reports derive it. | L |
| ATT-27 | §3.2 step 2 | Manager notified of request | INTENTIONALLY CHANGED | AttendanceTracker.php:2357-2376; NotificationRecipients.php:63-70 | R8: HR approvers notified; manager sees "Awaiting HR". | — |
| ATT-28 | §3.2 step 3 | Manager approves/rejects with comment | INTENTIONALLY CHANGED | AttendanceService.php:155-192; AllAttendance.php:486-640 | R8: HR decides in one step. Defect: TeamAttendance::approveRegularisation (:69-88) doesn't catch the service's DomainException. | L |
| ATT-29 | §3.2 step 4; R6 | Attendance correct after approval | BUG | AttendanceCalculator.php:172-181; AttendanceDayRebuilder.php:125-130; EngineAttendanceSyncService.php:161-176 | Corrected day frozen at the corrected in/out: later genuine punches (e.g. afternoon sessions, late OUT) are dropped from hours and OT. Fix written in this session, **uncommitted and untested**. | M (H if same-day corrections are common) |
| ATT-32 | §3.2 | Decided request is final | BUG | AttendanceService.php:524-554; AllAttendance.php:543,626-631 | HR `override` flips an APPROVED request to rejected while corrected attendance and OT stay applied. Fix: route through RegularisationManager::revert or remove. | L |
| ATT-33 | R8 | HR edit/delete/revert approved regularisations | INTENTIONALLY CHANGED | RegularisationManager.php:94-259 | R8 / d46d99f. Minor: revert rebuild ignores night shift; employee not notified of HR edit/delete. | L |
| ATT-34 | R8 | Mark-attendance fast path limited to HR | PARTIAL | AllAttendance.php:229-276; AttendanceService.php:209-237 | Gated on manage_employees + manage_attendance, which Directors hold → Directors apply corrections directly. Request created before checks and outside a transaction → orphan request on failure. Fix: require `canApproveRegularisations()`, check first, wrap in a transaction. | M |
| ATT-36 | §3.2 | Clock in via web | INTENTIONALLY CHANGED | PunchTimeline.php:18-80,302-323 | R7: biometric Face = IN / Card = OUT rules; web clock-in still exists. | — |
| ATT-39 | §4 Attendance | Director: view department | PARTIAL | seeder:166-169; ApprovalGuard.php:35-56 | Unscoped Director is company-wide and can fast-track corrections. Decision D1. | M |
| ATT-40 | §4 Attendance | Manager: view + approve regularise | INTENTIONALLY CHANGED | ApprovalGuard.php:16-31; AllAttendance.php:86,130 | R8: approval moved to HR; team-only view enforced. | — |
| ATT-41 | §4, §4.1 | Finance: attendance summary | MISSING | routes/web.php:285-301,510-512; seeder:187 | Finance reaches no attendance screen or CSV. Uncommitted: PermissionScopes grants Finance view/export. | M |
| ATT-43 | §2.4 | Enforced by gates/policies | PARTIAL | AttendancePolicy.php:10-26 (unused) | `view_attendance` / `approve_regularisation` keys seeded but never checked; screens gated on approve-leave. | L |
| ATT-44 | §2.2 | Attendance notifications in-app only | PARTIAL | MissingCheckoutNotification.php:23-26; ExcessBreakNotification.php:27-30; regularisation notifications | Database + mail, gate fails open. Decision D5. | L |

PASS: ATT-01 shifts IT/UK 9h grace 5 (ShiftSettingSeeder.php:14-31) · ATT-02 late from 10:36 / 13:06 at minute precision (ResolvedShift.php:39-48) · ATT-03 late flags 10:45 + 13:15, manager digest (CheckLateArrivals.php:57-99) · ATT-04 web clock in/out · ATT-06 IP soft audit · ATT-07 hours = out − in, breaks not deducted (AttendanceCalculator.php:48-65) · ATT-08 repeatable breaks · ATT-09 break segments with duration · ATT-11 break informational · ATT-13 excess-break notification · ATT-14 missing checkout at shift end + 1h (MissingCheckoutService.php:39-94) · ATT-15 missing-checkout notification · ATT-17 shift_settings columns · ATT-20 break_logs · ATT-21 regularisations table · ATT-22 FKs · ATT-23/24/25 indexes · ATT-26 request fields + validation (minor: half-day accepts future date; duplicate pending allowed) · ATT-30 original values in audit trail (AttendanceService.php:259-288) · ATT-31 employee notified of outcome · ATT-35 approved correction not overwritten by sync · ATT-37 SA full · ATT-38 HR full · ATT-42 employee own + regularise.

---

## LV — Leave CSL / MDL / Comp Off, holidays, encashment (57 rows)

PASS 35 · PARTIAL 16 · MISSING 1 · BUG 5 · INTENTIONALLY CHANGED 0

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| LV-04 | R4 | CSL grant schedule configurable, no auto-credit until HR chooses | PARTIAL | routes/console.php:230-237; ConexusCslAccrualService.php:21-44; LeaveRuleResolver.php:81-88 | Scheduled job auto-credits 1 day/month ("HR-confirmed" comment, b88d14d) but ignores the rule's `accrual_method`; resolver forces monthly for CSL. Decision D7. | L |
| LV-12 | §3.3 step 7 | PH/MDL/weekly-off rules also apply when a reviewer changes dates | BUG | LeaveService.php:488-531; TeamTimeOff.php:221-254; LeaveAdminActionService.php:103-120 | `reviewRequest()` re-checks only overlap and balance; leave can be moved onto a PH/MDL day and charged. Fix: reuse submit validations. | M |
| LV-13 | §3.3 MDL | Working MDL earns 1 CO | PARTIAL | AttendanceService.php:99,604-620; HolidayWorkService.php:42-45; AttendanceDayRebuilder.php:218 | Credited only on a web clock-out (any duration). Biometric days go through the rebuilder, which never credits MDL CO. | M |
| LV-14 | §3.3; R19 | Working a UK holiday earns CO | PARTIAL | AttendanceService.php:99,604-620; HolidayWorkService.php:212-217 | Web clock-out credits 1 CO even when the holiday-work request is paid as OT / double pay (paid twice); comp-off pay type swallowed by the idempotency key; ignores holiday scope. Fix: all holiday CO via HolidayWorkService. | M |
| LV-17 | §3.3 table | No half-day Comp Off | PARTIAL | LeaveTypeSeeder.php:184; ConexusLeavePolicyService.php:383-395 | CO `allow_half_day` = true. Fix or record as deliberate (R19 posts 0.5 credits). | L |
| LV-20 | §3.3 | Leave regularisation doesn't charge PH/MDL | BUG | LeaveRegularisationService.php:63,208-257,374-385 | Day count skips weekly offs only; a range over a bank holiday/MDL debits CSL. Fix: WorkingDayResolver::classify. | M |
| LV-22 | §3.3 step 6 | Optional reason | PARTIAL | MyTimeOff.php:186-196 | Reason required (min 5). Stricter than spec. | L |
| LV-23 | §3.3 step 6 | Half-day is one half of one day | BUG | LeaveService.php:211-219,291,502 | Mon–Fri request marked half-day is charged 0.5 days total. Fix: reject half-day where start ≠ end. | M |
| LV-27 | §3.3 step 9 | Manager approves/rejects with comment | PARTIAL | LeaveService.php:513-531; ApprovalCenter.php:85 | Manager approval only → `pending_hr`; HR approves again (not in spec/register). Approval Center drops the comment; HR decision overwrites the manager's comment. Decision D2. | M |
| LV-28 | §3.3 step 10 | 24-h escalation to HR | PARTIAL | EscalateLeaveRequests.php:19-47; LeaveRequestNotification.php:39-47 | Reuses the "New Leave Request" payload — indistinguishable from the submission notice. Fix: distinct escalation text. | L |
| LV-29 | §3.3 step 11 | Employee told the correct outcome | BUG | LeaveService.php:539-545; LeaveRequestNotification.php:39-83 | No `pending_hr` case → default branch says **"Leave Rejected"** to the employee and every HR queue member on each manager/Director approval. Fix: `pending_hr` branch. | **H** |
| LV-31 | §2.2 | Leave/encashment notices in-app only | PARTIAL | LeaveRequestNotification.php:20-23; LeaveEncashmentNotification.php:17-20 | Database + mail by default. Decision D5. | M |
| LV-32 | §3.3 step 12 | Encashment request: days + reason | PARTIAL | LeaveService.php:990-1042; LeaveEncashment.php:9-14 | No reason field; one encashment per **calendar** year (not leave year, and approved ones count). | L |
| LV-33 | §3.3 step 12 | Encash from CSL card without breaking leave apply | BUG | MyTimeOff.php:186-205,481-493; my-time-off.blade.php:1481 | Encash button sets a flag but never opens the modal; leave submission then fails validation on encashment fields. Fix uncommitted in the working tree. | M |
| LV-36 | §3.3 step 15 | Director or HR approves encashment | PARTIAL | LeaveService.php:1048-1174 | Director/HR approval → `pending_finance`; Finance approves finally. Director approval not department-scoped. Decision D3. | M |
| LV-37 | §3.3 step 16 | CSL reduced on approval | PARTIAL | LeaveService.php:1150-1169 | Debited only at Finance approval (correct once D3 is settled). | L |
| LV-38 | §3.3 step 16 | Amount into current month's payroll, once | PARTIAL | SalaryCalculationService.php:219-246; LeaveService.php:1142 | Exact `payout_month` match: approved after that month's run → CSL debited but never paid. Amount not stored. Decision D8. | M |
| LV-43 | §6 | leave_encashment_requests columns | PARTIAL | migrations 2026_04_16_095635, 2026_10_04_202659 | No `reason`, no `encashment_amount`. | L |
| LV-45 | §3.3 | `december_mandatory_days.is_comp_off_eligible` | MISSING | migration 2026_04_21_115844 | All MDL dates treated as CO-eligible. | L |
| LV-51 | R4/R5 | HR leave settings describe CSL correctly | PARTIAL | time-off-settings.blade.php:74-79,100 | Shows "Policy / working pattern — 0 weeks" for CSL; says carry forward is "applied only on HR action" though `leave:rollover --apply` runs unattended on 1 July. | L |
| LV-55 | §4 Leave | Director approves own department | PARTIAL | seeder:166-170; ApprovalGuard | Unscoped Director decides company-wide. Decision D1. | L |
| LV-56 | §4.1 | Finance sees leave summaries | PARTIAL | seeder:187-190; routes/web.php:545-550 | Leave reports need approve-leave, which Finance lacks. | L |

PASS: LV-01 leave year Jul–Jun (LeaveYearResolver.php:25-27) · LV-02 financial-year scoping (leave_year_id; label "2026/27") · LV-03 CSL 12/yr (ConexusLeavePolicyService.php:62) · LV-05 half-day · LV-06 no lapse, unlimited carry · LV-07 combined pool, legacy CL/SL/AL retired · LV-08 encashable · LV-09 6 MDL dates pre-blocked (production dates UNVERIFIED; seeder 26–31 Dec includes a weekend in 2026) · LV-10 MDL never a balance · LV-11 no leave on MDL at submit · LV-15 CO no expiry · LV-16 CO not encashable · LV-18 UK calendar resolution · LV-19 leave blocked on holidays · LV-21 auto-flag skips PH/MDL · LV-24 balance check · LV-25 no overlap · LV-26 manager notified · LV-30 balance updated on approval (ledger) · LV-34 encashment balance check · LV-35 encashment notice to Director + HR · LV-39 encashment outcome notice · LV-40/41/42/44 schema · LV-46/47 indexes · LV-48 escalation job hourly · LV-49 dashboard CSL / MDL / CO · LV-50 no 28-day AL shown (R4) · LV-52 register truth, negative balances · LV-53 SA/HR · LV-54 manager approves team · LV-57 employee own leave.

---

## PAY — OT, payroll, salary cycles, incentives, reimbursements, payslips (80 rows)

PASS 38 · PARTIAL 26 · MISSING 4 · BUG 10 · INTENTIONALLY CHANGED 2

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| PAY-01 | §3.4 | OT only if pre-approved | PARTIAL | MyOtRequests.php:34; OvertimeService.php:135-157 | `before_or_equal:today` blocks requests for future dates and allows any past date, so approval is usually after the fact. Fix: future dates allowed; past only via HR. | M |
| PAY-02 | §3.4 | OT from an approved regularisation | INTENTIONALLY CHANGED | AttendanceService.php:468-470; OvertimeService.php:83-130 | R9: filed and auto-approved under the HR reviewer. Docblock OvertimeService.php:77-82 is stale. | — |
| PAY-03 | §1.2; §3.4 | No PM integration; OT approved in Pulse | BUG | OvertimeService.php:299-417,469-502; SyncNexflowOvertimeHours.php:148-152 | Nexflow-approved OT becomes payable with `reviewer_id` null for `ot_tracking_source=nexflow`. Looks deliberate (f455144, 74f772b) but not registered. Decision D4. | M |
| PAY-04 | §3.4 #18 | Submit date, hours, reason | PARTIAL | OvertimeService.php:43-74; OtWindows.php:34-37 | Refused unless HR/Director opened an OT window. Decision D6. | L |
| PAY-08 | §3.4 #22 | OT = worked − 9h; clock-out is truth | PARTIAL | OvertimeService.php:181-251 | Approved OT for a date with **no attendance** pays the requested estimate (:214). Fix: 0 for past dates with no attendance (keep estimate for Nexflow only). | M |
| PAY-12 | §3.4 #23 | Monthly OT summary for Finance | PARTIAL | FinanceDashboard.php:63-70; finance-dashboard.blade.php:28-29 | Computed but hidden (`$showPayroll = false`); OT CSVs need approve-ot. | M |
| PAY-13 | §3.4 #24 | Finance verifies OT before payslip | MISSING | overtime_records migration; SalaryCalculationService.php:156-161 | No verify step / `verified_by`; all approved OT auto-included. | M |
| PAY-14 | §3.4/§3.5 | Approved OT reaches a payslip | BUG | SalaryCalculationService.php:159 | Only OT dated inside the cycle; OT approved/settled after that run is never paid. Fix: unpaid approved OT dated ≤ cycle end. Decision D8. | M |
| PAY-15 | §3.5 | Approved OT, incentives, claims are paid | BUG | EmployeeCreate.php:120-126,307-325; SalaryCalculationService.php:155,178,191 | Create form defaults `ot_eligible`, `incentive_eligible`, `reimbursement_eligible` to **false**; no screen can change them; approved items silently excluded. Fix: default true, editable on Pay tab, warn on approval. | M |
| PAY-17 | §6 | overtime_records columns | PARTIAL | overtime_records migration | No month/year, no `verified_by`, no unique `ot_request_id`. | L |
| PAY-18 | §6.1 | ot_requests composite index | PARTIAL | migrations 2026_04_16_064905:27; 2026_05_05_060107:65 | Two 2-column indexes instead of one 3-column. | L |
| PAY-21 | §3.5 | Cycle A pays on the 1st | PARTIAL | SalaryCycleSeeder.php:13 | `pay_day` seeded 5 (display only). Data fix. | L |
| PAY-23 | §3.5 | Cycle B pays on the 21st | PARTIAL | SalaryCycleSeeder.php:14 | Seeded 25. Data fix. | L |
| PAY-26 | §3.5 | Cycle move effective next cycle start | MISSING | EmployeeObserver.php:25-40; PayrollService.php:103-110 | Immediate; two payslips for one month possible; A→B pays 21st–month end twice. Fix: effective-from date or overlap guard. | **H** |
| PAY-28 | §3.5 #25 | HR locks attendance + leave for the period | PARTIAL | AttendanceDayRebuilder.php:37,307-330; PayrollService.php:468-485 | Only the biometric rebuild and absence review respect submitted runs; regularisation, leave, OT approvals are not blocked. | M |
| PAY-31 | §3.5 #28 | Finance reviews OT / incentives / reimbursements | PARTIAL | finance-approval.blade.php:76-121; PayrollService.php:141,598-607 | Approval page shows totals only; header OT/incentive totals not recomputed after single-payslip edit/regenerate/delete. | M |
| PAY-33 | §3.5 #29 | Finance sign-off can't be bypassed | BUG | ApprovalPolicySettings.php:61-86; PayrollApprovalStep.php:44-55; PayrollService.php:312-334 | HR (manage_settings) can build a chain of HR/Director steps only; last step finalizes with no Finance. Fix: require an active Finance step. | **H** |
| PAY-34 | §3.5 | No silent changes after submission | BUG | PayrollService.php:665-680; Process.php:410-436 | Payslips stay draft while run is pending_finance; run-payroll users edit lines after submission/approval steps. Fix: lock or reset steps. | M |
| PAY-36 | §3.5 #30 | Only approved payslips emailed | BUG | PayrollService.php:817-830; Process.php:368-381,463-492 | Admin single/bulk email has no status check. | L |
| PAY-37 | §3.5 #31 | Archive + salary history | PARTIAL | EmployeeEdit.php:570-594 | Salary rows edited/deleted in place, no effective dating. | M |
| PAY-38 | §3.5 | Gross = basic + HRA + special | PARTIAL | SalaryCalculationService.php:37-96,357-360; EmployeePayrollSettings.php:72 | HRA forced to 0 unless `hra_enabled`, and **no screen sets it**. Fix: toggle on Create/Edit; backfill is HR's call. | **H** |
| PAY-40 | §3.5 | Statutory deductions | INTENTIONALLY CHANGED | SalaryCalculationService.php:104-116,295-333; StatutoryService.php | R10: PF/ESI/PT/TDS when both flags on; no filing. | — |
| PAY-42 | §3.5 | Approved incentives for the month | PARTIAL | IncentiveService.php:83-107 | Exact month match; approved after the run → never paid. Decision D8. | M |
| PAY-43 | §3.5 | Approved reimbursements for the period | BUG | ReimbursementService.php:84-108; ExpenseClaimService.php:66 | Month = expense month; approved after that run → never paid. Decision D8 (arrears rule). | **H** |
| PAY-44 | §3.5 | Approved encashment | PARTIAL | SalaryCalculationService.php:219-247 | See LV-38. | M |
| PAY-47 | §3.5 | Incentive types | MISSING | incentives migration; Incentives.php:50-69 | Free-text title; no type. | M |
| PAY-48 | §3.5 | Incentives created by HR or Director | PARTIAL | routes/web.php:350-357 | Page needs run_payroll; Director can't create. | M |
| PAY-49 | §3.5 | Director approval before inclusion | BUG | Incentives.php:72-100; IncentiveService.php:29-81 | Any HR/Finance user (not requester/employee) approves; Director can't reach the page. | **H** |
| PAY-50 | §6 | incentives columns | PARTIAL | incentives migration | No type; month+year combined. | L |
| PAY-52 | §3.5 | Claim categories travel / internet / meal / medical / other | PARTIAL | Expenses.php:109; expenses.blade.php:240-246 | Free string; UI offers a different list (no internet, medical, other). | M |
| PAY-53 | §3.5 | Manager approves claim | PARTIAL | Expenses.php:135-207; ExpenseClaimService.php:45-85; Reimbursements.php:64-110 | Reviewer has no link to open the receipt; HR/Finance can create and approve directly (same user may do both). | M |
| PAY-54 | §3.5 | Paid in payslip or separately | PARTIAL | ReimbursementService.php:84-108 | Payslip only; claim never moves to Paid. | L |
| PAY-55 | §6 | reimbursements columns | PARTIAL | reimbursements migration | Year inside month; category free text. | L |
| PAY-57 | §3.5 | Employee sees only Finance-approved payslips | BUG | MyPayslips.php:251,270; PayslipController.php:150-172 | `status in [paid, draft]`: employees see, download, email, QR-verify draft figures; YTD includes drafts. | M |
| PAY-58 | §9 | Payslip downloads via 5-minute signed URLs | MISSING | routes/web.php:343-344; my-payslips.blade.php:491,497,648 | Plain auth route (owner check present). See SEC-09. | M |
| PAY-60 | §6 | payslips columns | PARTIAL | payslips migration; PayslipController.php:160 | No `pdf_path` (dead branch), no `emailed_at`. | L |
| PAY-63 | §6 | salary_structures effective_from/to | PARTIAL | EmployeeSalary.php:28-41 | See PAY-37. | L |
| PAY-69 | §4 OT | Director: view department | PARTIAL | seeder:169; ApprovalGuard.php:51-90 | `approve_overtime`, company-wide unless scoped. Decision D1. | M |
| PAY-71 | §4 OT | Finance: OT summary | PARTIAL | see PAY-12 | | M |
| PAY-74 | §4 Comp | HR Admin: configure | PARTIAL | routes/web.php:350-357; Incentives.php:72; ApprovalPolicySettings.php:61 | Can approve incentives (spec: Director) and build a Finance-less chain. | M |
| PAY-75 | §4 Comp | Director: view dept cost only | BUG | seeder:171; routes/web.php:369-384 | Holds approve_finance + approve_payroll: finalizes any run, sees every salary. Decision D1; production needs a role_permission change (seeder never removes). | M |
| PAY-80 | §4.1 | Finance sees attendance + leave summaries | PARTIAL | seeder:187-196; routes/web.php:509-511 | No attendance permission; summary CSV needs approve-leave. | M |

PASS: PAY-05 manager notified of OT · PAY-06 manager decides with comment (scope / not-self / pending enforced) · PAY-07 employee notified · PAY-09 9h threshold from shift · PAY-10 ₹100/hr (per-employee rate collected but unused) · PAY-11 overtime_records only from approved request (OvertimeService.php:504-516) · PAY-16 ot_requests columns · PAY-19 overtime_records index · PAY-20 Cycle A 1st–last · PAY-22 Cycle B 21st–20th, unknown cycle throws · PAY-24 per-employee cycle decides the run · PAY-25 separate run per cycle · PAY-27 status flow draft → pending_finance → finalized · PAY-29 drafts for everyone in cycle · PAY-30 Finance notified · PAY-32 Finance approves (legacy path, maker-checker) · PAY-35 dompdf PDF emailed on approval · PAY-39 agreed deductions + LWP · PAY-41 OT pay exactly once · PAY-45 net formula · PAY-46 re-run keeps inclusions · PAY-51 claim with receipt on private disk · PAY-56 employee payslip history with PDF · PAY-59 payslip scoping (no IDOR) · PAY-61 payslips index · PAY-62 payroll_runs columns · PAY-64 dompdf · PAY-65/66/67 payslip, incentive, reimbursement notifications · PAY-68 SA/HR OT · PAY-70 manager approves team OT · PAY-72 employee submits own · PAY-73 SA comp · PAY-76 manager no comp access · PAY-77 Finance full · PAY-78 employee own payslips · PAY-79 payslip docs access.

---

## NTF — Notifications, scheduled jobs, reports (58 rows)

PASS 39 · PARTIAL 16 · MISSING 0 · BUG 0 · INTENTIONALLY CHANGED 3

App timezone is Asia/Kolkata (config/app.php:68); every §7 job runs at its IST time per `schedule:list`. Queue default is `database`.

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| NTF-07 | §3.6 | `data` JSON has related_model / related_id | PARTIAL | e.g. LateArrivalNotification.php:28-40 | Payload is title/body/url/type; no related_model/related_id anywhere. | L |
| NTF-10 | §2.2 | Email only for 4 critical events | INTENTIONALLY CHANGED | NotificationCatalog.php:299-309; NotificationDeliveryGate.php:47-61 | R14: per-event mail control, fail-open. Flag: default is mail **on** for nearly every event. Decision D5. | — |
| NTF-14 | §2.2, §3.8 | Final settlement emailed on offboarding | PARTIAL | OffboardingManager.php:103-106,127 | No settlement email; a generic details notice on every save. Fix: one notice when settlement completes. | M |
| NTF-15 | §1.2 | Bulk email excluded | INTENTIONALLY CHANGED | NotificationSettings.php:159-300; AppServiceProvider.php:283,387 | R14: kill switch + Compose & Send broadcast. Flag: reverses a §1.2 exclusion. | — |
| NTF-16 | §8.1 | Database queue, async dispatch | PARTIAL | config/queue.php:16 | Only a few classes are `ShouldQueue`; scheduler and payroll notifications mail inline (SMTP 421 risk). Production worker UNVERIFIED. | L/M |
| NTF-22 | §3.6 | Regularisation submitted → manager | INTENTIONALLY CHANGED | AttendanceTracker.php:2351-2372; NotificationRecipients.php:63-70 | R8: HR approvers. | — |
| NTF-28 | §3.6, §3.5 | Payroll ready → Finance | PARTIAL | PayrollService.php:226-240,256,400-410 | Recipients correct, but sent synchronously inside `DB::transaction` — an SMTP failure rolls back the submission. Fix: afterCommit or ShouldQueue. | M |
| NTF-30 | §3.6 | Review due 7 days before quarter end → employee + manager | PARTIAL | CheckReviewCycleReminders.php:52-79; RemindReviewParticipants.php:17-28 | Employees only; keyed to self-review deadline; no sent marker → repeats daily for ~8 days (db + mail). | M |
| NTF-31 | §3.6, §3.9 | Document requires acknowledgement → employee | PARTIAL | DocumentUploadController.php:60-62 | Company-wide docs notify nobody. See EMP-53. | M |
| NTF-33 | §3.6, §7 | Probation due 10 days → manager + HR | PARTIAL | CheckProbationDue.php:38-69 | Only `status=probation`; new hires default to `onboarding` and nothing moves them, so they're missed. | L |
| NTF-40 | §7 | Excess breaks at 20:00 | PARTIAL | routes/console.php:72-75; CheckExcessBreaks.php:22-62 | UK-shift breaks after 20:00 never evaluated; inline mail with no per-row try/catch. See ATT-12. | M |
| NTF-44 | §7 | Probation due daily 08:00 | PARTIAL | CheckProbationExpiry.php:22-39 | Overdue digest re-sent to HR daily with no marker. | L |
| NTF-46 | §7 | Review reminders Monday 09:00 | PARTIAL | routes/console.php:116-119,281-284 | See NTF-30. | M |
| NTF-48 | §7 | Monthly attendance summary per employee (1st, 01:00) | PARTIAL | GenerateAttendanceSummary.php:37-79 | Persisted but no screen or report reads it; exited staff still get rows. | L |
| NTF-49 | §7 | Job robustness | PARTIAL | several commands | No per-row isolation in CheckExcessBreaks, EscalateLeaveRequests, CheckDocumentExpiry, CheckProbationDue/Expiry, CheckReviewCycleReminders; default 24-h overlap mutex can silence hourly jobs for a day after a killed run. | M |
| NTF-52 | §4, §4.1 | Director reports scoped to department | PARTIAL | ApprovalGuard.php:36-58; routes/web.php:381 | Company-wide unless scope set. Decision D1. | M |
| NTF-54 | §4.1 | Finance: attendance/leave summaries | PARTIAL | routes/web.php:494-568 | Payroll reports only. | L |
| NTF-56 | §8 | Reports export PDF / Excel | PARTIAL | ReportController.php:82-112; DataExportService.php:32-55 | PDF + CSV; xlsx only in the Import/Export centre (5 datasets). | L |
| NTF-58 | §4 | Reports permission governs reports | PARTIAL | User.php:254-257 | `view_reports` / `export_reports` seeded but gate nothing. | L |

PASS: NTF-01 database channel everywhere · NTF-02 bell + badge · NTF-03 30-s polling · NTF-04 dropdown with timestamps · NTF-05 mark all read · NTF-06 read_at on open · NTF-08 badge index · NTF-09 90-day pruning (read and unread) · NTF-11 payslip email with PDF · NTF-12 account-created email (second welcome email also sent, L) · NTF-13 password reset email · NTF-17 leave submitted → manager · NTF-18 leave outcome → employee · NTF-19 leave escalation → HR (once) · NTF-20/21 OT submitted / outcome · NTF-23 regularisation outcome → employee · NTF-24/25 encashment submitted / outcome · NTF-26 excess break → employee + manager · NTF-27 missing clock-out → employee · NTF-29 payslip issued · NTF-32 document expiring → HR · NTF-34 30-day check-in → manager · NTF-35 incentive approved · NTF-36 reimbursement outcome · NTF-37 scheduler in IST (crontab UNVERIFIED) · NTF-38 missing checkouts 21:00 (+ 10-min sweep, 23:05) · NTF-39 late arrivals 10:45 + 13:15 · NTF-41 leave escalations hourly · NTF-42 OT escalations hourly · NTF-43 document expiry 08:00 · NTF-45 new-hire check-in 08:00 · NTF-47 prune Sunday · NTF-50 SA reports all · NTF-51 HR reports · NTF-53 manager team reports · NTF-55 employee own summary · NTF-57 audit log viewer.

Extra jobs flagged for review (not in spec, not in the register): `hrms:sync-nexflow-ot` auto-approves OT (D4); `hrms:issue-late-warnings` auto-issues disciplinary letters (D10); `leave:purge-attachments` deletes leave evidence after 30 days (D10). `hrms:sync-biometric` window ends 22:00; UK checkouts after that rely on the engine sync until 23:00 plus the 23:50 catch-up (UNVERIFIED).

---

## PRF / DSH — Performance / KPI and dashboards (66 rows)

PASS 18 · PARTIAL 27 · MISSING 13 · BUG 6 · INTENTIONALLY CHANGED 2

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| DSH-01 | §5.1 | Super Admin gets the Executive dashboard | PARTIAL | Dashboard.php:58-59,94-371 | SA lands on `/` overview; `/dashboard/executive` is separate; neither covers all of §5.1. | L |
| DSH-02 | §5.1 | Headcount total active | PARTIAL | Dashboard.php:100; ExecutiveDashboard.php:40 | `status=active` only (probation/onboarding excluded). | L |
| DSH-04 | §5.1 | Headcount by shift and work mode | MISSING | — | | M |
| DSH-05 | §5.1 | % clocked in today | PARTIAL | Dashboard.php:108-109 | Numerator any check-in, denominator active only — can exceed 100%. | L |
| DSH-06 | §5.1 | Today WFH vs office | MISSING | — | | M |
| DSH-07 | §5.1 | Absences without leave | PARTIAL | Dashboard.php:138-175; LeaveService.php:1243-1254 | No count; auto-flagged absences stored as approved leave read as "on leave". | L |
| DSH-09 | §5.1 | On leave today | PARTIAL | ExecutiveDashboard.php:77-80 | Computed but not rendered on Executive; counts unauthorised absences. | L |
| DSH-10 | §5.1 | Payroll cycle progress, pending Finance | PARTIAL | Dashboard.php:128-130; ExecutiveDashboard.php:58-66 | Draft count only; `pending_finance` never shown. | M |
| DSH-11 | §5.1 | QBR review completion % | MISSING | — | | M |
| DSH-12 | §5.1 | Average rating by department | PARTIAL | ExecutiveDashboard.php:69-71,134-144 | Reads `overall_rating`, which nothing writes; department bar is attendance "health". Fix: scorecard final_score by department. | M |
| DSH-13 | §5.1 | Alerts: expiring docs, overdue onboarding, probation due | PARTIAL | Dashboard.php:195-282 | No overdue-onboarding alert on `/`; Executive folds three into one number. | L |
| DSH-14 | §5.1, §1.1 | Real metrics; Jul–Jun year | BUG | executive-dashboard.blade.php:31-43,87-88; dashboard.blade.php:30-44,134 | "Satisfaction" (formula seeded at 82), "Company Health", readiness shown as "Performance", synthetic sparklines; FY progress uses Apr–Mar. | M |
| DSH-15 | §5.2, R16 | Director sees own department only | PARTIAL | Dashboard.php:75-92; ExecutiveDashboard.php:28-31 | Unscoped Director lands on company-wide Executive page (payroll cost, audit feed). Decision D1. | H |
| DSH-17 | §5.2 | Team daily clock-in status | PARTIAL | ManagerDashboard.php:139-174 | Per-person status canonical; headline absent count includes approved leave. | L |
| DSH-18 | §5.2 | Monthly team leave calendar | PARTIAL | manager-dashboard.blade.php:96-110 | "This week" list + link only. | L |
| DSH-19 | §5.2 | Pending approvals with age indicator | PARTIAL | ManagerDashboard.php:177-190 | No age indicator; mojibake "Â·" (fixed in uncommitted copy). | L |
| DSH-21 | §5.2 | Team KPI completion, outstanding reviews | PARTIAL | ManagerDashboard.php:216-236 | Counts legacy statuses; misses submitted/manager_reviewed/hr_reviewed. | M |
| DSH-22 | §5.2 | `/dashboard/department` | PARTIAL | DepartmentDashboard.php:24-49 | Legacy: counts inactive staff, "present" = rows without check-in, no route gate. Uncommitted rewrite. | L |
| DSH-24 | §5.3 | Finance dashboard shows content | MISSING | finance-dashboard.blade.php:28 | Hard-coded `$showPayroll = false` hides every widget (not the R18 module switch). | M |
| DSH-25 | §5.3 | Cycle A/B approval queue | MISSING | FinanceDashboard.php:41-51 | Hidden; first payroll by created_at; no pending_finance queue. | M |
| DSH-26 | §5.3 | Monthly summary by department + total | MISSING | finance-dashboard.blade.php:126-161 | Hidden; per employee only. | M |
| DSH-27 | §5.3 | Approved OT hours by employee | MISSING | FinanceDashboard.php:60-73 | Hidden; amount computed locally as requested_hours × 100. | M |
| DSH-28 | §5.3 | Incentives pending/approved | MISSING | FinanceDashboard.php:53,57 | Hidden. | L |
| DSH-29 | §5.3 | Reimbursements awaiting payment | MISSING | FinanceDashboard.php:54,58 | Hidden; no awaiting-payment state. | L |
| DSH-30 | §5.3 | Approved encashments for payslip | MISSING | FinanceDashboard.php:55 | Counts pending, hidden. | M |
| DSH-31 | §5.4 | Clock widget with today's hours + break time | PARTIAL | EmployeeDashboardService.php:313-325 | Total break minutes not shown. | L |
| DSH-32 | §5.4 | Work mode toggle | INTENTIONALLY CHANGED | status-card.blade.php:104-117; Dashboard.php:409-427 | R20: toggle only with approved WFH. | — |
| DSH-34 | §5.4 | This month: excess-break flags | MISSING | — | | L |
| DSH-35 | §5.4 | This month: leave taken | PARTIAL | EmployeeDashboardService.php:133-145,438-448 | Auto-flagged unauthorised absences count as leave. | M |
| DSH-37 | §5.4 | OT pending/approved + submit | PARTIAL | EmployeeDashboardService.php:179-188 | Approved hours from requests, not OvertimeRecord; pending OT folded into a generic count. | L |
| DSH-38 | §5.4 | Payslips last 12 months with PDF | PARTIAL | EmployeeDashboardService.php:193-199,562-577 | Latest only + link; includes draft payslips. See PAY-57. | M |
| DSH-41 | §5.4, §3.7 | Self-assessment-due alert | BUG | EmployeeDashboardService.php:595-599 | Queries `pending`; live reviews are `draft` → never fires. | L |
| PRF-01 | §3.7 | Quarters Q1 Jul–Sep … Q4 Apr–Jun | PARTIAL | PerformanceService.php:10-29; KpiTemplates.php:340-392 | Window checked only when the cycle name contains Q1–Q4; template launch never checks. | L |
| PRF-02 | §3.7, §8.1 | financial_year 'YYYY-YY' on performance tables | MISSING | performance_cycles migration | Dates only, no FY/quarter. | L |
| PRF-03 | §3.7 | KPIs per department per quarter by Directors/HR | PARTIAL | KpiTemplates.php:89-399; seeder:172 | Directors hold `manage_kpi_templates` but get 403 (gate is canManageSettings); no department limit. Uncommitted gate fix. | M |
| PRF-07 | §3.7 | Self-assessment before manager review | BUG | ParticipantService.php:128-165; ReviewTasks.php:81-89 | Order not enforced; a lead can submit first, then My Review refuses the self review. | M |
| PRF-09 | §3.7 | Manager feedback + promotion flag | PARTIAL | TeamReviews.php:19-110 | Only TeamReviews captures them and it lists by `reviewer_id`, which live cycles never set → empty. | M |
| PRF-10 | §3.7 | Final rating composite from KPI weights | PARTIAL | KpiScoringEngine.php:86-152 | No max_score normalisation; unscored manual KPI = 0; self score weighted ~20% (D9). | M |
| PRF-11 | §3.7, R6 | Auto KPIs use canonical attendance | BUG | KpiScoringEngine.php:156-220 | Attendance % = rows with check-in ÷ existing rows (absent days have no row → ≈100%); shift compliance hard-coded 100. Fix: WorkingDayResolver denominator. | M |
| PRF-12 | §3.7 | Cycle windows and closure respected | PARTIAL | ReviewWorkflowService.php:228; PerformanceCycle.php:81-91 | Checks legacy ReviewCycle status only; deadlines never enforced. | M |
| PRF-14 | §3.7 | Full history visible to manager | PARTIAL | TeamReviews.php:108-110; EmployeeScorecard.php:30-32 | No team history list. | M |
| PRF-15 | §3.7, §6 | goals (quarter, FY, completion_note) | PARTIAL | review_goals migrations; Goals.php:59-64 | No quarter/FY/completion_note; goals never linked to the review → KPI dashboard goal progress always 0. | L |
| PRF-16 | §3.7, §6 | kpis / performance_reviews / kpi_scores | INTENTIONALLY CHANGED | migrations 2026_06_05_*, 2026_07_11_163503 | R21. | — |
| PRF-17 | §6.1 | performance_reviews index | PARTIAL | 2026_05_05_060107:109-110 | No composite/unique on (employee, cycle, type). | L |
| PRF-18 | §3.6, §7 | Review-due reminder | PARTIAL | CheckReviewCycleReminders.php:18-45 | See NTF-30. | L |
| PRF-19 | §2.2 | "Review submitted" notification | MISSING | ParticipantService.php:128-165 | None on self/manager submit. | L |
| PRF-21 | §4, R16 | Director reviews own department only | BUG | AllReviews.php:67-137; IncrementCenter.php:39-215 | Unscoped Director company-wide; **IncrementCenter ignores reach for any Director** — sees company salary-raise proposals and can apply the cycle. | **H** |
| PRF-23 | §4 | Finance: no performance access | BUG | seeder:192; routes/web.php:425,443,449 | Finance holds review/PIP/promotion permissions (data effectively empty). Fix: drop the keys. | L |

PASS: DSH-03 headcount by department · DSH-08 pending leave company-wide · DSH-16 Nikita scope via scope_shifts (production value UNVERIFIED) · DSH-20 team OT hours/amount · DSH-23 manager team dashboard · DSH-33 this-month attendance + late flags · DSH-36 CSL / MDL / CO balances (canonical calculator) · DSH-39 notifications · DSH-40 colleagues on leave this week · DSH-42 no other employees' data · PRF-04 KPI targeting · PRF-05 weights total 100% · PRF-06 self-assessment rating + comment · PRF-08 manager rates each KPI · PRF-13 history visible to employee · PRF-20 SA full · PRF-22 manager reviews team · PRF-24 employee own reviews.

---

## RBAC / SET / AUD / HLP / SEC — RBAC, settings, audit logs, help & guide, security (121 rows)

PASS 81 · PARTIAL 25 · MISSING 4 · BUG 4 · INTENTIONALLY CHANGED 7

The flattened §4 matrix was rebuilt as 12 modules × 6 roles (each column yields exactly 12 cells). One cell is ambiguous: HR × System Settings ("Partial" or "None"); R12 overrides it either way. Findings assume seeded role defaults (`RolesAndPermissionsSeeder.php:148-218`) plus the 2026_10_06 migrations.

| ID | Spec | Requirement | Status | Evidence | Finding / fix | Risk |
|---|---|---|---|---|---|---|
| RBAC-01 | §2.4 | Breeze auth | INTENTIONALLY CHANGED | config/fortify.php:155; FortifyServiceProvider.php:178-181 | R1: Fortify; login throttled 5/min. | — |
| RBAC-03 | §2.4 | Single source of authorisation | PARTIAL | LeaveService.php:1056,1098; WarningService.php:183 | 19 enum-role checks remain; a custom role with the right permission is refused. | L |
| RBAC-04 | §2.4, §9 | Gates/policies enforce access | PARTIAL | LeavePolicy, AttendancePolicy, PayrollPolicy (no callers); PayslipController.php:153 | Three dead policies; `Gate::check('view',$payslip)` has no policy and always false. | L |
| RBAC-23 | §4 | HR: System Settings | INTENTIONALLY CHANGED | seeder:162-163; migration 2026_10_06_150527; RoleDelegationGuard.php:38-131 | R12. | — |
| RBAC-24 | §4 | Director: Employee Profiles = view dept | PARTIAL | seeder:168; ApprovalGuard.php:41 | Full CRUD, company-wide unless scoped. D1. | M |
| RBAC-25 | §4 | Director: Attendance = view dept | PARTIAL | seeder:169 | manage_attendance, company-wide unless scoped. D1. | M |
| RBAC-26 | §4 | Director: Leave = approve dept | PARTIAL | seeder:170 | Company-wide unless scoped. D1. | M |
| RBAC-27 | §4 | Director: OT = view dept | PARTIAL | seeder:169 | Holds approve_overtime. D1. | L |
| RBAC-28 | §4 | Director: Compensation = view dept cost | BUG | routes/web.php:383-384; FinanceDashboard.php:29-73; seeder:171 | approve_finance opens the Finance dashboard (every employee's gross/net, OT) and finance approval of payroll. D1. | M |
| RBAC-30 | §4 | Director: Performance = review dept | PARTIAL | AllReviews.php:43-113 | Also manages cycles, increments, warnings via defaults. | L |
| RBAC-31 | §4 | Director: Onboarding/Exit = view dept | PARTIAL | seeder:168; OffboardingManager.php:150,159 | Has write (offboard). | L |
| RBAC-33 | §4 | Director: Payslips = no access | PARTIAL | DocumentPolicy.php:76 | Documents blocked, but Finance dashboard shows amounts (RBAC-28). | M |
| RBAC-34 | §4 | Director: Reports = dept dash | PARTIAL | ExecutiveDashboard.php:30; ReportController.php:42-60 | Unscoped Directors get company-wide exports. | L |
| RBAC-36 | §4 | Manager: Employee Profiles = view team | PARTIAL | EmployeeIndex.php:242-245 | See EMP-16. | L |
| RBAC-37 | §4 | Manager: Attendance = view + approve regularise | INTENTIONALLY CHANGED | TeamAttendance.php:61,118 | R8. | — |
| RBAC-43 | §4 | Manager: Onboarding/Exit = team tasks | MISSING | routes/web.php:227-230 | See EMP-45. | L |
| RBAC-49 | §4 | Finance: Attendance = summary | MISSING | routes/web.php:286-295,510-512,559-564 | All attendance screens need approve-leave. | M |
| RBAC-50 | §4 | Finance: Leave = summary | PARTIAL | routes/web.php:244,545-550 | Encashment queue only. | M |
| RBAC-54 | §4 | Finance: Performance = no access | PARTIAL | seeder:192 | See PRF-23. | L |
| RBAC-55 | §4 | Finance: exit settlement | MISSING | OffboardingManager.php:102-106; OnboardingService.php:50 | See EMP-36. | M |
| RBAC-61 | §4 | Employee can't change locked identity fields | PARTIAL | pages/settings/⚡profile.blade.php:29-43; ⚡delete-user-modal.blade.php:16-22 | Any user can change their own login email and soft-delete their own account (including the only Super Admin). | M |
| RBAC-73 | §4 | Six roles | INTENTIONALLY CHANGED | seeder:138,208-217 | R13: Coordinator. | — |
| RBAC-76 | §4.1 | Finance: attendance/leave summaries | MISSING | see RBAC-49/50 | | M |
| RBAC-77 | §4.1 | Nikita scoped to UK Sales shift only | PARTIAL | ApprovalGuard.php:84-96,196-200,239-245 | Reach = reporting line **plus** scope; as Sales head she gets the whole department; can't be narrowed to the shift. Uncommitted ScopeResolver adds a cap. | M |
| RBAC-79 | §4, §9 | Identity edits limited to the actor's level | BUG | ProfileFieldRegistry.php:185-193; EmployeePolicy.php:102-108; EmployeeEdit.php:270-277 | Any in-reach manage_employees holder can repoint an HR Admin / Finance user's login email, then take the account via password reset. No `unique` rule on email. Fix: delegation ceiling + unique. | M |
| SET-06 | §4 | Roles and permissions management | INTENTIONALLY CHANGED | RoleManager.php:55-56 | R12. | — |
| SET-07 | §4.1 | Approval chain keeps Finance sign-off | PARTIAL | ApprovalPolicySettings.php:61-86 | See PAY-33. | L |
| SET-09 | §4 | AI settings | INTENTIONALLY CHANGED | ⚡ai.blade.php:27,50,85 | R12. | — |
| AUD-03 | §9 | Observer on payslips | PARTIAL | PayslipObserver.php:10-27; PayrollService.php:425 | Bulk status update skips the observer. | L |
| AUD-08 | §9 | Audit visible to SA + HR only | PARTIAL | Payroll/AuditTrail.php:36,109-111 | Finance reads payroll/payslip audit rows. | L |
| AUD-10 | §9 | Retention indefinite | PARTIAL | ClearDemoData.php:57,115-117; RemoveDemoEmployees.php:85 | `hrms:clear-demo-data` truncates audit_logs; `app:remove-demo-employees` deletes rows; no production guard. | M |
| HLP-03 | — | Guide links respect permissions | PARTIAL | Services/Help/RouteAccess.php:30-51 | Ignores `module:` middleware (Payslips link shown when module off). Uncommitted fix. | L |
| HLP-06 | — | Guide matches R4 | PARTIAL | Services/Help/EmployeeGuide.php:156,347 | Says carry-forward "can expire"; CSL never lapses; MDL / Comp Off not explained. | L |
| SEC-01 | §9 | HTTPS enforced | PARTIAL | AppServiceProvider.php:103-105; config/session.php:172 | forceScheme in production; no secure-cookie default, no HSTS; Nginx redirect UNVERIFIED. | L |
| SEC-05 | §9 | No unauthenticated write paths | BUG | AdmsController.php:76-151; routes/web.php:127-132 | `/iclock` ADMS: no auth, throttle or IP check; anyone with a device serial writes attendance. Fix: IP allowlist / comm key + throttle. | **H** |
| SEC-06 | §9 | Rate limiting | PARTIAL | bootstrap/app.php:13-37 | `/api/v1` and `/iclock` unthrottled. | L |
| SEC-08 | §9 | Uploads outside public web root | BUG | MyTimeOff.php:345,437; TeamTimeOff.php:183; AllTimeOff.php:302; EmployeeLeaveDetail.php:586; AttendanceTracker.php:2304 | Leave attachments (medical certificates) and regularisation proofs on the public disk, served without auth. | M |
| SEC-09 | §9 | Payslip downloads via 5-min signed URLs | PARTIAL | routes/web.php:343-344; PayslipController.php:150-155 | Session + owner check instead (equivalent protection, different mechanism). Accept or switch. | L |
| SEC-11 | §9 | Password min 8 | INTENTIONALLY CHANGED | AppServiceProvider.php:139-147 | R15. | — |
| SEC-12 | §9 | 8-hour inactivity timeout | PARTIAL | config/session.php:35; .env.example:31 | Default 480 but .env.example 120; production UNVERIFIED. | L |

PASS: RBAC-02 role on users · RBAC-05 role middleware on route groups · RBAC-06 grace at service layer · RBAC-07 Livewire actions re-authorised (persistent middleware) · RBAC-08 scope engine fails closed · RBAC-09 self-approval blocked everywhere · RBAC-10 Directors can now be scoped · RBAC-11 SA full · RBAC-12…22 HR rows (profiles, attendance, leave, OT, compensation configure, notifications, performance, onboarding, documents, payslips, reports) · RBAC-29 Director notifications · RBAC-32 Director policies only · RBAC-35 Director no settings · RBAC-38…42 manager leave / OT / no compensation / notifications / performance · RBAC-44…47 manager documents / payslips / team dashboard / no settings · RBAC-48 Finance basic profiles · RBAC-51 Finance OT summary (data source hidden, see PAY-12) · RBAC-52/53 Finance compensation / notifications · RBAC-56…59 Finance documents / payslips / reports / no settings · RBAC-60, 62…72 employee rows · RBAC-74 HR manager-level read across departments · RBAC-75 Finance direct payroll approval · RBAC-78 HR profile surface rules · SET-01 company settings gated · SET-02 shift config UI · SET-03 cycle assignment UI · SET-04 MDL setup UI · SET-05 purge Super-Admin-only · SET-08 settings actions authorised · AUD-01 employees observer · AUD-02 leave_requests observer · AUD-04 salary structures · AUD-05 ot_requests · AUD-06 audit_logs columns (auditable_type/id) · AUD-07 viewer SA + HR · AUD-09 audit rows immutable · AUD-11 viewer for HR · HLP-01 guide authenticated, read-only · HLP-02 no broken routes (18 checked) · HLP-04 guide matches R8 · HLP-05 matches R6 · HLP-07 getting-started content · SEC-02 CSRF (only iclock exempt) · SEC-03 no raw SQL interpolation · SEC-04 `{!! !!}` only on trusted output · SEC-07 upload MIME · SEC-10 bcrypt · SEC-13 session invalidated on logout · SEC-14 daily backups, 30 days (same server only, archive includes `.env`; cron UNVERIFIED) · SEC-15 `.env` never committed.

Noted for removal: starter-kit Teams pages (`settings/teams`) let any user create teams and email invitations — unrelated to the HRMS.

---

## Extensions (beyond spec, not gaps)

Recorded so they aren't mistaken for scope creep or gaps: extra lifecycle states and lifecycle engine; onboarding templates, analytics and owner reminders; employee invitation flow; KYC documents; Teams module; performance-linked documents (PIP, promotion, warning); seven work modes with optional location/selfie; biometric devices, punch journey, night shifts; half-day and leave-category regularisations; attendance score engine; repair commands (`attendance:rebuild-punch-timelines`, `attendance:recalculate-hours`); Command Center, Executive Attendance, 13 attendance report types; sandwich leave and cross-request bridge; leave more-info loop; paid/unpaid override; HR apply-on-behalf and correct-approved leave; Leave Assistant; leave regularisation for past absences; append-only leave ledger, rollover, reconciliation, historical import, overrides; holiday-work requests and holiday pay; leave attachments; bulk leave assignment; configurable payroll approval chain; payroll/payslip lock; historical payroll import (written as finalized without Finance sign-off, L); payslip QR authenticity page (signed, no expiry — exposes name and net pay indefinitely, L); dynamic salary components, LWP, F&F line; twelve payroll CSVs incl. bank transfer and statutory; full notifications inbox with preferences and mutes; Coordinator alerts; multi-reviewer performance with HR validation and lock; increment cycles; KPI dashboard; impersonation; Import/Export centre; module switches; forced first-login password; Employee Guide and Getting-Started tutorial.

## Prior-audit reconciliation

Each reviewer re-checked every in-scope row of appendices A–G. Status changes, condensed:

- **Appendix A:** E-03 PASS→PARTIAL (cycle change immediate); E-05, E-10, ON-9, ON-10, D-02, D-05, D-07 → PASS; E-07 split (soft delete PASS, purge INTENTIONALLY CHANGED R12); D-04 split (guard PASS, audience PARTIAL, notification MISSING); R-02 / R-07 CONFLICT→BUG (Director defaults, D1); R-12, R-14, S-01, S-03 → PASS.
- **Appendix B:** A-02, A-03, A-06, A-09, A-13, A-16, A-21 → PASS; A-04, A-14, A-15, R-04 → INTENTIONALLY CHANGED (R20, R8); A-08 PASS→PARTIAL (UK timing); A-18 → BUG L (override path); R-03 → PARTIAL; A-07, A-10, A-11, R-05, R-12 unchanged; OT rows: O-01 split (R9 INTENTIONALLY CHANGED, Nexflow BUG, dates PARTIAL), O-02, O-05 → PARTIAL, O-09 PASS→PARTIAL (dashboard hidden), R-09, R-11, I-05 → PASS.
- **Appendix C:** C-02, C-03, C-04, C-07, C-10, C-18, C-21, C-43, C-44, C-46 → PASS; C-11 → BUG/PARTIAL (late items never paid); C-13 → BUG (no Director approval); C-16, C-20, C-37 → PARTIAL; C-19, C-33, C-38 → BUG; C-24 → PASS (legacy) + BUG (chain bypass).
- **Appendix D:** N09 → INTENTIONALLY CHANGED (R14); N10, E16, E18, E21, S01, S02, S05, S06, S08, S10, X02, R04 → PASS; E06 → INTENTIONALLY CHANGED (R8); E12 PASS→PARTIAL (SMTP rollback, new); E02 PASS→BUG (leave "Rejected", new); E03 PASS→PARTIAL; E17 → PARTIAL; S11 MISSING→PARTIAL; X01 → PASS; X05 → PARTIAL (CSL auto-credit, D7).
- **Appendix E:** R-04, E-07, D-01, D-05, M-01, M-04, S-04, S-08, S-11, P-03 → PASS; R-03 → PARTIAL; D-04, D-06 → PARTIAL; S-02 → INTENTIONALLY CHANGED (R20); E-12, S-10, P-05, P-12, P-14 → BUG; P-02 unchanged at HEAD.
- **Appendix F:** F-H1, F-H2 (except email takeover, now RBAC-79), F-H4, F-M1, F-M3, F-M4, F-M5, F-L2, F-L3, F-L5, F-L6, F-L10 → PASS; F-H3 unchanged (SEC-05, H); F-M2 unchanged (RBAC-28); F-M6 → INTENTIONALLY CHANGED (roles, R12) + PARTIAL (chain); F-L7, F-L8 unchanged.
- **Appendix G:** H-1…H-5, M-1…M-6, L-1…L-4 → PASS; L-6 (BiometricSync orphan, no auth) unchanged as a latent risk; L-5 UNVERIFIED; Finance performance → PARTIAL; manager onboarding tasks and Finance exit settlement still MISSING.
