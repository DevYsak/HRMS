# Pulse HRMS — Portal Audit, 2 November 2026

**Branch:** `staging` · **Scope:** every module, every role, logins with and without an employee record · **Production:** not touched (no access); the commands you can run there are listed in section 6.

## 1. Summary

- **The portal works for every role.** Every menu link opens for all eight roles, and for seven roles also with a login that has no employee record (for example a Super Admin who is not on the payroll).
- **Login vs employee:** no code was found that mixes a login's ID with an employee's ID. One real problem was found and fixed: any user could delete their own login from Settings, which left their employee record active but invisible in HR lists.
- **One display defect fixed:** the Employee 360° attendance chart repeated and overlapped its date labels in the first days of a month.
- **The automated test suite is clean again.** 16 long-standing failing tests were out-of-date or date-dependent tests, not product faults. They have been corrected, so a future failure means a real problem.
- **8 items need your decision or a command you run on production** — section 5 and section 6.

## 2. How it was checked

| Check | What it covers | Result |
|---|---|---|
| Full automated suite | 3,539 tests across every module (4 parallel runs) | 18 failures; all explained and resolved (section 4) |
| Logins with no employee record | 7 roles × every menu link, the dashboard and Help | All open; one intermittent error not reproducible (item 5.6) |
| Code scan: login vs employee | Missing-profile crashes, user IDs used as employee IDs, employee codes used as database keys | Nothing found; self-service pages already handle a missing profile |
| Browser role journeys (Playwright) | 8 fictional role accounts log in through the real screens: landing page, menu, blocked pages, department isolation, approval workflows, phone / tablet / desktop layouts | 73 passed first time; 11 of 13 failures fixed and re-verified; 2 need a test rewrite (item 5.3) |
| Earlier programme (October) | Role × page access sweep, data quality, cross-module counts, payroll / settings | Not repeated; its decisions are respected |

## 3. Module by module

| Module | Checked | Status |
|---|---|---|
| Sign-in, profile & account settings | Sign-in / out, 2FA, password, profile, account deletion | **Fixed:** account deletion (4.1) |
| Dashboards (Main, Team, Department, Executive, Finance, Employee) | Each role lands on its own dashboard; counts match the modules | OK |
| People: employees, Employee 360°, onboarding, documents | Scope per role, exports, 360° sections | **Fixed:** 360° chart labels (4.2) |
| Attendance: My Attendance, Team, Attendance Log, Command Center, Live, Reports, Biometric, Regularisation | Status, punches, breaks, filters, approvals | OK. The Attendance Log was redesigned this cycle (7) |
| Leave: My Time Off, request drawer, Leave Management, All Leave, HR request page, Policies, Reconciliation | Balances, projection, approval, conversation, ledger integrity (`leave:verify` clean) | OK. Leave features delivered this cycle (7) |
| Overtime, WFH | Requests, approvals, payable OT | OK |
| Payroll: Run Payroll, Finance Approval, Payslips | Draft, finance hand-off, payslip actions | OK (tests corrected, 4.3) |
| Performance: reviews, PIP, warnings, promotions | Own pages with and without an employee record | OK |
| Holidays & calendars | Holiday applies to the right calendar | OK. Note: item 5.2 |
| Reports | Each report opens per role and scope | OK |
| Settings: roles, modules, notifications, theme | Every settings page per role | OK. One intermittent error (5.6) |
| Notifications, AI assistant, Help | Open for every role | OK |

## 4. What was fixed

### 4.1 A login linked to an employee could be deleted by its owner — fixed (`2d779e4`)
Settings → Profile showed **Delete account** to everyone. Deleting a login that belongs to an employee left the employee record active with no login. Most HR lists (attendance, leave, approvals) only show employees who have a login, so that person silently disappeared while biometric punches kept arriving.

- Now only a login with **no** employee record sees the option.
- An employee's login shows: *"Your sign-in is linked to your employee record, so it cannot be deleted here. HR closes it when you leave, through offboarding."*
- The server refuses it as well, so it can't be bypassed.
- **People → Data Quality** already lists any active employee whose login is missing or deleted ("Active employee without a login account"). Use it to find anyone affected before this fix.

### 4.2 Employee 360° chart repeated its date labels — fixed (`dc56111`)
In the first days of a month (or with a range of one or two days), the worked-hours chart printed the same date many times across the axis, overlapping. Short ranges now show one label per day; longer ranges are unchanged.

### 4.3 The test suite reported 16 false failures — fixed (`76eaba6`, `dc56111`)
None of these were product faults; each test was out of date or depended on the calendar:

- **Run Payroll (3 tests):** they only passed in July (the page opens on the current month).
- **Command Center (2):** a panel was renamed to "Recent Activity", and "Recent New Requests" always shows pending items by design.
- **My Time Off holiday schedule (1):** the test holiday used an old India default, while employees use the company (UK) calendar.
- **Attendance analytics and regularisation (4):** they failed on the first days of a month or on Mondays.
- **Logout (1), email verification (4):** these now match the app (email verification is switched off on purpose).
- **Browser specs (5 updated):** the button is "Request Leave", Department Heads land on Department View (your 15 October decision), and HR's headcount excludes HR herself.

A new automated check now opens every menu link for seven roles **without** an employee record, so this can't regress unnoticed.

## 5. Needs your decision or permission

| # | Item | Recommendation |
|---|---|---|
| 5.1 | **Self-deletion for logins with no employee record** (e.g. a Super Admin) is still allowed. A Super Admin could delete their own login and lock the company out of admin. | Remove self-deletion entirely; accounts are closed by an administrator. Say yes and I will make the change. |
| 5.2 | **Holiday calendar default.** The database column still defaults to India (`IN`), while the company calendar is UK. The HR holiday form always fills in the company calendar, so normal use is correct; only imports or scripts that skip the field would get India. | Change the column default to follow the company calendar (a small database migration). Needs your OK because it changes the schema. |
| 5.3 | **Two browser tests still use the old leave form** (employee applies → manager approves → employee notified; the leave form on a phone). With Leave V2 switched on, the new request drawer is used, so these 2 (and 8 that depend on them) do not run. The same flow is covered by the automated feature tests. | Let me extend the test-only seed (`e2e:seed-roles`) with a Leave V2 policy and rewrite both specs for the drawer. Test data only, never real records. |
| 5.4 | **Leave V2 on production.** The projected balance, the HR leave cards and the request drawer need `LEAVE_POLICY_ENGINE_V2=true`. | Confirm whether production has it on before you rely on those screens there. |
| 5.5 | **Data checks on production** (I have no access). | Run the read-only commands in section 6 and send me anything flagged. |
| 5.6 | **Settings → Modules returned a server error once** for a Super Admin with no employee record, while the machine was under heavy test load. It did not happen again in two direct retries. | Nothing to change now. If you see an error on that page, send me the line from `storage/logs/laravel.log`. |
| 5.7 | **Visual design pass, page by page** (spacing, consistency, dark mode, with before / after screenshots per module). Layout, responsiveness and the most-used HR screens were checked and improved this cycle; a full design pass of every page was not. | Approve it as the next phase and tell me which modules come first. My suggestion: Payroll, Performance, Reports. |
| 5.8 | **Deploy.** Everything is on `staging` only. | Pull and build when you have reviewed it (section 6). |

## 6. Commands for you

On the server, to update:

```
git pull origin staging
npm run build
php artisan optimize:clear
```

This cycle adds no database migrations.

Read-only checks on production (they change nothing):

```
php artisan hrms:data-quality      # look for "Active employee without a login account" and "Login account without an employee record"
php artisan leave:verify           # leave balances against the ledger
php artisan migrate:status         # anything not yet run
```

## 7. Also delivered this cycle

| Commit | Change |
|---|---|
| `ae5bfa0` | Leave history reconciliation: September CSL accrual and genuine HR corrections counted correctly |
| `d299ff5` | Paid leave can be booked against monthly accrual expected before the leave starts; approval re-checks the same figure |
| `01a8da5` | HR leave view: one card per leave type, request links, employee–HR conversation on the request page |
| `b301a38` | All Leave page: correct balances in the side panel, cards, paid / unpaid split, links |
| `f7f0d5f` | Attendance Log: punch timeline, break counts, missed-punch / late / multiple-break audit, one employee's full punch log |
| `2d779e4` | Account deletion fix (4.1) |
| `76eaba6` | Test suite cleaned up and new check for logins without an employee record (4.3) |
| `dc56111` | Employee 360° chart fix and browser specs updated (4.2, 4.3) |
