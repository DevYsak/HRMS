# Stabilization checkpoint — 4 October 2026

Branch `staging`, on top of `1af1393`. The working tree holds three bodies of uncommitted work:

- the Conexus CSL/MDL/Comp Off leave policy and the register reconciliation;
- the employee dashboard redesign;
- the v3.1 compliance pass, first round.

This file records the full-suite result taken **before** the four prepared patch batches were applied. It also records how each failure was classified.

## A. Baseline full-suite result (before the four batches)

Command: `php -d memory_limit=-1 vendor/bin/pest --compact` against `hrms_test`.

| Total | Passed | Failed | Skipped | Assertions | Duration |
|---|---|---|---|---|---|
| 2032 | 2012 | 17 | 3 | 6400 | 3053.10 s (≈ 51 min) |

For reference, the run taken this morning before the compliance pass was: 1968 total, 1952 passed, 13 failed, 3 skipped, 2666.82 s.

## B. Failure classification

| # | Test | Classification | Cause |
|---|---|---|---|
| 1 | AttendanceCommandCenterTest › the command center shows pending counts per request type | BASELINE_EXISTING | Also fails in this morning's pre-pass run and in the documented Aug-2026 baseline. The Command Center markup differs from the assertion. |
| 2 | AttendanceCommandCenterTest › the status filter shows decided history, not just pending | BASELINE_EXISTING | Same as #1. |
| 3 | Auth\AuthenticationTest › users can logout | BASELINE_EXISTING | Logout redirects to `/`, but the test expects `/welcome`. This is a config mismatch. |
| 4 | Auth\EmailVerificationTest › email verification screen can be rendered | BASELINE_EXISTING | Email verification is disabled in `config/fortify.php`, so the route is not defined. |
| 5 | Auth\EmailVerificationTest › email can be verified | BASELINE_EXISTING | Same as #4. |
| 6 | Auth\EmailVerificationTest › email is not verified with invalid hash | BASELINE_EXISTING | Same as #4. |
| 7 | Auth\EmailVerificationTest › already verified user visiting verification link… | BASELINE_EXISTING | Same as #4. |
| 8 | LeaveYearBoundaryTest › an ordinary current leave still lands in the current year | FLAKY_ENVIRONMENT (date-dependent, pre-existing) | The test books leave for "today" without fixing the clock. On a weekend day the leave counts as 0 working days and the ledger refuses a 0-day debit. It passes Monday–Friday. |
| 9 | MyTimeOffPageTest › leave center shows forecast, holiday planner and policy explorer | BASELINE_EXISTING | The "Holiday Planner" text the test looks for is not rendered. Pre-existing. |
| 10 | Payroll\ProcessPayslipActionsTest › selectAllVisible fills selected with every payslip | BASELINE_EXISTING | A pre-existing payroll UI bug, documented in the baseline. |
| 11 | Payroll\ProcessPendingFinanceAffordanceTest › a user who can approve finance sees a real link | BASELINE_EXISTING | Pre-existing, documented in the baseline. |
| 12 | Payroll\ProcessPendingFinanceAffordanceTest › a run-payroll-only user sees a plain status badge | BASELINE_EXISTING | Same as #11. |
| 13 | Settings\ProfileUpdateTest › user can delete their account | BASELINE_EXISTING | SoftDeletes: `$user->fresh()` returns the soft-deleted row. Pre-existing. |
| 14 | AttendanceModeTest › regularising only the check-out keeps the recorded check-in | EXPECTED_TEST_CHANGE (also date-dependent) | The test corrects the **7th of the current month**, which is still in the future on the 1st–6th (today is the 4th). The compliance pass now refuses punch corrections for days that have not happened (spec §3.2; audit row A-13). Fix: the test uses a past date. |
| 15 | BiometricReleaseTest › offboarding an employee past their last working day releases their card | EXPECTED_TEST_CHANGE | The test sets `OffboardingManager::$selectedEmployeeId` directly. That property is now `#[Locked]`: setting it from the client was the tampering hole the pass closed. Fix: the test calls `selectEmployee()`, the real UI path. |
| 16 | Security\FirstLoginPasswordTest › HR cannot force a Super Admin into a reset | EXPECTED_TEST_CHANGE | `EmployeePolicy::update` now refuses HR on a Super Admin's record, so the edit page 403s at mount and the follow-up `call()` meets an empty snapshot. The protected outcome still holds (the Super Admin is untouched), now enforced earlier. Fix: assert the mount is forbidden. |
| 17 | Security\RoleEscalationTest › HR cannot change a Super Admin's role | EXPECTED_TEST_CHANGE | Same cause and fix as #16. |

Totals:

- **BASELINE_EXISTING: 12**
- **FLAKY_ENVIRONMENT: 1**
- **EXPECTED_TEST_CHANGE: 4**
- **NEW_REGRESSION: 0**
- **REAL_BUG introduced: 0**

Several BASELINE_EXISTING failures are themselves real, older bugs (#1–2, #9–13), and #3–7 are configuration mismatches. None was introduced by this work.

Visible behaviour change behind #16–17: HR can no longer open or edit the **Super Admin's** employee record. Before, HR could change its login email and then take the account over through a password reset. The Super Admin edits their own record.

## C. Batches applied after the baseline

The four prepared batches were applied only after section A was recorded. Before the commit, an adversarial review of the compliance pass and of these four batches produced further confirmed findings. These were fixed in batches 5–8. Every batch is a stabilization fix to existing behaviour; none adds a new feature.

| Batch | Scope | Findings fixed |
|---|---|---|
| 1 — documents | `DocumentPolicy`, dashboard + sidebar acknowledgement counts | **HIGH:** managers and directors keep authorized download access to the PIP, promotion and warning-letter documents they upload, within reach and never for their own record. **MEDIUM:** acknowledgement counts include only documents addressed to, or visible to, that employee. |
| 2 — attendance | All Attendance review modal, engine/biometric sync | **LOW:** a concurrent decision on All Attendance shows a conflict message instead of an error. **LOW:** a stale review modal cannot reverse or overwrite another approver's decision. **LOW:** sync never overwrites approved corrected punches, but still writes the checkout of a status-only (half-day) regularisation. |
| 3 — payroll / KPI / AI | Effective-date salary rows, paid employment states, Cycle B end to end, Finance net total in ₹, historical import (past periods only), KPI designation targeting, AI leave context | Payroll, Cycle B, salary-date, employee-status, finance-total, KPI and AI leave fixes. |
| 4 — documents | Version numbering, once-only expiry notices | Version numbers no longer repeat. Each expiry is announced once (`documents.expiry_notified_for`). The pre-existing static `Document::expiringSoon()` fatal is fixed. |
| 5 — review round 2 | Payroll revert, increments, Finance approval, encashment, import, profile, probation/status, carry-forward, offboarding, purge | Reverting to draft keeps inclusions. The approver's own raise is held for another approver. Legacy finance buttons are shown only to Finance. Encashment decisions are stage-aware. Import updates go through `EmployeePolicy::update`. Profile financial values are masked and own-record edits refused. No self probation or status change. No self carry-forward or bulk add-on. Settlement inputs are shown only to payroll staff. Purge is Super Admin only. |
| 6 — scheduler | Onboarding/offboarding/new-hire notices, auto-absence clean-up, attendance summary, probation digest | Sent-once facts live on the employee (`*_notified_at`), not in pruned notification rows. Absence clean-up never cancels a deduction a payslip holds. `--month` is parsed exactly. The overdue digest says "overdue". Missed new-hire check-ins are caught up once. |
| 7 — dashboards | `/` role landing, clock-in work mode, Director scope, MDL today card, Manager team | Managers, Finance and Directors land on their own full page; the embed broke the app-shell grid. WFH requires an approved request on the card and on the server. A scoped Director cannot open the company-wide dashboard. An MDL day reads "MDL shutdown", not "Absent". The team view counts people working now. A stale reject shows a message. |
| 8 — payroll / KPI follow-up | Run membership, KPI targeting, seeder, salary-cycle data, historical import | A run pays people employed in its cycle: never a later joiner, and a joined hire still marked onboarding. Role templates reach everyone again. The KPI form picks each target from its own table and clears a stale one. The master seeder writes `cycle_a`. `hrms:normalize-salary-cycles` lists employees whose run differs from their form and moves them only with `--sync-from-form`. Historical import judges "already paid" by the pay period's own end. |

## D. Targeted test results per batch

Each batch ran on an isolated database (`hrms_test_w1`–`w7`). A batch did not proceed while a test it introduced was failing.

| Batch | First run | Fixes, then re-run |
|---|---|---|
| 1 | 48 passed | — |
| 2 | 117 passed, 1 failed | The new engine-sync fixture lacked `original_check_in`. Re-run: 17/17. |
| 3 | 196 passed, 4 failed | 3 are BASELINE_EXISTING (Process* payroll UI). The Cycle B test asserted a tampered cycle at the wrong step; it now asserts the 422 on `updatedCycle`. Re-run: 6/6. |
| 4 | 77 passed, 1 failed | A REAL_BUG, pre-existing: a static call to the `Document::expiringSoon()` scope. Fixed to `Document::query()->expiringSoon(30)`. Re-run: 2/2. |
| 5 | a 22/1 · b 154/3 · c 144/6 · d 139/0 (passed/failed) | b: the 3 are BASELINE_EXISTING. a: an `&` must be compared unescaped (`assertSee(…, false)`). c: EXPECTED_TEST_CHANGE — the import actor is now an HR Admin, because updates go through `EmployeePolicy::update`. Re-runs: 11/11 and 48/48. |
| 6 | 37 passed | — |
| 7 | 156 passed, 2 failed | One is BASELINE_EXISTING (logout). The other was a new test asserting a sidebar link Directors never get (Directors hold `approve_finance` and receive the Finance sidebar); the assertion now checks the page. Re-run: 10/10. |
| 8 | Payroll dir 158 passed, 3 failed · Performance + seeder + salary + round-two 57 passed | The 3 are BASELINE_EXISTING. EXPECTED_TEST_CHANGE: payroll fixtures that are paid in a past month now state a joining date, because the factory's random joining date (up to today) could fall after the run month. |

## E. Affected-module results (full suite, after every batch)

Almost every module was touched, so the whole suite ran. It was split into three shards running concurrently on `hrms_test_w4`, `w5` and `w6`.

| Run | Total | Passed | Failed | Skipped | Wall time |
|---|---|---|---|---|---|
| Run 1 | 2079 | 2061 | 15 | 3 | ≈ 22 min (shards 1174 s, 1301 s, 1196 s) |
| Run 2 (after the isolation fix below) | 2079 | **2062** | **14** | 3 | ≈ 21 min (shards 1154 s, 1261 s, 1155 s) |

Run 1 failures beyond the baseline: two `HrFormatReportsTest` tests. Classification: **FLAKY_ENVIRONMENT, pre-existing**.
- Cause: `AttendanceSetting` caches the weekly-off days in a static property, and the rollback between tests does not clear it. When `LeaveAttachmentAndMoreInfoTest` ran first (the new shard order did this), Saturday stayed a weekly off and the report shifted by a day.
- Reproduced by running the two files in that order. Fixed in `tests/Pest.php`: the cache is flushed before every feature test. The same order then gave 17/17.

Run 2 failure beyond the baseline: `SettingsAndDocumentAccessTest › payslip documents are not visible to a Director`. Classification: **FLAKY_ENVIRONMENT, caused by running shards in parallel**.
- `Storage::fake('local')` uses one shared folder and every process wipes it, so a concurrent shard deleted the file under this test.
- It passed in run 1 and passes 11/11 alone. A serial run is not exposed to this.

Both runs show **no NEW_REGRESSION and no REAL_BUG introduced**.

## F. Stabilization commit

`288978c` on `staging`: "fix: v3.1 compliance pass and stabilization checkpoint". It contains 204 files: code, migrations and tests only. This record is committed separately. It contains no new self-service, leave-policy or import/export feature work.

## G. Remaining known baseline failures

| Test | Classification |
|---|---|
| AttendanceCommandCenterTest × 2 | BASELINE_EXISTING |
| Auth\AuthenticationTest › users can logout | BASELINE_EXISTING (config) |
| Auth\EmailVerificationTest × 4 | BASELINE_EXISTING (verification disabled) |
| MyTimeOffPageTest › Holiday Planner | BASELINE_EXISTING |
| Payroll\ProcessPayslipActionsTest › selectAllVisible | BASELINE_EXISTING |
| Payroll\ProcessPendingFinanceAffordanceTest × 2 | BASELINE_EXISTING |
| Settings\ProfileUpdateTest › delete account | BASELINE_EXISTING |
| LeaveYearBoundaryTest › ordinary current leave | FLAKY_ENVIRONMENT (fails on weekends; 4 Oct 2026 is a Sunday) |

That is 12 baseline failures plus 1 date-dependent failure. The 4 EXPECTED_TEST_CHANGE failures from section B now pass.

## Deployment notes for this commit (not deployed)

1. `php artisan migrate`: the 8 additive migrations listed in the commit message.
2. `php artisan hrms:normalize-salary-cycles --dry-run`, then run it without `--dry-run`. This maps any legacy `'A'`/`'B'` keys. It also lists employees whose payroll run differs from the cycle on their form. Move those with `--sync-from-form` only after Finance settles each one's transition month: Cycle A pays the 1st to month end, Cycle B pays the 21st to the 20th.
3. `php artisan hrms:review-auto-absences` (preview), then `--apply` once HR agrees.
4. `php artisan leave:conexus-reconcile`: user-run only, per the Conexus policy.

Rollback: `git revert 288978c`, then `php artisan migrate:rollback --step=8`. All 8 migrations add columns or tables only.

## Open policy questions (not fixed here)

- **Proration:** pay is not prorated for a mid-cycle joiner or leaver. A joiner inside the cycle is paid the full structure; this predates this work. HR and Finance must choose a basis (calendar or working days).
- **Director finance access:** the seeded Director role holds `approve_finance`, so a department-scoped Director keeps the company-wide Finance dashboard and payroll sign-off. Whether a scoped Director should keep finance approval is a role decision.
