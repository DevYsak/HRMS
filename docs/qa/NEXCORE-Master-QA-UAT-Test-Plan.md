# NEXCORE / Pulse HRMS — Master Manual QA & UAT Test Plan

**Version:** 1.0 · **Prepared:** 8 October 2026 · **Execution status:** NOT TESTED

## Purpose and safety

This is a tester-facing manual plan for local or staging first. It is based on Pulse v3.1, the 8 October 2026 gap report, the confirmed D1–D10 decisions, current routes, and the current seeded role matrix. Code presence is not evidence of a pass. Record the observed result in every case; do not alter production data, invoke destructive settings, submit production payroll, change employee status, or test biometric device writes in production. Production checks are limited to read-only navigation, own test-account data, and approved sandbox transactions.

**Build under test:** Branch `staging`; commit `02cf1f3d586e79177fc744314c1c6450eb4469ee`; build date `2026-10-08 12:07:03 IST (+05:30)`.  
**Database / test-fixture baseline:** Standard fixture entry point `DatabaseSeeder`; QA fixture topology `NEXCORE-Test-Data-Matrix.md` v1.0. **Current local database is not aligned with the build:** read-only migration status on 8 October found migrations from `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table` onward pending. The migration/seed run must be aligned and recorded before further execution.  
**Sync record:** `NEXCORE-Pre-Execution-Sync-2026-10-08.md`.  
**Execution companions:** `NEXCORE-Test-Data-Matrix.md` and `NEXCORE-Test-Execution-Order.md`. Manual cases must not be changed to PASS from automated test evidence.

Known-risk tests must be run especially carefully: Director scope (D1), leave final approval (D2), encashment final approval (D3), Nexflow OT (D4), notification email default (D5), OT windows (D6), arrears (D8), self-score weighting (D9), and human approval for automated jobs (D10).

## Test environment and common data

Use a disposable staging company with Sales and Marketing departments; one Department Head assigned to Sales; one Manager with two Sales reports; one Marketing employee; a Finance user; a Coordinator assigned only to Sales; an HR Admin; and a Super Admin. Create Cycle A and Cycle B employees, IT and UK shifts, a public holiday and MDL date, a closed payroll period, and an open next period. Never reuse real PAN, Aadhaar, bank, medical, or payroll data.

Required account register (fill before execution):

| Role | Login user | Department / relationship | Expected scope / landing page |
|---|---|---|---|
| Super Admin | `sa.qa@…` | Global | All company data; `/` |
| HR Admin | `hr.qa@…` | HR | All HR data and permitted settings; `/dashboard/hr-admin` |
| Director / Department Head | `sales.head.qa@…` | Head of Sales | Sales only; `/dashboard/department` or configured dashboard |
| Manager | `sales.manager.qa@…` | Sales manager | Direct reports/team only; `/dashboard/manager` |
| Finance | `finance.qa@…` | Finance | Finance-authorised salary/payroll data; `/dashboard/finance` |
| Coordinator | `coord.sales.qa@…` | Assigned Sales only | Attendance exceptions only; `/` |
| Employee | `sales.emp.qa@…` | Reports to Sales Manager | Own data only; `/` |

For every test, capture the required screenshot using `ROLE-MODULE-TC-ID-DESCRIPTION.png`. On any failure capture full page, problem area, and browser console when relevant. Test desktop 1920×1080 and 1366×768, tablet 768×1024, and mobile 390×844.

## Execution rules

Use **NOT TESTED**, **PASS**, **FAIL**, or **BLOCKED** only. A 403/404 or safe denial is a pass only where stated as expected. Direct-URL tests must be made in a fresh private window after signing in as the stated role. For Livewire/network errors preserve the request/response status without exposing secrets. The detailed spreadsheet is the execution record; this plan is the authoritative procedural companion.

## Detailed manual cases

### Authentication and account state

**Test Case ID:** TC-AUTH-001  
**Module:** Authentication  
**Feature:** Valid login and landing route  
**Role:** Each role  
**Priority:** Critical  
**Severity if failed:** Blocker  
**Preconditions:** Account register is active; first-login flag is off.  
**Test Data:** Correct email/password for each role.  
**Starting URL/Page:** `/login`

**Steps:**
1. Open `/login` in a private window.
2. Sign in with the role’s registered test account.
3. Record the first page, sidebar, and displayed user.
4. Compare data/cards and menu to the role checklist.

**Expected Result:** Login succeeds; account reaches its permitted dashboard; no other role’s private data or menus appear.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `ROLE-AUTH-TC-AUTH-001-dashboard.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-AUTH-002  
**Module:** Authentication  
**Feature:** Invalid credentials, reset, logout, expiry and inactive access  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** One active account and separately one inactive/resigned/terminated test employee; mail sink available.  
**Test Data:** Wrong password, malformed email, reset token, inactive accounts.  
**Starting URL/Page:** `/login`

**Steps:**
1. Submit the active email with a wrong password, then a malformed email.
2. Request password reset; use only the mail-sink link to set a policy-compliant password.
3. Sign in, log out, then use Back and a previously copied authenticated URL.
4. Mark only the staging test account inactive/resigned and attempt sign-in.
5. Leave an authenticated session until its configured expiry, then request an authenticated page.

**Expected Result:** Errors are safe and non-enumerating; reset works once; logout/expiry removes access; inactive, resigned, and terminated accounts cannot enter.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-002-account-state.png`  
**Evidence / Notes:** Include first-login password change separately when an issued account requires it.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-AUTH-003  
**Module:** Authentication  
**Feature:** Role change revokes/grants access  
**Role:** HR Admin / Employee  
**Priority:** High  
**Severity if failed:** Critical  
**Preconditions:** Staging user may safely change role; two browser sessions available.  
**Test Data:** Employee promoted to Manager then reverted.  
**Starting URL/Page:** `/settings/roles` (or current role manager)

**Steps:**
1. Sign in as Employee in window A and open a Manager-only URL in a second tab.
2. As authorised HR Admin, grant Manager role/scope in window B.
3. Refresh window A and verify permitted team access.
4. Revoke the role and refresh/re-authenticate; retry the copied Manager URL.

**Expected Result:** Permission change takes effect as configured; revoked access is denied server-side, not merely hidden.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-RBAC-TC-AUTH-003-role-change.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### RBAC, scope, navigation, dashboards

**Test Case ID:** TC-RBAC-001  
**Module:** RBAC  
**Feature:** Role × module UI and direct-route access  
**Role:** All roles  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Account register and permission matrix completed.  
**Test Data:** Every route in the route inventory below.  
**Starting URL/Page:** `/`

**Steps:**
1. For each role, record every visible sidebar group/item and active state.
2. Open each allowed URL and confirm useful permitted content.
3. In a private window, manually open every forbidden URL.
4. Record status, redirect, and any data exposed before denial.

**Expected Result:** Navigation and backend agree: allowed routes work; forbidden routes return safe 403/redirect with no content leakage; no blank/duplicate groups.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `ROLE-RBAC-TC-RBAC-001-navigation-403.png`  
**Evidence / Notes:** Run the matrix in `NEXCORE-Security-Access-Checklist.md`.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-RBAC-002  
**Module:** RBAC  
**Feature:** Department/team isolation  
**Role:** Director, Manager, Coordinator  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Sales and Marketing target employees have distinct records, attendance, leave, performance, and documents.  
**Test Data:** Sales head/manager/coordinator and Marketing employee ID.  
**Starting URL/Page:** `/employees/{marketing-id}/profile`

**Steps:**
1. As Sales Director, attempt Marketing profile, attendance, leave, performance, salary, and document URLs.
2. As Sales Manager, repeat for a non-reporting Sales employee and Marketing employee.
3. As Sales Coordinator, attempt non-assigned exception records and employee details.
4. Change route IDs/query filters to each target and inspect response before denial.

**Expected Result:** Director sees only assigned Sales data; Manager sees direct/team data only; Coordinator sees configured exception scope only. Unauthorised data is never rendered.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-RBAC-TC-RBAC-002-marketing-blocked.png`  
**Evidence / Notes:** D1 regression; current audit reported company-wide overreach risk.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-NAV-001  
**Module:** Navigation  
**Feature:** Sidebar, nested menus, breadcrumbs and back navigation  
**Role:** All roles  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Successful role login.  
**Test Data:** One item per visible module.  
**Starting URL/Page:** Role landing page

**Steps:**
1. Open each sidebar group and nested item.
2. Confirm correct active marker, page title and breadcrumb.
3. Use browser Back/Forward after deep navigation.
4. Refresh each deep link and switch role/permission in a separate session.

**Expected Result:** Routes are stable, relevant, singular, and highlight correctly; no inaccessible links, stale menus, or blank groups appear.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `ROLE-NAV-TC-NAV-001-sidebar.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-DSH-001  
**Module:** Dashboards  
**Feature:** Role dashboard content, scope, states and integrity  
**Role:** All roles  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Seeded data produces headcount, exception, approval, and empty-state examples.  
**Test Data:** Each role account.  
**Starting URL/Page:** Role landing page

**Steps:**
1. Capture full dashboard for each role.
2. Compare each card/count to the source list/detail page and expected scope.
3. Test loading, empty state, narrow viewport, and one long employee name.
4. Open each card/link and validate its destination and filter.

**Expected Result:** Super Admin company summary; HR operational queue; Director department-only; Manager team-only; Finance authorised payroll queue; Coordinator configured exceptions; Employee own items. No invented/repeated metric, overlap, or blank excessive area.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `ROLE-DSH-TC-DSH-001-dashboard.png`  
**Evidence / Notes:** Finance/executive dashboard gaps are known risks.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Employees, onboarding, offboarding, documents

**Test Case ID:** TC-EMP-001  
**Module:** Employee Management  
**Feature:** Create, edit, lifecycle, assignment and field validation  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Staging only; an unused employee code/email; valid lookup data.  
**Test Data:** Invalid/valid PAN, Aadhaar, account, IFSC, phone, email, DOB; Department, Manager, Shift, work mode, Cycle, HRA.  
**Starting URL/Page:** `/employees/create`

**Steps:**
1. Submit blank mandatory fields, then each invalid value one at a time.
2. Create a valid employee and attach a non-sensitive test document.
3. Edit department, manager, shift, work mode, cycle, lifecycle and payroll eligibility/HRA switches.
4. Change only Account Number to an invalid value; inspect its error label.
5. View, archive/resign (staging only), and verify post-last-working-day access.

**Expected Result:** Each error is beside its own control; Account Number never shows Aadhaar error; a valid record persists accurate assignments; lifecycle revokes access as configured.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMP-TC-EMP-001-validation.png`  
**Evidence / Notes:** Do not permanently alter production identities.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-EMP-002  
**Module:** Onboarding / Offboarding  
**Feature:** Task ownership, completion and access revocation  
**Role:** Employee, Manager, HR Admin, Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** A new joiner and an exit record in staging.  
**Test Data:** Checklist owned by employee, manager, HR, finance.  
**Starting URL/Page:** `/my-onboarding`

**Steps:**
1. As new employee, open assigned onboarding tasks and complete permitted item.
2. As Manager and HR, complete their own tasks and verify no false auto-completion.
3. Create/advance an exit record using staging employee and review Finance settlement visibility.
4. On last working day, verify employee login/session and copied URLs are denied.

**Expected Result:** Each owner can see and complete only their tasks; exit flow is auditable; resignation/termination prevents continued access even if exit details are incomplete.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONB-TC-EMP-002-task-owner.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-DOC-001  
**Module:** Documents  
**Feature:** Versioning, expiry, acknowledgement and protected download  
**Role:** HR Admin, Employee, unauthorised Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Two employee documents and one company acknowledgement document in staging.  
**Test Data:** New and old version; copied download/storage URL.  
**Starting URL/Page:** `/documents` or current document manager

**Steps:**
1. Upload version 1 then version 2 and open the library item.
2. Verify recipient acknowledgement and expiry notification using test data.
3. As owner, open authorised document.
4. As another employee and logged-out private window, open copied controller and `/storage/...` URLs.

**Expected Result:** Latest version opens; correct recipients are notified; KYC/medical/regularisation/payslip evidence cannot be guessed, downloaded, or rendered without authorisation.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-DOC-TC-DOC-001-url-denied.png`  
**Evidence / Notes:** Known public-disk risk; do not use real evidence.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Attendance, biometric, breaks and regularisation

**Test Case ID:** TC-ATT-001  
**Module:** Attendance / Biometric  
**Feature:** Canonical punch timeline and worked hours  
**Role:** Employee, HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Staging biometric simulator/test device; IT and UK shift accounts.  
**Test Data:** Face IN, Card OUT, repeat/stray punches, 60-second burst, holiday/weekly off.  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Send Face IN, then Card OUT; open the employee timeline.
2. Send duplicate Face IN and stray Card OUT; send a same-direction burst within 60 seconds.
3. Repeat on IT and UK shifts including late arrival, missing checkout, weekly off and holiday.
4. Compare displayed first valid IN, final valid OUT, flags and total hours to stored/raw timeline.

**Expected Result:** Face is IN, Card is OUT; duplicate/stray handling follows policy; worked hours equal final valid OUT minus first valid IN; breaks are informational and not deducted; missing checkout occurs only after shift end + 60 minutes.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-001-timeline.png`  
**Evidence / Notes:** Never post to production `/iclock`.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-ATT-002  
**Module:** Breaks  
**Feature:** Start/end, excess break and clock-out closure  
**Role:** Employee, HR Admin  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Active attendance day with configured break policy.  
**Test Data:** Within-limit break, excess break, open break at clock-out, UK after 20:00.  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Start and end a within-limit break.
2. Exceed the policy limit and run/await the exception check in staging.
3. Start a break and clock out without ending it.
4. Repeat excess scenario on UK shift after 20:00.

**Expected Result:** Timeline states are coherent; excess is flagged/notified at the correct time; open break is safely resolved at clock-out; total worked hours remain IN-to-OUT.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-002-break.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-REG-001  
**Module:** Regularisation  
**Feature:** Submission, HR decision, edit/revert and genuine later punches  
**Role:** Employee, HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Staging attendance day with later raw biometric punches.  
**Test Data:** Valid/invalid dates, OUT≤IN, overnight correction; regularised segment then Face IN/Card OUT/Face IN/Card OUT.  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Submit valid correction with evidence; attempt wrong date, OUT≤IN and overnight inputs.
2. Verify Manager sees awaiting-HR only and HR approves/rejects with notification.
3. As HR, edit approved correction, then revert/delete with confirmation.
4. After an approved first segment, send the four later genuine punches and reopen timeline/hours/OT.

**Expected Result:** Valid request routes straight to HR; invalid inputs fail beside correct fields; audit trail records actions; later genuine punches remain in timeline and total covers first valid IN to final valid OUT.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-001-final-hours.png`  
**Evidence / Notes:** Capture before approval, after approval, drawer, and final hours.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Leave, encashment and OT

**Test Case ID:** TC-LV-001  
**Module:** Leave  
**Feature:** CSL, MDL and Comp Off request/approval rules  
**Role:** Employee, Manager, HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Balances and holiday/weekly-off/MDL dates configured.  
**Test Data:** Full day, half day, multi-day, overlap, insufficient balance, holiday and weekly-off requests.  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Submit CSL, MDL and Comp Off requests; include valid full/half day and an overlap/insufficient case.
2. Attempt a single half-day flag across a multi-day request and request chargeable leave on holiday/MDL/weekly-off.
3. As Manager approve a valid standard leave; then inspect employee/HR notification and balance.
4. Reject, cancel, correct, and (HR) override one staging request.

**Expected Result:** Rules/balance are accurate; invalid range/half-day cases are blocked; manager approval is final for standard leave (D2); employee receives “Leave Approved,” never “Leave Rejected” after approval; audit/balance changes exactly once.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-LV-TC-LV-001-approved.png`  
**Evidence / Notes:** Capture leave balances and approval notification.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-ENC-001  
**Module:** Leave Encashment  
**Feature:** Balance, final approval, debit and payroll inclusion  
**Role:** Employee, Director, HR Admin, Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** CSL balance; current and closed payroll periods.  
**Test Data:** Valid amount, amount above balance, late-approved request.  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Submit a valid encashment and a deliberately excessive one.
2. Approve one as Director; approve another as HR; reject a third.
3. Verify no Finance step is required or available as final approval.
4. Close original cycle, approve a late item, run next eligible staging payroll and inspect arrears/original period.

**Expected Result:** Available CSL is checked; Director OR HR is final (D3); CSL debits once; rejected item does not debit; late item appears once in next open payroll as arrears.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ENC-TC-ENC-001-arrears.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-OT-001  
**Module:** Overtime  
**Feature:** Submission, actual hours, approval, Nexflow and regularisation OT  
**Role:** Employee, Manager, HR Admin, Finance  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Worked and unworked days, holiday, missing-OUT case, and Nexflow test payload in staging.  
**Test Data:** Future request, actual hours, no attendance, missing OUT, approved regularisation, holiday.  
**Starting URL/Page:** `/overtime/my`

**Steps:**
1. Submit normal OT without an open OT window; submit future/no-attendance/missing-OUT cases.
2. Submit actual worked/holiday OT and route through Manager approval/rejection.
3. Approve regularisation that exceeds threshold; inspect generated OT.
4. Import Nexflow-approved OT and inspect resulting Pulse status/reviewer.

**Expected Result:** Normal submission does not require a window (D6); no genuine final OUT means no payable actual OT; manager decision is recorded; regularisation rule follows policy; Nexflow creates **pending** Pulse OT for approval, never automatically payable (D4).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-001-approval.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Payroll, cycles, arrears, incentives, reimbursements and payslips

**Test Case ID:** TC-PAY-001  
**Module:** Payroll  
**Feature:** Cycle A/B draft, Finance approval, finalisation and lock  
**Role:** HR Admin, Finance, Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Cycle A/B data with HRA, OT, incentive, reimbursement, encashment, statutory and LOP test records.  
**Test Data:** One payroll per cycle; configuration with attempted missing Finance stage.  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Generate draft for each cycle and reconcile each inclusion/deduction to source data.
2. Attempt to configure/submit an approval chain without Finance.
3. Submit to Finance, attempt payslip edit, then approve/finalise/lock.
4. As Employee, check drafts before and after finalisation and attempt own/another employee download.

**Expected Result:** Finance step cannot be removed; submitted payslip edit is locked or resets approval per implemented rule; draft payslips are invisible to employees; final values include valid HRA/OT/incentive/reimbursement/encashment/statutory/LOP exactly once.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-001-finance-approval.png`  
**Evidence / Notes:** Never execute live payroll as a test.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-PAY-002  
**Module:** Payroll / Arrears  
**Feature:** Late approved monetary items and duplicate prevention  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Original month payroll closed; next payroll open.  
**Test Data:** One late OT, reimbursement, incentive and encashment.  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Create/approve each item after its original period closes.
2. Generate next eligible open payroll and inspect lines/source period/arrears label.
3. Finalise once, then re-run preview/process according to permitted staging workflow.
4. Reconcile paid totals back to all four source records.

**Expected Result:** Each late item enters next eligible open payroll, retains original period, displays arrears indicator, and is paid exactly once; re-run cannot duplicate it (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-002-arrears.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-CYC-001  
**Module:** Salary Cycle  
**Feature:** Deferred A↔B move and transition payroll  
**Role:** HR Admin, Finance, Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** A and B cycles and employee payroll history exist.  
**Test Data:** A→B, B→A, pending cancellation.  
**Starting URL/Page:** Employee payroll settings

**Steps:**
1. Request A→B effective next payroll month and inspect current payroll membership.
2. Cancel a pending change and confirm unchanged membership.
3. Request B→A and execute transition payroll in staging.
4. Count payslips, salary, and LOP across affected month.

**Expected Result:** Change takes effect next payroll month; no pro-rating is expected; no duplicate/missing salary; LOP once; exactly one payslip per month.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-CYC-TC-CYC-001-transition.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Notifications, performance, scheduled jobs, reports, settings and audit

**Test Case ID:** TC-NTF-001  
**Module:** Notifications  
**Feature:** Routing, content, link, unread state and email default  
**Role:** All roles  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Mail sink; one event each for leave, OT, regularisation, encashment, attendance, payroll/payslip, performance, document, probation/onboarding.  
**Test Data:** Triggered staging events.  
**Starting URL/Page:** Notifications bell / `/notifications`

**Steps:**
1. Trigger each event with a known expected recipient/team/department.
2. Inspect in-app text, link, unread/read change and badge count.
3. Inspect mail sink while event email setting remains default-off.
4. Enable one approved optional email setting and repeat once.

**Expected Result:** Correct authorised recipient and URL receive useful wording; read/badge state is correct; non-critical email is off unless explicitly enabled (D5); no unrelated department recipient receives it.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NTF-TC-NTF-001-routing.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-PRF-001  
**Module:** Performance / KPI  
**Feature:** Cycle, review routing, score normalisation and promotion feedback  
**Role:** Employee, Manager, HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Active KPI cycle/template and assigned participant.  
**Test Data:** Different KPI weights and self-score.  
**Starting URL/Page:** `/performance/my-review`

**Steps:**
1. Create/assign KPI template and cycle; employee submits self assessment.
2. Manager reviews; HR validates/finalises; attempt out-of-order and post-deadline actions.
3. Compare displayed self score, weighted components and final score to hand calculation.
4. Add feedback/promotion flag and verify history/access scope.

**Expected Result:** Self score is comparison-only with default 0% weighting; KPI score is normalised; authorised routing/deadlines/locking hold; feedback and promotion outcome persist in history (D9).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-PRF-TC-PRF-001-score.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-JOB-001  
**Module:** Scheduled Jobs  
**Feature:** Flags/drafts versus prohibited autonomous actions  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Scheduler test environment; staging evidence and absence records.  
**Test Data:** Late/absence, medical evidence, disciplinary threshold.  
**Starting URL/Page:** Scheduler/logs and relevant queues

**Steps:**
1. Trigger/observe scheduled late, missing-checkout, review, probation, document and absence jobs in staging.
2. Verify any flag, notification, recommendation, or draft.
3. Inspect before/after status of medical evidence, disciplinary letters, and absence leave.
4. Complete each outcome only through a named human approver.

**Expected Result:** Jobs may flag, notify, recommend, or create drafts only; they do not issue disciplinary letters, delete medical evidence, or convert absence into approved unpaid leave without human approval (D10).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-JOB-TC-JOB-001-human-approval.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

**Test Case ID:** TC-OPS-001  
**Module:** Reports, Settings, Help & Audit  
**Feature:** Authorised reporting/configuration, guide accuracy, immutable activity  
**Role:** Super Admin, HR Admin, Finance, Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Existing audited change and report data.  
**Test Data:** Allowed and forbidden settings/report URLs.  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. As each role, follow Help & Guide to every listed relevant task/page.
2. Open permitted reports and export options; try forbidden report/settings URLs directly.
3. Make one approved staging change and locate its audit record.
4. Attempt audit modification/delete via UI and verify no unsafe demo-clear action is exposed.

**Expected Result:** Guide names, images, links and rules match UI; only authorised reports/settings open; audit record is complete and immutable; no production-impacting purge test is run.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-OPS-TC-OPS-001-audit-guide.png`  
**Evidence / Notes:**  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

### Error, responsive and end-to-end UAT

**Test Case ID:** TC-ERR-001  
**Module:** Error Handling / UI  
**Feature:** 403, 404, 419, 422, 500-safe handling and responsive consistency  
**Role:** Employee, HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Test-only invalid route/form/session; error monitoring permitted.  
**Test Data:** Forbidden URL, nonexistent URL, expired form/session, invalid fields, controlled test error if available.  
**Starting URL/Page:** Relevant module page

**Steps:**
1. Request forbidden and nonexistent URLs.
2. Expire a form/session then submit; submit invalid form fields.
3. Trigger approved controlled 500 only in local/staging if provided.
4. Repeat key pages at all four viewports; inspect sidebar, header, tables, filters, forms, modals, cards, charts, pagination, drawers, buttons and long names.

**Expected Result:** Safe usable 403/404/419/422/500 pages contain no stack trace/DB/secrets; errors stay near fields and recover where practical; layout has no clipping, overlap, inaccessible controls, or horizontal breakage.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ERR-TC-ERR-001-responsive.png`  
**Evidence / Notes:** Capture full page and problem area for every defect.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

## Route inventory for direct checks

Test the applicable current routes: `/employees/*`, `/attendance/{my,team,employees,exceptions,reports}`, `/dashboard/{department,manager,finance,director}`, `/time-off/{my,team,employees,encashments,leave-management}`, `/overtime/{my,manage,nexflow,windows}`, `/payroll/{my-payslips,overview,process,incentives,reimbursements,finance-approval}`, `/performance/*`, `/documents`, `/notifications`, `/help/*`, `/settings/*`, `/settings/audit-log`, and direct document/payslip download URLs. Current sidebar labels include **Department View**, **Team View**, and **Finance View**; exact visibility is permission-driven. Record the exact deployed route if navigation differs.

## Exit criteria

Do not approve UAT until every Critical case is PASS or formally risk-accepted, all seven role journeys complete, no unauthorised data exposure remains, payroll reconciliation passes, and all failed/blocked cases have triaged bug IDs. Populate the companion execution summary at the end of the run.
