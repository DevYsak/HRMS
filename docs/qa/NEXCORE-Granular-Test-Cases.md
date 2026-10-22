# NEXCORE — Granular Manual Test Cases (supplement)

**Status of every case: NOT TESTED.** Companion to `NEXCORE-Master-QA-UAT-Test-Plan.md` (broad cases). These are single-purpose cases with exact steps. Run on local/staging first. Destructive steps (role changes, deactivation, `--apply`, purge) are **staging only**. Production verification is read-only.

Total cases: **230**. Role keys: SA=Super Admin, HR=HR Admin, DIR=Director / Department Head, MGR=Manager, FIN=Finance, COO=Coordinator, EMP=Employee.
Screenshot file format: `ROLE-MODULE-TC-ID-DESCRIPTION.png`. On any FAIL capture full page, problem area, and console if relevant.

## AUTH

**Test Case ID:** TC-AUTH-001  
**Module:** AUTH  
**Feature:** Login  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Blocker  
**Preconditions:** Active employee account; first-login flag off.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Open /login in a private window.
2. Enter the registered email and correct password.
3. Press Sign in.
4. Note the landing page and the sidebar items.

**Expected Result:** Login succeeds, lands on the employee dashboard "/", sidebar shows only employee items (Dashboard, My Profile, Attendance, Leave, Holidays, Work From Home, Overtime, Documents, Inbox, Help & Guide and similar). No HR/Finance/Admin item appears.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-001-employee-landing.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-002  
**Module:** AUTH  
**Feature:** Login  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Blocker  
**Preconditions:** Active HR Admin account.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in as HR Admin.
2. Note the landing page.

**Expected Result:** HR Admin reaches the dashboard permitted for the role (HR dashboard /dashboard/hr-admin or "/"). No error page.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-AUTH-TC-AUTH-002-hr-landing.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-003  
**Module:** AUTH  
**Feature:** Login  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Blocker  
**Preconditions:** Active Finance account.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in as Finance.
2. Note landing page and sidebar.

**Expected Result:** Finance lands on its dashboard; Payroll group is visible; Employees management, Settings and Performance admin items are not.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-AUTH-TC-AUTH-003-finance-landing.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-004  
**Module:** AUTH  
**Feature:** Login  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** Blocker  
**Preconditions:** Active Department Head assigned to Sales.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in as the Sales Department Head.
2. Note landing page and sidebar.

**Expected Result:** Lands on the department view; every list and card shows Sales employees only (D1).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-AUTH-TC-AUTH-004-dept-head-landing.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-005  
**Module:** AUTH  
**Feature:** Login failure  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Active employee account.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Enter the correct email and a wrong password; submit.
2. Enter a malformed email such as "abc@"; submit.
3. Enter an unregistered email with any password; submit.

**Expected Result:** Each attempt is refused with a generic error. The message does not reveal whether the email exists. No stack trace. Field error is shown beside the email/password field.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-005-invalid-credentials.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-006  
**Module:** AUTH  
**Feature:** Login throttle  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Active employee account.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Submit a wrong password 6 times within one minute.
2. Then submit the correct password immediately.

**Expected Result:** After repeated failures the form shows a "too many attempts / try again in N seconds" message and refuses even the correct password until the wait ends (limit is 5 per minute).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-006-throttle.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-007  
**Module:** AUTH  
**Feature:** Inactive / resigned  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging test employee with status Inactive; another whose last working day was yesterday (exit record).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in as the inactive employee.
2. Sign in as the employee whose last working day has passed.
3. If any session was already open for either, refresh an authenticated page.

**Expected Result:** Neither account can use the application: sign-in is refused or the user is signed out immediately. No data page loads.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-007-inactive-blocked.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-008  
**Module:** AUTH  
**Feature:** Resigned without exit record  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Staging employee set to status Resigned (or Terminated) with NO exit record.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Set the status as stated on staging only.
2. Sign in as that employee.

**Expected Result:** Record the result. Expected per spec: access is revoked. Known gap EMP-34: such users may still sign in. Mark FAIL if access is still granted and link EMP-34.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-AUTH-TC-AUTH-008-resigned-no-exit.png`  
**Evidence / Notes:** Gap EMP-34  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-009  
**Module:** AUTH  
**Feature:** Logout  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open the user menu and choose Log out.
2. Press the browser Back button.
3. Paste a previously visited authenticated URL, e.g. /attendance/my.

**Expected Result:** Session ends; Back and the pasted URL redirect to /login. No cached private page content is shown.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-009-logout.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-010  
**Module:** AUTH  
**Feature:** Session expiry  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in; session lifetime known from environment (default 480 min).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Leave the session idle past the configured lifetime (on staging, ask the admin to shorten it for the test).
2. Click any sidebar item.

**Expected Result:** User is sent to /login. After signing in again the original request is not replayed unsafely.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** NO  
**Screenshot Name:**  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-011  
**Module:** AUTH  
**Feature:** Password change  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/password (Profile > Password)`

**Steps:**
1. Open the password settings.
2. Enter the wrong current password; submit.
3. Enter the right current password and a weak new password (e.g. "abc123"); submit.
4. Enter a compliant new password (12+ chars, mixed case, number, symbol); submit.
5. Log out and sign in with the new password.

**Expected Result:** Wrong current password and weak password are refused with errors beside the correct fields. The compliant password is accepted; the old password stops working.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-011-password-change.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-012  
**Module:** AUTH  
**Feature:** First-login password  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Newly invited employee with the forced-change flag.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in with the temporary password.
2. Try to open /attendance/my directly.
3. Complete the forced change at /set-password.

**Expected Result:** User is held on the set-password page until a compliant password is saved; other pages redirect there. After saving, normal navigation works.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-012-first-login.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-013  
**Module:** AUTH  
**Feature:** Password reset  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Mail sink (log/mailpit) available on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/forgot-password`

**Steps:**
1. Request a reset for a real employee email.
2. Request one for an unregistered email.
3. Open the reset link from the mail sink and set a compliant password.
4. Reuse the same link a second time.

**Expected Result:** Both requests show the same neutral confirmation. The link works once; reuse is refused. Sign-in works with the new password.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-AUTH-TC-AUTH-013-password-reset.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-014  
**Module:** AUTH  
**Feature:** Role change  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Two browsers; a staging employee who can be promoted to Manager and back.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/roles`

**Steps:**
1. Browser A: sign in as the employee; open /time-off/team (expect 403).
2. Browser B: as HR/SA grant the Manager role.
3. Browser A: reload /time-off/team.
4. Browser B: revoke the role.
5. Browser A: reload /time-off/team again.

**Expected Result:** Step 1 403; after grant the page opens (team only); after revoke it returns to 403 on the next request. Menu and URL always agree.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-AUTH-TC-AUTH-014-role-change.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUTH-015  
**Module:** AUTH  
**Feature:** Disabled account  
**Role:** Super Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging account that is signed in elsewhere.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees`

**Steps:**
1. As SA deactivate the signed-in test employee (lifecycle status to Inactive with a past last working day).
2. In the other browser click any link.

**Expected Result:** The deactivated user cannot continue; they are signed out or blocked on the next request.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-AUTH-TC-AUTH-015-disabled-account.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## RBAC

**Test Case ID:** TC-RBAC-001  
**Module:** RBAC  
**Feature:** Director scope  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Sales Department Head; Sales employee S1 and Marketing employee K1 exist.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees`

**Steps:**
1. Open /employees.
2. Search for K1 (Marketing).
3. Open /employees/{K1 id}/profile and /employees/{K1 id}/edit by typing the URL.
4. Open /attendance/employees and filter by K1.

**Expected Result:** List and search show Sales only. K1 profile/edit URLs give 403 or an authorised-denial page. K1 never appears in attendance. No Marketing name, salary or leave is leaked in any message.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-RBAC-TC-RBAC-001-dept-isolation.png`  
**Evidence / Notes:** D1  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-002  
**Module:** RBAC  
**Feature:** Director no company-wide edit  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Department Head with default scope only.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/create`

**Steps:**
1. Open /employees/create.
2. If it opens, try to create an employee in Marketing.
3. Open /employees/{Sales id}/offboarding and /employees/{Sales id}/edit.

**Expected Result:** Per D1 a Director has no company-wide employee edit, offboarding or salary access unless explicitly granted. Record exactly what opens. Any company-wide create/edit/offboard is a FAIL.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-RBAC-TC-RBAC-002-director-no-crud.png`  
**Evidence / Notes:** D1; gap EMP-15/44  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-003  
**Module:** RBAC  
**Feature:** Director salary  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Department Head; Sales employee S1.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{S1}/finance-profile`

**Steps:**
1. Open /employees/{S1 id}/finance-profile.
2. Open /dashboard/finance.
3. Open /payroll/finance-approve and /payroll/process.

**Expected Result:** All four are denied (403) unless the Director was explicitly granted payroll permission in Roles & Permissions. No salary figure is shown.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-RBAC-TC-RBAC-003-director-salary-denied.png`  
**Evidence / Notes:** D1; RBAC-28/PAY-75  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-004  
**Module:** RBAC  
**Feature:** Manager team scope  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Sales Manager with two direct reports; another Sales employee not reporting to them.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Open /time-off/team.
2. Open /attendance/team.
3. Search for the non-report Sales employee.

**Expected Result:** Only the two direct reports appear. The non-report is absent from lists and from search.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-RBAC-TC-RBAC-004-team-isolation.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-005  
**Module:** RBAC  
**Feature:** Manager forbidden URLs  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Sales Manager.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/create`

**Steps:**
1. Type each URL: /employees/create, /settings/roles, /payroll/process, /dashboard/hr-admin, /dashboard/finance, /settings/audit-log, /overtime/windows.

**Expected Result:** Each returns 403 (or redirects to an allowed page). None shows data or a form.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-RBAC-TC-RBAC-005-manager-forbidden.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-006  
**Module:** RBAC  
**Feature:** Employee forbidden URLs  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Type each URL: /time-off/team, /attendance/team, /attendance/employees, /employees/create, /payroll/process, /payroll/overview, /settings/roles, /reports/attendance-summary.csv, /dashboard/manager.

**Expected Result:** Each returns 403. Nothing downloads.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-RBAC-TC-RBAC-006-employee-forbidden.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-007  
**Module:** RBAC  
**Feature:** Finance forbidden URLs  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Finance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/roles`

**Steps:**
1. Type each URL: /settings/roles, /employees/create, /employees/{id}/edit, /attendance/settings, /performance/cycles, /dashboard/hr-admin.

**Expected Result:** Each returns 403. Finance never edits employees or settings.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-RBAC-TC-RBAC-007-finance-forbidden.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-008  
**Module:** RBAC  
**Feature:** Coordinator scope  
**Role:** Coordinator  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Coordinator assigned to Sales only.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/exceptions`

**Steps:**
1. Open /attendance/exceptions.
2. Check the employees listed.
3. Type /time-off/team, /payroll/overview, /employees/create, /attendance/command-center.

**Expected Result:** Exceptions list shows only assigned Sales employees. The other URLs return 403. A Coordinator has no approval, payroll or settings power.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `COO-RBAC-TC-RBAC-008-coordinator-scope.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-009  
**Module:** RBAC  
**Feature:** HR boundaries  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as HR Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/roles`

**Steps:**
1. Open /settings/roles.
2. Try to assign the Super Admin role to a user.
3. Try to assign the HR Admin role to a user.
4. Open /settings/data-management and look for purge / force-delete.

**Expected Result:** HR cannot grant Super Admin or HR Admin roles. Data purge and force-delete are Super-Admin-only. Denials explain themselves; no 500.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-RBAC-TC-RBAC-009-hr-boundaries.png`  
**Evidence / Notes:** R12  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-010  
**Module:** RBAC  
**Feature:** SA full access  
**Role:** Super Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Super Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open each sidebar item in turn, including Settings and Payroll.
2. Open /settings/audit-log.

**Expected Result:** Every page loads without 403/500. Audit log lists recent events with actor, event, time.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-RBAC-TC-RBAC-010-sa-all-pages.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-011  
**Module:** RBAC  
**Feature:** Menu vs URL agreement  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as each role in turn (repeat per role).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Record every sidebar item visible to the role.
2. Click each; record result.
3. For 5 items NOT in the menu, type the URL; record result.

**Expected Result:** Every visible item opens (no 403). Every hidden item that should be blocked returns 403. No empty menu group, no duplicate item, no item that leads to 403.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-RBAC-TC-RBAC-011-menu-agreement.png`  
**Evidence / Notes:** Repeat for all 7 roles; one row per role in the matrix.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-012  
**Module:** RBAC  
**Feature:** Hidden action  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Critical  
**Preconditions:** Employee with a pending leave of their own and a colleague’s pending leave id known.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Open the browser developer tools Network tab.
2. Try to approve a colleague’s leave by replaying a captured approve request with the colleague’s id (use a tester tool or Livewire call).

**Expected Result:** The server rejects it (403/validation). The colleague’s leave stays pending. (Skip if no safe replay tool; mark BLOCKED.)  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-RBAC-TC-RBAC-012-hidden-action.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-013  
**Module:** RBAC  
**Feature:** Employee id in URL  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Two employees A and B.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/payslips/{id}/download`

**Steps:**
1. As A, open your own payslip download and note the numeric id.
2. Change the id to a payslip id of B.
3. Open /performance/scorecard/{B id} and /employees/{B id}/profile.

**Expected Result:** All requests for B return 403/404. No B data appears.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-RBAC-TC-RBAC-013-idor.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-014  
**Module:** RBAC  
**Feature:** Module switch  
**Role:** Super Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Staging only.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/modules`

**Steps:**
1. Switch Payroll off.
2. As Finance open /payroll/overview and the sidebar.
3. Switch Payroll back on.

**Expected Result:** With Payroll off its menu group disappears and its routes return 403/404. After re-enabling, both return.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-RBAC-TC-RBAC-014-module-switch.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-RBAC-015  
**Module:** RBAC  
**Feature:** Menu configuration  
**Role:** Super Admin  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** Staging only.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/menu`

**Steps:**
1. Rename a menu label and hide one employee item; save.
2. Sign in as an employee and look at the sidebar.
3. Revert the change.

**Expected Result:** Employee sidebar reflects the change. Hidden items stay unreachable only if also permission-denied; note whether the URL still opens (a hidden menu alone is not security).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-RBAC-TC-RBAC-015-menu-config.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## DASH

**Test Case ID:** TC-DASH-001  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Super Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Super Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: company headcount, attendance, leave, payroll status, alerts. It must NOT show: nothing hidden. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-DASH-TC-DASH-001-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-002  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as HR Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/hr-admin`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: employees, attendance exceptions, leave, regularisations, documents, onboarding, probation. It must NOT show: payroll amounts you cannot approve. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-DASH-TC-DASH-002-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-003  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Director / Department Head  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Director / Department Head.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/department`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: Sales headcount, Sales attendance, Sales leave, Sales approvals. It must NOT show: any Marketing person or company totals. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-DASH-TC-DASH-003-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-004  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Manager  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Manager.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/manager`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: direct reports’ attendance today, pending leave/OT/regularisation for the team. It must NOT show: non-reports; salary. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-DASH-TC-DASH-004-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-005  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Finance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/finance`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: payroll cycle status, approval queue, OT, incentives, reimbursements, encashments. It must NOT show: employee performance or editing tools. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-DASH-TC-DASH-005-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-006  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Coordinator  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Coordinator.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: own employee dashboard plus an Attendance Exceptions menu item. It must NOT show: approvals, payroll. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `COO-DASH-TC-DASH-006-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-007  
**Module:** DASH  
**Feature:** Dashboard content  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Signed in as Employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open the dashboard.
2. Capture the full page (scroll and capture all).
3. Check every card number against its source page (e.g. open Attendance / Leave for the same figure).
4. Check for repeated cards or repeated numbers.

**Expected Result:** Dashboard shows: own attendance today, leave balances (CSL/MDL/Comp Off), OT, latest payslip, notifications, holidays, profile completion. It must NOT show: other people’s data except permitted colleagues-on-leave. Numbers match their source pages. No duplicate cards, no invented metrics, no overlapping text, consistent card padding and fonts.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-DASH-TC-DASH-007-dashboard-full.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-008  
**Module:** DASH  
**Feature:** Dashboard empty / loading  
**Role:** Employee  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** New employee with no attendance, leave or payslip.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Sign in as the new employee.
2. Reload and watch the loading state.

**Expected Result:** Cards show tidy empty states ("no payslip yet" style), no NaN, no 0/0%, no broken layout. Loading state does not jump the layout.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-DASH-TC-DASH-008-dashboard-empty.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-009  
**Module:** DASH  
**Feature:** Finance dashboard content  
**Role:** Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** A draft payroll for the current cycle exists.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/finance`

**Steps:**
1. Open the dashboard.
2. Look for cycle progress, approval queue, OT, incentives, reimbursements, encashments widgets.

**Expected Result:** Widgets are visible and populated (known gap DSH-24…30: widgets may be hidden — FAIL if the page is empty).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-DASH-TC-DASH-009-finance-widgets.png`  
**Evidence / Notes:** DSH-24..30  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-010  
**Module:** DASH  
**Feature:** Executive metrics honesty  
**Role:** Super Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as SA.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/dashboard/executive`

**Steps:**
1. Open the executive dashboard.
2. List each KPI and its source.
3. Check the financial-year progress bar start month.

**Expected Result:** Every metric maps to real data. No "Satisfaction"/"Company Health" made-up scores. Financial year is July–June.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-DASH-TC-DASH-010-executive-honest.png`  
**Evidence / Notes:** DSH-14  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-DASH-011  
**Module:** DASH  
**Feature:** Redirect after login  
**Role:** Manager  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Manager account.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Sign in as Manager.
2. Open /dashboard/hr-admin directly.

**Expected Result:** Landing is the manager-appropriate page; the HR dashboard URL returns 403.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-DASH-TC-DASH-011-landing-redirect.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## EMPMGT

**Test Case ID:** TC-EMPMGT-001  
**Module:** EMPMGT  
**Feature:** Create employee  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** HR Admin; a fresh test email.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/create`

**Steps:**
1. Open /employees/create.
2. Fill name, work email, employee code (unique), department, manager, shift, work mode, salary cycle, joining date.
3. Save.
4. Open the new profile.

**Expected Result:** Employee is created with the entered values; a login is created; onboarding tasks appear; status is a valid lifecycle state.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-001-create-employee.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-002  
**Module:** EMPMGT  
**Feature:** Required validation  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** On the create form.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/create`

**Steps:**
1. Submit the form empty.
2. Submit with a duplicate employee code.
3. Submit with an existing email.

**Expected Result:** Each error appears beside its own field. Duplicate code and email are refused with clear text. No generic 500.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-002-create-required.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-003  
**Module:** EMPMGT  
**Feature:** Account number regression  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Employee edit page, bank section.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Open edit; go to the bank/KYC section.
2. Type an invalid account number (letters, 3 digits).
3. Leave Aadhaar empty or invalid.
4. Save and read every message.

**Expected Result:** The Account Number field shows only account-number errors. NEVER an Aadhaar message under or for the account number field. Each message sits beside the field it belongs to.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-003-account-number-no-aadhaar.png`  
**Evidence / Notes:** Required regression  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-004  
**Module:** EMPMGT  
**Feature:** Validate PAN  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter ABCDE12345 in PAN; save.
2. Replace with ABCDE1234F; save.

**Expected Result:** Invalid PAN is refused with a message beside the PAN field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-004-validate-pan.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-005  
**Module:** EMPMGT  
**Feature:** Validate Aadhaar  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter 1234 in Aadhaar; save.
2. Replace with 123412341234; save.

**Expected Result:** Invalid Aadhaar is refused with a message beside the Aadhaar field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-005-validate-aadhaar.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-006  
**Module:** EMPMGT  
**Feature:** Validate IFSC  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter HDFC123 in IFSC; save.
2. Replace with HDFC0001234; save.

**Expected Result:** Invalid IFSC is refused with a message beside the IFSC field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-006-validate-ifsc.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-007  
**Module:** EMPMGT  
**Feature:** Validate Phone  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter 12345 in Phone; save.
2. Replace with 9876543210; save.

**Expected Result:** Invalid Phone is refused with a message beside the Phone field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-007-validate-phone.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-008  
**Module:** EMPMGT  
**Feature:** Validate Email  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter abc@ in Email; save.
2. Replace with name@company.com; save.

**Expected Result:** Invalid Email is refused with a message beside the Email field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-008-validate-email.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-009  
**Module:** EMPMGT  
**Feature:** Validate Date of birth  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee edit open (PAN/Aadhaar/IFSC are Optional by default).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Enter a future date in Date of birth; save.
2. Replace with a date 30 years ago; save.

**Expected Result:** Invalid Date of birth is refused with a message beside the Date of birth field only; a valid value saves and persists after reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-009-validate-date-of-birth.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-010  
**Module:** EMPMGT  
**Feature:** Edit shift / manager / cycle  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee with attendance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Change the manager; save.
2. Change the shift; save.
3. Change the salary cycle from A to B; save; read the confirmation.

**Expected Result:** Manager and shift changes persist and appear in team lists. Salary cycle change must take effect from the NEXT payroll month and show a pending effective date (gap PAY-26: record if it applies immediately).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-010-edit-assignments.png`  
**Evidence / Notes:** PAY-26  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-011  
**Module:** EMPMGT  
**Feature:** Eligibility + HRA switches  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee edit, Pay tab.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Find OT eligible, Incentive eligible, Reimbursement eligible and HRA enabled controls.
2. Toggle each on; save; reload.

**Expected Result:** All four switches exist, save and persist. New employees have OT/incentive/reimbursement eligibility ON by default. If a switch is missing, FAIL (gap PAY-15, PAY-38).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-011-eligibility-hra.png`  
**Evidence / Notes:** PAY-15, PAY-38  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-012  
**Module:** EMPMGT  
**Feature:** Lifecycle status  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Test employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Change status Active to Notice to Resigned to Inactive one step at a time.
2. Try an invalid jump if the UI allows it.

**Expected Result:** Valid transitions work and are audited. Invalid states cannot be entered by tampering with the form.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-012-lifecycle.png`  
**Evidence / Notes:** EMP-09  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-013  
**Module:** EMPMGT  
**Feature:** Profile field visibility  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee own profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/my-profile`

**Steps:**
1. Open My Profile.
2. Try to change name, employee code, department, salary.
3. Change phone and address; save.

**Expected Result:** Locked fields are read-only (changes go via request). Allowed fields save. No salary shown unless permitted.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-EMPMGT-TC-EMPMGT-013-profile-fields.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-014  
**Module:** EMPMGT  
**Feature:** Email self-change risk  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** High  
**Preconditions:** Employee own profile; mail sink.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/my-profile`

**Steps:**
1. Try to change the login email to another employee’s email.
2. Try to delete the account (Settings).

**Expected Result:** Duplicate email refused. Self-delete must not be possible for the only Super Admin. Record behaviour (gap RBAC-61).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-EMPMGT-TC-EMPMGT-014-email-self-change.png`  
**Evidence / Notes:** RBAC-61  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-015  
**Module:** EMPMGT  
**Feature:** Profile change request  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Profile-change requests enabled.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/my-profile`

**Steps:**
1. Request a change to a locked field with a reason.
2. As HR open the request and approve.

**Expected Result:** Request appears for HR with old/new values; approval applies it and is audited; employee is notified.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-EMPMGT-TC-EMPMGT-015-profile-change.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-016  
**Module:** EMPMGT  
**Feature:** Employee list scope  
**Role:** Manager  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Sales Manager.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees`

**Steps:**
1. Open /employees and /employees/directory.

**Expected Result:** /employees shows direct reports only; /employees/directory shows the allowed directory fields only (no salary, no personal contact beyond policy).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-EMPMGT-TC-EMPMGT-016-employee-lists.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-EMPMGT-017  
**Module:** EMPMGT  
**Feature:** Import / export  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Staging; a small CSV of 3 employees.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/import-export`

**Steps:**
1. Download the Employees export.
2. Open /employees/import and import the 3-row CSV.
3. Re-import the same file.

**Expected Result:** Export has no bank/tax/salary columns. Import creates 3 employees; re-import creates no duplicates (matched on employee code). Both are audited.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-EMPMGT-TC-EMPMGT-017-import-export.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## ONOFF

**Test Case ID:** TC-ONOFF-001  
**Module:** ONOFF  
**Feature:** Onboarding checklist  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** New employee with onboarding tasks.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/onboarding`

**Steps:**
1. Open the checklist.
2. Complete one task.
3. Check that no task auto-completed (e.g. "Email & Slack setup").

**Expected Result:** Tasks show real state; completing one updates progress and the audit log. Tasks must not tick themselves on account creation (gap EMP-21).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONOFF-TC-ONOFF-001-onboarding.png`  
**Evidence / Notes:** EMP-21  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-002  
**Module:** ONOFF  
**Feature:** Employee onboarding view  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Employee with onboarding tasks.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/my-onboarding`

**Steps:**
1. Open My Onboarding.
2. Mark an employee-owned task done.

**Expected Result:** Only the employee’s own tasks are listed and completable; progress updates.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ONOFF-TC-ONOFF-002-my-onboarding.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-003  
**Module:** ONOFF  
**Feature:** Offboarding  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Staging employee to be offboarded; biometric code assigned.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/offboarding`

**Steps:**
1. Set exit type and a last working day in the past; process.
2. Sign in as that employee.
3. Check the biometric code is released and equipment return tasks exist.

**Expected Result:** Employee loses access; exit record exists; tasks created; audit event recorded. Director/Manager cannot do this (D1).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONOFF-TC-ONOFF-003-offboarding.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-004  
**Module:** ONOFF  
**Feature:** Probation  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee on probation with end date within 10 days.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/probation`

**Steps:**
1. Open the probation page.
2. Complete manager review then HR confirmation.

**Expected Result:** Both steps are required; status becomes Confirmed; notifications reach manager and HR.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONOFF-TC-ONOFF-004-probation.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-005  
**Module:** ONOFF  
**Feature:** Documents library  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Company document and personal document uploaded.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/documents`

**Steps:**
1. Open Documents.
2. Open a company document.
3. Acknowledge a document marked "requires acknowledgement".

**Expected Result:** Employee sees company-wide documents and own documents only. Acknowledgement is recorded once with timestamp.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ONOFF-TC-ONOFF-005-documents.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-006  
**Module:** ONOFF  
**Feature:** Document versions  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** A document with two uploaded versions.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/documents`

**Steps:**
1. Upload version 2 of a document.
2. Open the document as an employee.

**Expected Result:** Employee opens the LATEST version; older versions remain stored. (Gap EMP-50: library may open the oldest — FAIL if so.)  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONOFF-TC-ONOFF-006-doc-versions.png`  
**Evidence / Notes:** EMP-50  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-007  
**Module:** ONOFF  
**Feature:** Document access by URL  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee B’s personal document id known.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/documents/{id}/download`

**Steps:**
1. As employee A, paste the download URL of B’s document (without the valid signature).
2. Alter one character of a signed URL.

**Expected Result:** 403/invalid-signature page. No file content downloads.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ONOFF-TC-ONOFF-007-doc-url-guess.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-008  
**Module:** ONOFF  
**Feature:** KYC visibility  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** KYC document uploaded; a manager and HR with and without view_kyc_documents.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Open the Documents tab as HR with the KYC permission.
2. Repeat as a user without it.

**Expected Result:** KYC files are listed only for holders of View KYC Documents. Others cannot list, view or delete them (gap EMP-62).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ONOFF-TC-ONOFF-008-kyc.png`  
**Evidence / Notes:** EMP-62  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ONOFF-009  
**Module:** ONOFF  
**Feature:** Storage URL guess  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Leave attachment and regularisation proof uploaded by employee B (note file name).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/storage/…`

**Steps:**
1. Without signing in, open the leave attachment’s /storage/… URL in a private window.
2. Repeat for a regularisation proof and a profile photo.

**Expected Result:** Sensitive files (medical evidence, regularisation proof) are NOT retrievable without authorisation. A public photo may be acceptable by policy; record result (gap SEC-08).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ONOFF-TC-ONOFF-009-storage-guess.png`  
**Evidence / Notes:** SEC-08  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## ATT

**Test Case ID:** TC-ATT-001  
**Module:** ATT  
**Feature:** Face = IN  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee with IT shift 10:30–19:30; device/log simulator or seeded punches.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Create a Face punch at 10:28:00 (device tag set to OUT if the simulator allows).
2. Reload My Attendance.
3. Open the day timeline.

**Expected Result:** The punch shows as IN ("Biometric IN"). The raw log may show the device tag OUT flagged "direction corrected". Not late (10:28 is before 10:35 grace).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-001-face-in.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-002  
**Module:** ATT  
**Feature:** Card = OUT  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** After Face IN at 10:30.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add an ID Card punch at 19:30:00.
2. Reload.

**Expected Result:** The card punch is OUT ("Biometric OUT"). Day shows Completed. Worked = 9h 00m.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-002-card-out.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-003  
**Module:** ATT  
**Feature:** Duplicate burst IN  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Same employee/day.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add Face punches at 10:30:01, 10:30:22 and 10:30:48.
2. Add a Card punch at 19:30.
3. Open the HR raw-device view for the day.

**Expected Result:** Timeline keeps ONE IN at 10:30:48 (the latest). The earlier two are marked Duplicate and are not deleted. First IN = 10:30:48.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-003-burst-in.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-004  
**Module:** ATT  
**Feature:** Duplicate burst OUT  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Same setup.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add Card punches at 19:30:03 and 19:30:24.

**Expected Result:** Timeline keeps the OUT at 19:30:24; the 19:30:03 punch is marked Duplicate.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-004-burst-out.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-005  
**Module:** ATT  
**Feature:** IN and OUT close together  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Same setup.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add Face IN 10:30:00 and Card OUT 10:31:00.

**Expected Result:** Both are kept: IN 10:30 and OUT 10:31. They are not merged.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-005-in-out-kept.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-006  
**Module:** ATT  
**Feature:** Stray Card OUT  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** No Face IN yet today.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add a Card punch at 09:15 with no Face IN before it.
2. Then add Face IN at 10:30.

**Expected Result:** The 09:15 card is shown as ignored/stray and creates no attendance or hours. The day starts at the Face IN.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-006-stray-out.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-007  
**Module:** ATT  
**Feature:** Repeated Face IN  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Open session.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Add Face 10:30, Face 13:00, Card 19:30.

**Expected Result:** A "missing OUT" exception is flagged between the two INs; no OUT is invented. Final OUT 19:30 is kept.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-007-repeated-in.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-008  
**Module:** ATT  
**Feature:** Worked hours rule  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Face 10:30, Card 13:00, Face 14:00, Card 19:30.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Open the day.
2. Read Worked, Break and Sessions.

**Expected Result:** Worked = 9h 00m (final OUT − first IN). Break = 60m informational and NOT subtracted. Two sessions listed.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-008-worked-rule.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-009  
**Module:** ATT  
**Feature:** Late arrival IT  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** IT shift, grace 5 min.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Face IN at 10:35:59.
2. Another employee: Face IN at 10:36:00.

**Expected Result:** 10:35:59 is on time; 10:36:00 is late (late minutes counted from 10:35).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-009-late-it.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-010  
**Module:** ATT  
**Feature:** Late arrival UK  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** UK Sales shift 13:00–22:00, grace 5 min.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Face IN 13:05 then 13:06.

**Expected Result:** 13:05 on time; 13:06 late.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-010-late-uk.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-011  
**Module:** ATT  
**Feature:** Live status  
**Role:** Manager  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee with Face IN today, no OUT, within shift.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/team`

**Steps:**
1. Open Team Attendance and the manager dashboard.

**Expected Result:** Employee shows Working/LIVE in both places and in All Attendance (HR). Same status everywhere.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-ATT-TC-ATT-011-live-everywhere.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-012  
**Module:** ATT  
**Feature:** Missing checkout  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Day shift ends 18:00; Face IN 09:05, no OUT; clock at 19:05.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Wait/advance to shift end + 1 hour (19:00).
2. Run the missing-checkout job (hrms:auto-punch-out) or wait 10 minutes.
3. Open My Attendance and HR All Attendance.

**Expected Result:** Day shows Missing Checkout; check-out stays blank; worked time stops (no full shift credit); one notification to employee and manager; running the job again sends no duplicate.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-012-missing-checkout.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-013  
**Module:** ATT  
**Feature:** Overnight shift  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Night shift 22:00–06:00 employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Face IN 22:05 on day 1.
2. Card OUT 05:55 on day 2.

**Expected Result:** One attendance row on day 1 (no day 2 row); worked 7h 50m; status stays Working until 07:00 and is not closed at midnight.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-013-overnight.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-014  
**Module:** ATT  
**Feature:** Weekly off  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Saturday.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Open the Saturday row for an employee with no punches.
2. Then repeat for an employee with genuine Face/Card punches.

**Expected Result:** No punches: no attendance row and "Weekly off". With punches: real attendance ("Worked on weekly off"), missing OUT handled as normal.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-014-weekly-off.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-015  
**Module:** ATT  
**Feature:** Holiday  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** A public holiday date.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Open the holiday row with no punches.
2. Approve holiday-work for an employee and do NOT punch.

**Expected Result:** No attendance, no 09:00/18:00 times, no hours. Approval of holiday work alone creates no attendance.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-015-holiday-no-punch.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-016  
**Module:** ATT  
**Feature:** Breaks  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Employee clocked in on the web.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Start a break; end it after a few minutes; repeat once.
2. Clock out while a break is running (separate test).

**Expected Result:** Each break records start/end/duration; total break shown. Clocking out mid-break should close it (gap ATT-10: record result).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-016-breaks.png`  
**Evidence / Notes:** ATT-10  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-017  
**Module:** ATT  
**Feature:** Excess break  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Break longer than 60 min.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Create a 75-minute break.
2. Run hrms:check-excess-breaks.

**Expected Result:** Excess-break flag and a notification to employee and manager. Worked hours unchanged.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-017-excess-break.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-018  
**Module:** ATT  
**Feature:** Timeline vs stored  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Any completed day.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Open All Attendance, open the employee drawer for the day.
2. Compare In, Out, Worked, Break with My Attendance and the Team view.

**Expected Result:** All screens show identical In/Out/Worked/Break/Status for the same day.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ATT-TC-ATT-018-consistency.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-019  
**Module:** ATT  
**Feature:** Biometric guide notice  
**Role:** Employee  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** Any attendance page.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Open My Attendance and read the scrolling notice.
2. Hover over it.

**Expected Result:** Notice says Face = Biometric IN | ID Card = Biometric OUT | Regularised punches are manually corrected entries. It pauses on hover.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ATT-TC-ATT-019-guide-notice.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ATT-020  
**Module:** ATT  
**Feature:** Rebuild command preview  
**Role:** Super Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Staging copy of data with known wrong rows.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal`

**Steps:**
1. Run: php artisan attendance:rebuild-punch-timelines (no flags).
2. Read the table.
3. Confirm nothing changed in the database.

**Expected Result:** Output says DRY RUN — nothing changed and lists old vs new first IN/OUT/hours. Database unchanged. Run --apply ONLY on staging after review.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-ATT-TC-ATT-020-rebuild-preview.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## REG

**Test Case ID:** TC-REG-001  
**Module:** REG  
**Feature:** Employee submits  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** A past day with a missing OUT.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Press Regularize on the day.
2. Tick check-out, enter the right time and a reason (5+ characters).
3. Submit.

**Expected Result:** Request is Pending; HR approvers are notified (not the manager); employee sees it under My regularisations with Edit/Delete.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-REG-TC-REG-001-submit.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-002  
**Module:** REG  
**Feature:** Validation  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Regularisation form open.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Choose a future date.
2. Enter check-out earlier than or equal to check-in.
3. Leave reason empty/short.

**Expected Result:** Each is refused with a field-level message. An OUT at or before IN is refused unless the employee is on a night shift.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-REG-TC-REG-002-validation.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-003  
**Module:** REG  
**Feature:** HR approves  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Pending request from above.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Open All Attendance, the pending regularisation panel; press Review; Approve.
2. Open the employee’s day.

**Expected Result:** Day shows corrected times flagged Regularised; original punch preserved; hours recalculated; employee notified; audit event recorded. Manager cannot approve (sees "Awaiting HR").  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-003-approve.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-004  
**Module:** REG  
**Feature:** HR rejects  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Another pending request.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Reject with no comment.
2. Reject with a comment.

**Expected Result:** Comment required; after rejecting the day is unchanged; employee notified with the comment.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-004-reject.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-005  
**Module:** REG  
**Feature:** Employee edit own pending  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Pending request.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Press Edit on the pending request; change a time and the reason; save.

**Expected Result:** Updated and still Pending; audit trail shows the edit with old/new values.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-REG-TC-REG-005-edit-pending.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-006  
**Module:** REG  
**Feature:** Employee blocked after decision  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** An approved and a rejected request.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Look at Edit/Delete on both.
2. Open /attendance/my with the approved request’s edit via developer tools replay if possible.

**Expected Result:** Edit and Delete are not offered (shown Locked). A forced request is refused. Employees cannot change decided requests.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-REG-TC-REG-006-employee-locked.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-007  
**Module:** REG  
**Feature:** HR edits approved  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Approved correction 09:00–18:00.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Open Decided regularisations; Edit; change out to 17:30; leave the confirm box unticked; save.
2. Tick confirm, give a reason; save.

**Expected Result:** Without confirmation a message demands it. With it the day rebuilds then re-applies 17:30; audit shows old/new and reason.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-007-edit-approved.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-008  
**Module:** REG  
**Feature:** HR deletes approved  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Approved correction over genuine biometric punches.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Delete the approved request, confirm, give a reason.
2. Open the day; open raw device log.

**Expected Result:** Only the regularisation’s own punches disappear; the day rebuilds from genuine biometric punches; raw device punches are untouched; audit logs who/when/old/new/reason.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-008-delete-approved.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-009  
**Module:** REG  
**Feature:** Later genuine punches  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Regularised morning segment, then real Face IN, Card OUT, Face IN, Card OUT after it.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Approve a regularisation for 10:30–13:00.
2. Add genuine punches Face 14:00, Card 16:00, Face 16:30, Card 19:30.
3. Rebuild/reload and open the day drawer.

**Expected Result:** Later genuine punches remain in the timeline; total hours span the entire valid first IN to final OUT per the canonical rule. (Gap ATT-29: FAIL if later punches are dropped.)  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-009-later-punches.png`  
**Evidence / Notes:** ATT-29 critical regression  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-010  
**Module:** REG  
**Feature:** Overnight regularisation  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Day-shift employee and a night-shift employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. As day-shift employee request check-in 22:00 and check-out 06:00.
2. As night-shift employee request the same.

**Expected Result:** Day shift: refused ("check-out must be after check-in"). Night shift: accepted and spans midnight.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-REG-TC-REG-010-overnight-reg.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-011  
**Module:** REG  
**Feature:** Director fast-track  
**Role:** Director / Department Head  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Department Head.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Try the HR "Mark attendance" fast path for a Sales employee.

**Expected Result:** Not available unless explicitly granted (gap ATT-34). Record result.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-REG-TC-REG-011-dir-fast-track.png`  
**Evidence / Notes:** ATT-34  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-012  
**Module:** REG  
**Feature:** Override reject of approved  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Approved request.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Open an approved request and use Reject.

**Expected Result:** Prefer Edit/Delete(revert). If Reject flips status but corrected hours remain, FAIL (gap ATT-32).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-012-override-reject.png`  
**Evidence / Notes:** ATT-32  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-REG-013  
**Module:** REG  
**Feature:** Audit trail  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** After the regularisation tests.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/audit-log`

**Steps:**
1. Open the audit log; filter Attendance.

**Expected Result:** Events ATTENDANCE_REGULARISATION_APPROVED / UPDATED / CORRECTED / REVERTED / DELETED appear with actor, time, old and new values and reason.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-REG-TC-REG-013-audit.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## LEAVE

**Test Case ID:** TC-LEAVE-001  
**Module:** LEAVE  
**Feature:** Balances  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee with HR-register balances.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Open Leave.
2. Read CSL, MDL and Comp Off cards.

**Expected Result:** CSL = 12/year pool (negative allowed); MDL shown as shutdown days not a balance; Comp Off shown separately. No legacy CL/SL/AL types.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-001-balances.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-002  
**Module:** LEAVE  
**Feature:** Apply CSL  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Balance available.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply 2 working days with a reason.
2. Check the balance card and pending list.

**Expected Result:** Request is Pending; manager is notified; balance shows pending impact.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-002-apply.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-003  
**Module:** LEAVE  
**Feature:** Half day single  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Leave form.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply a half-day for one date.

**Expected Result:** 0.5 day requested and approved correctly.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-003-half-day.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-004  
**Module:** LEAVE  
**Feature:** Half day on range  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Leave form.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply Monday to Friday and tick half-day.

**Expected Result:** Refused or clearly limited: a half-day flag must not turn a 5-day request into 0.5 days (gap LV-23).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-004-half-day-range.png`  
**Evidence / Notes:** LV-23  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-005  
**Module:** LEAVE  
**Feature:** Overlap  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Existing pending/approved leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply for overlapping dates.

**Expected Result:** Refused with an overlap message beside the date field.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-005-overlap.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-006  
**Module:** LEAVE  
**Feature:** Holiday / weekly off / MDL  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** A range containing a public holiday, a weekend and an MDL date.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply a range including a UK public holiday.
2. Apply on an MDL date.
3. Apply across a weekend.

**Expected Result:** MDL and holiday dates cannot be taken as leave; weekends are not charged. Charged days exclude non-working days.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-006-non-working-days.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-007  
**Module:** LEAVE  
**Feature:** Insufficient balance  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Balance lower than request.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Apply for more days than the balance.

**Expected Result:** Behaviour follows policy (negative CSL allowed per register); record the warning/refusal text.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-007-insufficient.png`  
**Evidence / Notes:** R4 negative balances allowed  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-008  
**Module:** LEAVE  
**Feature:** Manager approval is final (D2)  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Pending leave of a direct report.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Approve the leave with a comment.
2. As the employee open the notification inbox.
3. As HR open the pending queue.

**Expected Result:** Status becomes Approved immediately (no second HR step). Employee receives "Leave Approved" and NEVER "Leave Rejected". The leave is not waiting for HR.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-LEAVE-TC-LEAVE-008-manager-final.png`  
**Evidence / Notes:** D2; LV-29  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-009  
**Module:** LEAVE  
**Feature:** Manager reject  
**Role:** Manager  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Pending leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Reject with a comment; then try with none.

**Expected Result:** Comment shown to employee; balance restored; employee notified "Rejected".  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-LEAVE-TC-LEAVE-009-reject.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-010  
**Module:** LEAVE  
**Feature:** Reviewer date edit  
**Role:** Manager  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Pending leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Edit the dates so they land on a public holiday; approve.

**Expected Result:** Refused: leave cannot be moved onto a holiday/MDL (gap LV-12).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-LEAVE-TC-LEAVE-010-date-edit.png`  
**Evidence / Notes:** LV-12  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-011  
**Module:** LEAVE  
**Feature:** Cancellation  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Pending and approved leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Cancel the pending request.
2. Cancel a future approved leave.

**Expected Result:** Balance restored; manager notified; audit recorded.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-011-cancel.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-012  
**Module:** LEAVE  
**Feature:** HR correction / override  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Approved leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/employees`

**Steps:**
1. Correct approved leave dates; add a balance adjustment with a reason; apply leave on behalf.

**Expected Result:** Each action is audited with reason; ledger entries append (nothing overwritten); employee notified.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-LEAVE-TC-LEAVE-012-hr-override.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-013  
**Module:** LEAVE  
**Feature:** Escalation  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Leave pending > 24 hours.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/team`

**Steps:**
1. Run hrms:escalate-leaves.
2. Check HR notifications; run again.

**Expected Result:** HR gets ONE escalation with distinct wording; second run adds none.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-LEAVE-TC-LEAVE-013-escalation.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-014  
**Module:** LEAVE  
**Feature:** Leave regularisation  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** A past absence within 30 days.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/regularisation`

**Steps:**
1. Request leave regularisation across a range that includes a holiday.

**Expected Result:** Holidays/MDL are not charged (gap LV-20: record).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-014-leave-reg.png`  
**Evidence / Notes:** LV-20  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-015  
**Module:** LEAVE  
**Feature:** Comp Off earning  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** MDL date and UK holiday; employee punches Face+Card.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Employee works the MDL date (biometric).
2. Employee works a UK holiday with an approved holiday-work request paid as overtime.

**Expected Result:** MDL work earns 1 Comp Off exactly once. A holiday paid as overtime must NOT also earn Comp Off (gap LV-13/14).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-LEAVE-TC-LEAVE-015-comp-off.png`  
**Evidence / Notes:** LV-13,14  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-LEAVE-016  
**Module:** LEAVE  
**Feature:** Team calendar privacy  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Colleagues on leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Open dashboard "colleagues on leave" and Holidays.

**Expected Result:** Only names and dates are visible; no reasons or balances of others.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-LEAVE-TC-LEAVE-016-calendar-privacy.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## ENCASH

**Test Case ID:** TC-ENCASH-001  
**Module:** ENCASH  
**Feature:** Submit encashment  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** CSL balance of at least 5.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Press Encash on the CSL card.
2. Enter 2 days and a reason; submit.

**Expected Result:** The encashment form opens (the Encash button must open its modal, gap LV-33) and a Pending request is created without affecting leave applications.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ENCASH-TC-ENCASH-001-submit.png`  
**Evidence / Notes:** LV-33  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ENCASH-002  
**Module:** ENCASH  
**Feature:** Insufficient encashment  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Balance 1 day.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Request 3 days.

**Expected Result:** Refused with message beside the days field.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ENCASH-TC-ENCASH-002-insufficient.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ENCASH-003  
**Module:** ENCASH  
**Feature:** Director or HR final (D3)  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Pending encashment.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/encashments`

**Steps:**
1. Approve as HR Admin (repeat a second case as Director).
2. Check status and CSL balance.
3. Open Finance queue.

**Expected Result:** Single approval by Director OR HR is FINAL. There is no separate Finance approval stage. CSL is debited ONCE at that approval. Finance only processes the amount in payroll.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-ENCASH-TC-ENCASH-003-final-approval.png`  
**Evidence / Notes:** D3  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ENCASH-004  
**Module:** ENCASH  
**Feature:** Reject encashment  
**Role:** Director / Department Head  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Pending encashment.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/encashments`

**Steps:**
1. Reject with comment.

**Expected Result:** Balance unchanged; employee notified.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-ENCASH-TC-ENCASH-004-reject.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ENCASH-005  
**Module:** ENCASH  
**Feature:** Payroll inclusion + arrears  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Encashment approved after the month’s run is finalised.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Approve an encashment after the original payroll is closed.
2. Open the next OPEN payroll and check inclusion.
3. Re-run/regenerate that payroll.

**Expected Result:** Included in the next open payroll with an arrears indicator and original period retained; paid exactly once even after a re-run (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-ENCASH-TC-ENCASH-005-arrears.png`  
**Evidence / Notes:** D8  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## OT

**Test Case ID:** TC-OT-001  
**Module:** OT  
**Feature:** Future OT request  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** No OT window opened.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/my`

**Steps:**
1. Submit an OT request for tomorrow with hours and reason.

**Expected Result:** Accepted as Pending with no OT window required (D6); manager notified. (Gap PAY-01: if future dates are refused, FAIL.)  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-OT-TC-OT-001-future.png`  
**Evidence / Notes:** D6; PAY-01  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-002  
**Module:** OT  
**Feature:** Manager OT approval  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Pending OT.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Approve with a comment; then reject another.

**Expected Result:** Approved OT becomes payable ONLY with a real final OUT; reject notifies the employee. Approver cannot be the requester.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-002-approve.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-003  
**Module:** OT  
**Feature:** No attendance  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Approved OT for a past date with no attendance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Approve it; open the OT record and payroll preview.

**Expected Result:** OT hours = 0 / unresolved; nothing payable (gap PAY-08).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-003-no-attendance.png`  
**Evidence / Notes:** PAY-08  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-004  
**Module:** OT  
**Feature:** Missing OUT  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Approved OT, Face IN, no OUT, after cutoff.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Check the OT record.
2. Add a real Card OUT later; reload.

**Expected Result:** No fake checkout is created; OT stays 0 until a real OUT arrives; then it settles to the real figure once.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-004-missing-out.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-005  
**Module:** OT  
**Feature:** Approved OT with real OUT  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Face 09:00, Card 20:30; threshold 9 h.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Approve OT; open the OT record.

**Expected Result:** OT = 2h 30m (worked 11h30 minus 9h), amount = hours × rate.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-005-real-out.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-006  
**Module:** OT  
**Feature:** Regularisation OT  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Regularisation that pushes the day over the threshold.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Approve it; open OT records.

**Expected Result:** OT is filed and auto-approved under the HR reviewer (documented override R9) and is reversed if the regularisation is reverted.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-OT-TC-OT-006-reg-ot.png`  
**Evidence / Notes:** R9  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-007  
**Module:** OT  
**Feature:** Holiday OT  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Approved holiday-work request (overtime pay).  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Do not punch; check OT.
2. Add Face IN 10:00 and Card OUT 15:30; reload.

**Expected Result:** No punches: no OT. With punches: OT = actual 5.5 h (not expected hours).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-OT-TC-OT-007-holiday-ot.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-008  
**Module:** OT  
**Feature:** Nexflow OT (D4)  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging Nexflow sync with a task.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/nexflow`

**Steps:**
1. Run the Nexflow sync.
2. Open OT requests.

**Expected Result:** Nexflow data creates PENDING Pulse OT only; it is not payable until a Pulse approver approves. No auto-approval with a blank reviewer.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-OT-TC-OT-008-nexflow.png`  
**Evidence / Notes:** D4  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-009  
**Module:** OT  
**Feature:** OT windows optional (D6)  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** OT windows page.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/windows`

**Steps:**
1. Submit OT as an employee for a date with no window.
2. Open a window for another date.

**Expected Result:** Windows are planning only; normal submission does not require one.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-OT-TC-OT-009-windows.png`  
**Evidence / Notes:** D6  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-OT-010  
**Module:** OT  
**Feature:** OT scope  
**Role:** Director / Department Head  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** OT from Sales and Marketing.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Open OT manage as the Department Head.

**Expected Result:** Sales OT only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-OT-TC-OT-010-scope.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## PAY

**Test Case ID:** TC-PAY-001  
**Module:** PAY  
**Feature:** Create draft Cycle A  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging; employees on Cycle A.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Open Process Payroll.
2. Choose Cycle A and the month; generate.
3. Open a payslip.

**Expected Result:** Draft payslips exist only for Cycle A employees; period 1st to last day.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-001-cycle-a.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-002  
**Module:** PAY  
**Feature:** Create draft Cycle B  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employees on Cycle B.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Generate Cycle B for the month.

**Expected Result:** Period is previous month 21st to 20th; only Cycle B employees included.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-002-cycle-b.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-003  
**Module:** PAY  
**Feature:** Submit for Finance approval  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Draft payroll.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Submit the run.
2. Check the Finance notification.
3. Open the payslip edit actions after submission.

**Expected Result:** Status becomes Pending Finance; Finance is notified; payslip lines are locked or approvals reset on edit (gap PAY-34).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-PAY-TC-PAY-003-submit.png`  
**Evidence / Notes:** PAY-34  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-004  
**Module:** PAY  
**Feature:** Finance approval step mandatory  
**Role:** Super Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/payroll-approval-policy`

**Steps:**
1. Try to delete the Finance step from the approval chain; save.

**Expected Result:** Refused: the chain must keep an active Finance step (gap PAY-33).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-PAY-TC-PAY-004-finance-step.png`  
**Evidence / Notes:** PAY-33  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-005  
**Module:** PAY  
**Feature:** Finalise and lock  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Pending Finance run.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/finance-approve`

**Steps:**
1. Approve each step.
2. After finalisation try to edit a payslip and regenerate.

**Expected Result:** Run becomes Finalised/Locked; edits and regeneration are blocked.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-005-finalise.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-006  
**Module:** PAY  
**Feature:** Draft payslip hidden from employee  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Draft payslip for the employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/my-payslips`

**Steps:**
1. Open My Payslips before approval.
2. Try the download URL of the draft payslip.

**Expected Result:** Draft payslips are not listed, downloadable or counted in YTD (gap PAY-57).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-PAY-TC-PAY-006-draft-hidden.png`  
**Evidence / Notes:** PAY-57  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-007  
**Module:** PAY  
**Feature:** Payslip content  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Finalised payslip.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/my-payslips`

**Steps:**
1. Open and download the PDF.
2. Check basic, HRA, special, OT, incentives, reimbursements, encashment, deductions, net.

**Expected Result:** Amounts match the payroll register; PDF opens; verify QR link works.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-PAY-TC-PAY-007-payslip.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-008  
**Module:** PAY  
**Feature:** HRA  
**Role:** Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee with HRA enabled and one without.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Generate payroll; compare both payslips.

**Expected Result:** HRA appears only where enabled; enabled employee shows non-zero HRA (gap PAY-38).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-008-hra.png`  
**Evidence / Notes:** PAY-38  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-009  
**Module:** PAY  
**Feature:** Statutory + LOP  
**Role:** Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee with PF/ESI/PT flags and 2 LOP days.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Generate; read deductions.

**Expected Result:** PF/ESI/PT/TDS apply only when both flags are on; LOP counted once.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-009-statutory-lop.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-010  
**Module:** PAY  
**Feature:** Eligibility flags  
**Role:** Finance  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Employee with OT approved but OT-eligible switched off.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Generate payroll.

**Expected Result:** OT excluded and a visible warning/notice explains why (no silent exclusion).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-010-eligibility.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-011  
**Module:** PAY  
**Feature:** Payroll email only approved  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Draft and finalised payslips.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Try to email a draft payslip.

**Expected Result:** Only finalised payslips can be emailed (gap PAY-36).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-PAY-TC-PAY-011-email-approved.png`  
**Evidence / Notes:** PAY-36  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PAY-012  
**Module:** PAY  
**Feature:** Reports permissions  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Finalised payroll.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/reports/payroll-register.csv`

**Steps:**
1. Download payroll register, bank transfer, PF, ESI, PT, TDS CSVs.

**Expected Result:** Files download for Finance/HR; Employees get 403; figures agree with the screen.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PAY-TC-PAY-012-reports.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## ARREARS

**Test Case ID:** TC-ARREARS-001  
**Module:** ARREARS  
**Feature:** Late-approved OT  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Original month payroll finalised; a OT for that month still pending.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/overtime/manage`

**Steps:**
1. Approve the OT after the month’s payroll is finalised.
2. Open the next OPEN payroll and generate it.
3. Find the item on the payslip/register.
4. Regenerate the same payroll and finalise.
5. Open the following month’s payroll.

**Expected Result:** Item appears in the next eligible open payroll with an arrears indicator and original period retained; paid exactly once; not paid again on regenerate or in the following month (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-ARREARS-TC-ARREARS-001-arrears-ot.png`  
**Evidence / Notes:** D8  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ARREARS-002  
**Module:** ARREARS  
**Feature:** Late-approved Reimbursement  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Original month payroll finalised; a Reimbursement for that month still pending.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/reimbursements`

**Steps:**
1. Approve the Reimbursement after the month’s payroll is finalised.
2. Open the next OPEN payroll and generate it.
3. Find the item on the payslip/register.
4. Regenerate the same payroll and finalise.
5. Open the following month’s payroll.

**Expected Result:** Item appears in the next eligible open payroll with an arrears indicator and original period retained; paid exactly once; not paid again on regenerate or in the following month (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-ARREARS-TC-ARREARS-002-arrears-reimbursement.png`  
**Evidence / Notes:** D8  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ARREARS-003  
**Module:** ARREARS  
**Feature:** Late-approved Incentive  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Original month payroll finalised; a Incentive for that month still pending.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/incentives`

**Steps:**
1. Approve the Incentive after the month’s payroll is finalised.
2. Open the next OPEN payroll and generate it.
3. Find the item on the payslip/register.
4. Regenerate the same payroll and finalise.
5. Open the following month’s payroll.

**Expected Result:** Item appears in the next eligible open payroll with an arrears indicator and original period retained; paid exactly once; not paid again on regenerate or in the following month (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-ARREARS-TC-ARREARS-003-arrears-incentive.png`  
**Evidence / Notes:** D8  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ARREARS-004  
**Module:** ARREARS  
**Feature:** Late-approved Encashment  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Original month payroll finalised; a Encashment for that month still pending.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/encashments`

**Steps:**
1. Approve the Encashment after the month’s payroll is finalised.
2. Open the next OPEN payroll and generate it.
3. Find the item on the payslip/register.
4. Regenerate the same payroll and finalise.
5. Open the following month’s payroll.

**Expected Result:** Item appears in the next eligible open payroll with an arrears indicator and original period retained; paid exactly once; not paid again on regenerate or in the following month (D8).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-ARREARS-TC-ARREARS-004-arrears-encashment.png`  
**Evidence / Notes:** D8  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## CYCLE

**Test Case ID:** TC-CYCLE-001  
**Module:** CYCLE  
**Feature:** Cycle A to B  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee on Cycle A with attendance in the transition month.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Change the salary cycle A to B; save.
2. Read the pending-change indicator.
3. Generate payroll for the current month.
4. Generate payroll for the next month.

**Expected Result:** Change is effective from the next payroll month; exactly ONE payslip per month; no overlapping or missing days; LOP counted once. No pro-rating is expected this phase.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-CYCLE-TC-CYCLE-001-cycle-A-to-B.png`  
**Evidence / Notes:** PAY-26  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-CYCLE-002  
**Module:** CYCLE  
**Feature:** Cycle B to A  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee on Cycle B with attendance in the transition month.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Change the salary cycle B to A; save.
2. Read the pending-change indicator.
3. Generate payroll for the current month.
4. Generate payroll for the next month.

**Expected Result:** Change is effective from the next payroll month; exactly ONE payslip per month; no overlapping or missing days; LOP counted once. No pro-rating is expected this phase.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-CYCLE-TC-CYCLE-002-cycle-B-to-A.png`  
**Evidence / Notes:** PAY-26  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-CYCLE-003  
**Module:** CYCLE  
**Feature:** Cancel pending change  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Pending cycle change.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{id}/edit`

**Steps:**
1. Cancel the pending change; generate payroll.

**Expected Result:** Employee remains on the original cycle; no extra payslip.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-CYCLE-TC-CYCLE-003-cancel-pending.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## NOTIF

**Test Case ID:** TC-NOTIF-001  
**Module:** NOTIF  
**Feature:** Leave submitted  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Leave submitted" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Leave submitted.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): manager. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-001-event-leave-submitted.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-002  
**Module:** NOTIF  
**Feature:** Leave decided  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Leave decided" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Leave decided.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-002-event-leave-decided.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-003  
**Module:** NOTIF  
**Feature:** OT submitted  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "OT submitted" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: OT submitted.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): manager. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-003-event-ot-submitted.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-004  
**Module:** NOTIF  
**Feature:** OT decided  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "OT decided" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: OT decided.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-004-event-ot-decided.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-005  
**Module:** NOTIF  
**Feature:** Regularisation submitted  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Regularisation submitted" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Regularisation submitted.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): HR approvers (not manager). Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-005-event-regularisation-submitted.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-006  
**Module:** NOTIF  
**Feature:** Regularisation decided  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Regularisation decided" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Regularisation decided.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-006-event-regularisation-decided.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-007  
**Module:** NOTIF  
**Feature:** Encashment submitted  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Encashment submitted" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Encashment submitted.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): Director + HR. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-007-event-encashment-submitted.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-008  
**Module:** NOTIF  
**Feature:** Missing checkout  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Missing checkout" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Missing checkout.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee + manager. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-008-event-missing-checkout.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-009  
**Module:** NOTIF  
**Feature:** Excess break  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Excess break" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Excess break.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee + manager. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-009-event-excess-break.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-010  
**Module:** NOTIF  
**Feature:** Payroll ready  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Payroll ready" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Payroll ready.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): Finance. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-010-event-payroll-ready.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-011  
**Module:** NOTIF  
**Feature:** Payslip issued  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Payslip issued" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Payslip issued.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-011-event-payslip-issued.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-012  
**Module:** NOTIF  
**Feature:** Document to acknowledge  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Document to acknowledge" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Document to acknowledge.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): employee. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-012-event-document-to-acknowledge.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-013  
**Module:** NOTIF  
**Feature:** Probation due  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Trigger "Probation due" on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Trigger the event: Probation due.
2. Sign in as each expected recipient and open Inbox.
3. Open the notification; read wording; click through.
4. Sign in as a non-recipient (another department) and check Inbox.

**Expected Result:** Recipient(s): manager + HR. Wording matches the event (e.g. approvals say Approved, never Rejected). The link opens the right page for that role. Unread badge decrements on read. Non-recipients get nothing.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-013-event-probation-due.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-014  
**Module:** NOTIF  
**Feature:** Email default off (D5)  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Staging with mail sink; fresh notification settings.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/notifications`

**Steps:**
1. Trigger a leave request and an OT request.
2. Check the mail sink.
3. Open Settings > Notifications and read the per-event mail switches.

**Expected Result:** No email is sent for non-critical events by default; in-app notification appears; switches default OFF. Turning one ON sends a mail for that event only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-NOTIF-TC-NOTIF-014-email-default.png`  
**Evidence / Notes:** D5  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-015  
**Module:** NOTIF  
**Feature:** Read / unread / badge  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** 3 unread notifications.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/notifications`

**Steps:**
1. Open one; use Mark all read.

**Expected Result:** Badge count drops accordingly and reaches 0 after mark all read.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-NOTIF-TC-NOTIF-015-badge.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-NOTIF-016  
**Module:** NOTIF  
**Feature:** Broadcast email  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Mail sink on staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/notifications`

**Steps:**
1. Use Compose & Send to a single test recipient.

**Expected Result:** Mail sent only after the explicit action; sender, recipients, time and result are audited; no event triggers it automatically.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-NOTIF-TC-NOTIF-016-broadcast.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## PERF

**Test Case ID:** TC-PERF-001  
**Module:** PERF  
**Feature:** Self-score weight (D9)  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Open performance cycle with participants.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/cycles`

**Steps:**
1. Open cycle settings and read participant weights.
2. Submit self score 5/5 and manager score 2/5.
3. Read the final score.

**Expected Result:** Self weight defaults to 0%; self score is shown for comparison only; final score uses KPI weights and authorised reviewers.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-PERF-TC-PERF-001-self-weight.png`  
**Evidence / Notes:** D9  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-002  
**Module:** PERF  
**Feature:** Self before manager  
**Role:** Manager  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Review task for a report.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/review-tasks`

**Steps:**
1. Try to submit the manager review before the employee’s self assessment.

**Expected Result:** Order enforced or clearly explained (gap PRF-07).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-PERF-TC-PERF-002-order.png`  
**Evidence / Notes:** PRF-07  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-003  
**Module:** PERF  
**Feature:** KPI normalisation  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** KPI with max score 10.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/kpi-templates`

**Steps:**
1. Score 5/10 and 50/100 on two KPIs of equal weight.

**Expected Result:** Both normalise to 50%; unscored manual KPI is not silently 0 without a flag.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-PERF-TC-PERF-003-normalise.png`  
**Evidence / Notes:** PRF-10  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-004  
**Module:** PERF  
**Feature:** Employee view  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Completed cycle.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/my`

**Steps:**
1. Open My Performance and My KPIs.

**Expected Result:** Own history, scores and feedback only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-PERF-TC-PERF-004-employee-view.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-005  
**Module:** PERF  
**Feature:** Manager feedback + promotion flag  
**Role:** Manager  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Review in progress.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/team`

**Steps:**
1. Enter feedback and set the promotion flag; save.

**Expected Result:** Both persist and are visible to HR; team history lists the review (gap PRF-09).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-PERF-TC-PERF-005-feedback.png`  
**Evidence / Notes:** PRF-09  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-006  
**Module:** PERF  
**Feature:** Performance isolation  
**Role:** Director / Department Head  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Sales Dept Head.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/employees`

**Steps:**
1. Open the performance list.
2. Open /performance/scorecard/{Marketing id}.

**Expected Result:** Sales only; Marketing scorecard denied. Increment Center must not show company-wide proposals (gap PRF-21).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-PERF-TC-PERF-006-isolation.png`  
**Evidence / Notes:** PRF-21  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-PERF-007  
**Module:** PERF  
**Feature:** Finance performance access  
**Role:** Finance  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** Finance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/performance/team`

**Steps:**
1. Type /performance/team, /performance/pip/manage, /performance/promotions/manage.

**Expected Result:** Denied (gap PRF-23).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-PERF-TC-PERF-007-finance-denied.png`  
**Evidence / Notes:** PRF-23  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## JOBS

**Test Case ID:** TC-JOBS-001  
**Module:** JOBS  
**Feature:** No auto warning letters (D10)  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Employee with 3+ late marks; staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal / /performance/warnings/manage`

**Steps:**
1. Run hrms:issue-late-warnings.
2. Open Warning letters.

**Expected Result:** A DRAFT/recommendation is created for HR review; nothing is issued or sent to the employee until HR approves.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-JOBS-TC-JOBS-001-warnings.png`  
**Evidence / Notes:** D10  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-JOBS-002  
**Module:** JOBS  
**Feature:** No auto-delete of medical evidence (D10)  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Approved leave with an attachment older than 30 days.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal / leave detail`

**Steps:**
1. Run leave:purge-attachments.
2. Open the leave record.

**Expected Result:** Attachment is flagged/listed for HR review but NOT permanently deleted without a human decision.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-JOBS-TC-JOBS-002-purge.png`  
**Evidence / Notes:** D10  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-JOBS-003  
**Module:** JOBS  
**Feature:** Absence not auto-approved leave (D10)  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Weekday with no punches and no leave.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal / leave list`

**Steps:**
1. Run hrms:flag-unauthorized-absences for that date.
2. Open leave requests and balances.

**Expected Result:** The absence is flagged and notified; it is NOT converted into approved unpaid leave until HR/manager approves.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-JOBS-TC-JOBS-003-absence.png`  
**Evidence / Notes:** D10  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-JOBS-004  
**Module:** JOBS  
**Feature:** Missing-checkout job idempotent  
**Role:** Super Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Open day past cutoff.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal`

**Steps:**
1. Run hrms:auto-punch-out 5 times and hrms:flag-missing-checkouts twice.

**Expected Result:** Data unchanged after the first run; exactly one notification each to employee and manager; check-out stays blank.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-JOBS-TC-JOBS-004-idempotent.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-JOBS-005  
**Module:** JOBS  
**Feature:** CSL auto-credit off (D7)  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** Staging; end of month passed.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal`

**Steps:**
1. Run the scheduler / leave:accrue on staging; read balances.

**Expected Result:** No automatic monthly CSL credit unless the configuration is switched on; imported/HR-register balances are never duplicated.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-JOBS-TC-JOBS-005-csl-accrual.png`  
**Evidence / Notes:** D7  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-JOBS-006  
**Module:** JOBS  
**Feature:** Scheduler list  
**Role:** Super Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Staging.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `Terminal`

**Steps:**
1. Run php artisan schedule:list.

**Expected Result:** All spec jobs appear at their IST times (missing checkouts, late arrivals, excess breaks, escalations, expiry, probation, summaries).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-JOBS-TC-JOBS-006-schedule.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## HELP

**Test Case ID:** TC-HELP-001  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-HELP-TC-HELP-001-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-002  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Manager  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Manager.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-HELP-TC-HELP-002-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-003  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as HR Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-HELP-TC-HELP-003-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-004  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Finance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-HELP-TC-HELP-004-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-005  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Director / Department Head  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Director / Department Head.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-HELP-TC-HELP-005-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-006  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Coordinator  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Coordinator.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `COO-HELP-TC-HELP-006-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-007  
**Module:** HELP  
**Feature:** Guide matches role  
**Role:** Super Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Signed in as Super Admin.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/employee-guide`

**Steps:**
1. Open Help & Guide.
2. List every page the guide describes for this role.
3. Open each link.
4. Compare menu names in the guide with the real sidebar.
5. Follow the guide to perform one real task for this role.

**Expected Result:** Every described page exists for this role; no forbidden page is documented; names match the sidebar; rules match current policy (CSL 12/year no expiry, HR-only regularisation, manager-final leave). Task is completable using only the guide.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-HELP-TC-HELP-007-guide-match.png`  
**Evidence / Notes:** HLP-03, HLP-06  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-HELP-008  
**Module:** HELP  
**Feature:** Getting started  
**Role:** Employee  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** New employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/help/getting-started`

**Steps:**
1. Open the tutorial; follow each step.

**Expected Result:** Steps match the actual UI; links open.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-HELP-TC-HELP-008-getting-started.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## UI

**Test Case ID:** TC-UI-001  
**Module:** UI  
**Feature:** Dashboard + sidebar @ 1920x1080  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1920x1080.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Set viewport 1920x1080.
2. Open /.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-001-responsive-1920x1080.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-002  
**Module:** UI  
**Feature:** My Attendance timeline @ 1920x1080  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1920x1080.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Set viewport 1920x1080.
2. Open /attendance/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-002-responsive-1920x1080.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-003  
**Module:** UI  
**Feature:** Leave form + modal @ 1920x1080  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1920x1080.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Set viewport 1920x1080.
2. Open /time-off/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-003-responsive-1920x1080.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-004  
**Module:** UI  
**Feature:** All Attendance table + drawer @ 1920x1080  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1920x1080.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Set viewport 1920x1080.
2. Open /attendance/employees.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-UI-TC-UI-004-responsive-1920x1080.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-005  
**Module:** UI  
**Feature:** Payroll process table @ 1920x1080  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1920x1080.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Set viewport 1920x1080.
2. Open /payroll/process.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-UI-TC-UI-005-responsive-1920x1080.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-006  
**Module:** UI  
**Feature:** Dashboard + sidebar @ 1366x768  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1366x768.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Set viewport 1366x768.
2. Open /.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-006-responsive-1366x768.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-007  
**Module:** UI  
**Feature:** My Attendance timeline @ 1366x768  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1366x768.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Set viewport 1366x768.
2. Open /attendance/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-007-responsive-1366x768.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-008  
**Module:** UI  
**Feature:** Leave form + modal @ 1366x768  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1366x768.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Set viewport 1366x768.
2. Open /time-off/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-008-responsive-1366x768.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-009  
**Module:** UI  
**Feature:** All Attendance table + drawer @ 1366x768  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1366x768.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Set viewport 1366x768.
2. Open /attendance/employees.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-UI-TC-UI-009-responsive-1366x768.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-010  
**Module:** UI  
**Feature:** Payroll process table @ 1366x768  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 1366x768.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Set viewport 1366x768.
2. Open /payroll/process.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-UI-TC-UI-010-responsive-1366x768.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-011  
**Module:** UI  
**Feature:** Dashboard + sidebar @ 768x1024  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 768x1024.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Set viewport 768x1024.
2. Open /.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-011-responsive-768x1024.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-012  
**Module:** UI  
**Feature:** My Attendance timeline @ 768x1024  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 768x1024.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Set viewport 768x1024.
2. Open /attendance/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-012-responsive-768x1024.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-013  
**Module:** UI  
**Feature:** Leave form + modal @ 768x1024  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 768x1024.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Set viewport 768x1024.
2. Open /time-off/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-013-responsive-768x1024.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-014  
**Module:** UI  
**Feature:** All Attendance table + drawer @ 768x1024  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 768x1024.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Set viewport 768x1024.
2. Open /attendance/employees.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-UI-TC-UI-014-responsive-768x1024.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-015  
**Module:** UI  
**Feature:** Payroll process table @ 768x1024  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 768x1024.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Set viewport 768x1024.
2. Open /payroll/process.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-UI-TC-UI-015-responsive-768x1024.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-016  
**Module:** UI  
**Feature:** Dashboard + sidebar @ 390x844  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 390x844.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Set viewport 390x844.
2. Open /.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-016-responsive-390x844.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-017  
**Module:** UI  
**Feature:** My Attendance timeline @ 390x844  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 390x844.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/my`

**Steps:**
1. Set viewport 390x844.
2. Open /attendance/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-017-responsive-390x844.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-018  
**Module:** UI  
**Feature:** Leave form + modal @ 390x844  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 390x844.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Set viewport 390x844.
2. Open /time-off/my.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-UI-TC-UI-018-responsive-390x844.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-019  
**Module:** UI  
**Feature:** All Attendance table + drawer @ 390x844  
**Role:** HR Admin  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 390x844.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/attendance/employees`

**Steps:**
1. Set viewport 390x844.
2. Open /attendance/employees.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-UI-TC-UI-019-responsive-390x844.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-UI-020  
**Module:** UI  
**Feature:** Payroll process table @ 390x844  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Browser window set to 390x844.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Set viewport 390x844.
2. Open /payroll/process.
3. Scroll the whole page; open any modal/drawer; use sidebar and filters.
4. Use a long employee name (30+ characters) if available.

**Expected Result:** No horizontal page scroll (tables may scroll inside their own container); no overlapping or clipped text; buttons tappable; modal closes; sidebar collapses on small screens; spacing and font sizes consistent.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-UI-TC-UI-020-responsive-390x844.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## ERR

**Test Case ID:** TC-ERR-001  
**Module:** ERR  
**Feature:** 403  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Employee.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/payroll/process`

**Steps:**
1. Open /payroll/process.

**Expected Result:** Friendly 403 page; no stack trace; link back to dashboard.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ERR-TC-ERR-001-403.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ERR-002  
**Module:** ERR  
**Feature:** 404  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Low  
**Preconditions:** Signed in.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/does-not-exist`

**Steps:**
1. Open /does-not-exist.

**Expected Result:** Friendly 404 page; no framework trace.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ERR-TC-ERR-002-404.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ERR-003  
**Module:** ERR  
**Feature:** 419 expired form  
**Role:** Employee  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Form open for a long time.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Open the leave form.
2. In another tab log out and in again.
3. Submit the first form.

**Expected Result:** Page-expired message with a way to reload; no data loss beyond the form; no stack trace.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ERR-TC-ERR-003-419.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ERR-004  
**Module:** ERR  
**Feature:** 422 validation  
**Role:** Employee  
**Priority:** High  
**Severity if failed:** Medium  
**Preconditions:** Any form.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Submit invalid data in 3 different forms.

**Expected Result:** Field errors appear beside the correct field; the form keeps entered values; user can correct and resubmit without reload.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-ERR-TC-ERR-004-422.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ERR-005  
**Module:** ERR  
**Feature:** 500 safe page  
**Role:** Super Admin  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Staging with debug OFF.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. With APP_DEBUG=false trigger a harmless error if a test hook exists (ask developer) or review any 500 found during testing.

**Expected Result:** Generic error page; no SQL, file path or secrets; request id shown if available.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-ERR-TC-ERR-005-500.png`  
**Evidence / Notes:** Ask developer for a safe trigger; never break production.  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-ERR-006  
**Module:** ERR  
**Feature:** Livewire/network error  
**Role:** Employee  
**Priority:** Low  
**Severity if failed:** Low  
**Preconditions:** Developer tools open.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/time-off/my`

**Steps:**
1. Set the browser offline; press Submit on a form.
2. Go back online.

**Expected Result:** A readable failure message appears; after reconnecting the user can retry without refresh loops.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** NO  
**Screenshot Name:**  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## E2E

**Test Case ID:** TC-E2E-001  
**Module:** E2E  
**Feature:** Employee full journey  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Employee test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Dashboard > Attendance (view today) > Leave balance > Submit leave > Inbox notification > Submit OT > My payslips > My Profile > Documents > Help & Guide > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-E2E-TC-E2E-001-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-002  
**Module:** E2E  
**Feature:** Manager full journey  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Manager test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Team dashboard > Team attendance > Approve leave > Approve OT > Team performance review > Notifications > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-E2E-TC-E2E-002-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-003  
**Module:** E2E  
**Feature:** HR Admin full journey  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete HR Admin test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > HR dashboard > Pending regularisations (approve one) > Leave queue > Employees (create + edit) > Onboarding > Documents > Reports > Audit log > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-E2E-TC-E2E-003-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-004  
**Module:** E2E  
**Feature:** Finance full journey  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Finance test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Finance dashboard > Process payroll (draft) > Review OT/incentives/reimbursements > Finance approval > Payslips issued > Payroll reports > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-E2E-TC-E2E-004-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-005  
**Module:** E2E  
**Feature:** Director / Department Head full journey  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Director / Department Head test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Department dashboard > Department attendance > Approve department leave > Encashment decision > Department performance > Reports > Try a Marketing URL (expect 403) > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-E2E-TC-E2E-005-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-006  
**Module:** E2E  
**Feature:** Coordinator full journey  
**Role:** Coordinator  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Coordinator test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Dashboard > Attendance Exceptions > Send a reminder > Escalate one issue > Verify only assigned employees appear > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `COO-E2E-TC-E2E-006-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-E2E-007  
**Module:** E2E  
**Feature:** Super Admin full journey  
**Role:** Super Admin  
**Priority:** Critical  
**Severity if failed:** High  
**Preconditions:** Complete Super Admin test data prepared; clean browser profile.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/login`

**Steps:**
1. Perform the journey in order: Login > Executive dashboard > Roles & Permissions > Settings (modules, menu) > Payroll approval policy > Audit log > Data management (view only) > Logout.
2. At each stop capture one screenshot.
3. Note any wait, error, or surprise.

**Expected Result:** Every stop loads without error; data shown belongs to the role’s scope; notifications arrive at the right recipients; no forbidden page is reachable; user can complete the journey using Help & Guide only.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-E2E-TC-E2E-007-journey.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## SEC

**Test Case ID:** TC-SEC-001  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** Employee  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Employee; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `EMP-SEC-TC-SEC-001-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-002  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** Manager  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Manager; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `MGR-SEC-TC-SEC-002-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-003  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** Director / Department Head  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Director / Department Head; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `DIR-SEC-TC-SEC-003-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-004  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** Finance  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Finance; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-SEC-TC-SEC-004-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-005  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** Coordinator  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as Coordinator; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `COO-SEC-TC-SEC-005-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-006  
**Module:** SEC  
**Feature:** Negative access sweep  
**Role:** HR Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Signed in as HR Admin; ids of a record outside scope noted.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/`

**Steps:**
1. Change the employee id in /employees/{id}/profile to one outside scope.
2. Open another employee’s payslip download URL.
3. Open an HR-only URL (/settings/roles) and a Finance-only URL (/payroll/process) not allowed for the role.
4. Open a disabled module route.
5. Copy a signed document URL into a different user’s browser.

**Expected Result:** Every attempt returns 403/404/invalid signature. No other person’s sensitive data appears in the page, title, error text or downloaded file.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-SEC-TC-SEC-006-negative-sweep.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-007  
**Module:** SEC  
**Feature:** ADMS endpoint  
**Role:** Super Admin  
**Priority:** Critical  
**Severity if failed:** Critical  
**Preconditions:** Staging only; known device serial; do NOT test on production.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/iclock/cdata`

**Steps:**
1. From an unauthorised machine send a punch to /iclock/cdata with a valid serial but without a device key (use your security tester tool).

**Expected Result:** Request is refused (authentication/allowlist/throttle). No attendance is written. (Gap SEC-05 — FAIL if accepted.)  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-SEC-TC-SEC-007-adms.png`  
**Evidence / Notes:** SEC-05  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-SEC-008  
**Module:** SEC  
**Feature:** Login email takeover  
**Role:** HR Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** HR and Finance test users.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/employees/{finance id}/edit`

**Steps:**
1. As a user with manage employees but lower authority, try to change an HR Admin or Finance login email.

**Expected Result:** Refused: identity edits are limited to the actor’s level (gap RBAC-79).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `HR-SEC-TC-SEC-008-email-takeover.png`  
**Evidence / Notes:** RBAC-79  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

## AUDIT

**Test Case ID:** TC-AUDIT-001  
**Module:** AUDIT  
**Feature:** Audit coverage  
**Role:** Super Admin  
**Priority:** High  
**Severity if failed:** High  
**Preconditions:** After performing the earlier tests.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/audit-log`

**Steps:**
1. Filter by category: Authentication, Attendance, Leave, Payroll, Settings, Permissions.
2. Open a few entries.

**Expected Result:** Entries exist for logins, approvals, edits, role changes, payroll steps, with actor, time, old/new values, reason. Entries cannot be edited or deleted.  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `SA-AUDIT-TC-AUDIT-001-coverage.png`  
**Evidence / Notes:**   
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---

**Test Case ID:** TC-AUDIT-002  
**Module:** AUDIT  
**Feature:** Audit visibility  
**Role:** Finance  
**Priority:** Medium  
**Severity if failed:** Medium  
**Preconditions:** Finance.  
**Test Data:** See Test-Data-Matrix  
**Starting URL/Page:** `/settings/audit-log`

**Steps:**
1. Open the audit log; open /payroll/audit-trail.

**Expected Result:** Finance sees only payroll audit if allowed; general audit log limited to SA/HR (gap AUD-08).  
**Actual Result:**  
**Status:** NOT TESTED  
**Screenshot Required:** YES  
**Screenshot Name:** `FIN-AUDIT-TC-AUDIT-002-visibility.png`  
**Evidence / Notes:** AUD-08  
**Bug ID:**  
**Tester:**  
**Test Date:**  
**Browser:**  
**Viewport:**

---
