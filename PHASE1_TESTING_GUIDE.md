# Phase 1 CSV — Manual Testing Guide

**Base URL:** `http://127.0.0.1:8000`  
**Server:** Already running (`php artisan serve`)

---

## Before You Start — What to Have Open

1. Browser tab at `http://127.0.0.1:8000` (logged in as Super Admin)
2. This file open in VS Code as your checklist
3. A second browser tab (optional) — log in as a **Finance** role user to test role-scoped dashboards

---

## TEST 1 — Incentives Module

**URL:** `http://127.0.0.1:8000/payroll/incentives`

### Step-by-step:
1. Navigate to the URL — page should load with an empty table and an **"Add Incentive"** button.
2. Click **"Add Incentive"** → modal should open.
3. Fill in:
   - Employee: pick any active employee
   - Title: `Q1 Performance Bonus`
   - Amount: `5000`
   - Month: select current month (2026-04)
   - Description: `Exceeded sales target`
4. Click **Submit** → toast "Incentive request submitted for approval" should appear.
5. The table should now show the new row with status **PENDING**.
6. Click **Approve** on the row → status changes to **APPROVED**.
7. Test **Reject** by creating another incentive and clicking Reject instead.
8. Test filters: change the status dropdown and month filter — table updates live.

✅ Pass / ❌ Fail: _______________

---

## TEST 2 — Reimbursements Module

**URL:** `http://127.0.0.1:8000/payroll/reimbursements`

### Step-by-step:
1. Navigate to URL — empty table with "Submit Claim" button.
2. Click **Submit Claim** → modal opens.
3. Fill in:
   - Employee: pick any
   - Title: `Client Dinner`
   - Amount: `1200`
   - Expense Date: today's date
   - Category: `Food`
   - Month: 2026-04
   - Receipt: upload any image or PDF (optional)
4. Click **Submit Claim** → toast appears, modal closes.
5. Row shows in table with status **PENDING**.
6. Click **Approve** → status changes to **APPROVED**.
7. Try uploading a file > 5MB → should show a validation error.

✅ Pass / ❌ Fail: _______________

---

## TEST 3 — Executive Dashboard

**URL:** `http://127.0.0.1:8000/dashboard/executive`

### What to check:
- **Active Employees** — should show `25` (matching your DB data).
- **Present Today** — count of employees who clocked in today.
- **Pending Leaves / Pending OT** — live counts from DB.
- **Payroll Status** — Cycle A and B both show "PENDING" (not run yet) — correct.
- **Performance avg rating** — shows `—` if no reviews submitted yet — correct.
- **Headcount by Department** — grid of department cards with counts.

✅ Pass / ❌ Fail: _______________

---

## TEST 4 — Finance Dashboard

**URL:** `http://127.0.0.1:8000/dashboard/finance`

### What to check:
- **Cycle A Payout / Cycle B Payout** — both show `—` with "NOT RUN" badge (no payroll run yet).
- **Approved Incentives** — should now show the incentive you approved in Test 1.
- **Reimbursements** — should show the reimbursement you approved in Test 2.
- **OT Financial Summary** — shows pending OT count and approved OT total.
- Change the **month filter** at the top right → numbers update.

✅ Pass / ❌ Fail: _______________

---

## TEST 5 — Cycle A vs Cycle B Payroll Split

**URL:** `http://127.0.0.1:8000/payroll/process`

### Step-by-step:
1. Navigate to the URL.
2. Look for a **Cycle** selector (dropdown showing `cycle_a` / `cycle_b`).
3. With **Cycle A** selected, click **"Run Payroll"** → should only process employees with `salary_cycle = cycle_a`.
4. The payslip table below shows only Cycle A employees.
5. Switch to **Cycle B** in the dropdown → run again OR check if it shows a separate run.
6. Navigate to Finance Dashboard → Cycle A payout total should now show a number.

> **Note:** If some employees don't have a `salary_cycle` set, they'll be skipped. You can assign `cycle_a` or `cycle_b` to a test employee via the Employee Edit page.

✅ Pass / ❌ Fail: _______________

---

## TEST 6 — Public Holidays Database

Run via tinker in terminal:

```bash
php artisan tinker --execute "App\Models\PublicHoliday::all()->map(fn(\$h) => [\$h->country, \$h->date->format('d M'), \$h->name])->values();"
```

**Expected:** 19 rows — 11 Indian holidays + 8 UK holidays for 2026.

Test the `isHoliday()` helper:
```bash
php artisan tinker --execute "echo App\Models\PublicHoliday::isHoliday(now()->setDate(2026,12,25),'IN') ? 'Christmas IS a holiday in India' : 'NOT a holiday';"
```

```bash
php artisan tinker --execute "echo App\Models\PublicHoliday::isHoliday(now()->setDate(2026,8,15),'IN') ? 'Independence Day confirmed' : 'NOT found';"
```

✅ Pass / ❌ Fail: _______________

---

## TEST 7 — December Mandatory Days

```bash
php artisan tinker --execute "App\Models\DecemberMandatoryDay::where('year',2026)->get()->map(fn(\$d) => \$d->date->format('d M Y'))->values();"
```

**Expected:** Dec 26, 27, 28, 29, 30, 31 — 2026 (6 rows).

Test the `isMandatory()` helper:
```bash
php artisan tinker --execute "echo App\Models\DecemberMandatoryDay::isMandatory(now()->setDate(2026,12,26)) ? 'Dec 26 IS mandatory' : 'Not mandatory';"
```

✅ Pass / ❌ Fail: _______________

---

## TEST 8 — Attendance Break Tracking

**URL:** `http://127.0.0.1:8000/attendance/my`

*(Log in as an employee role user for best experience)*

1. **Clock In** first (requires location permission in browser) — or if already clocked in today, use existing record.
2. Once clocked in, you should see two break buttons:
   - **"Starting Break"** button (grey)
3. Click **"Starting Break"** → toast "Break started at HH:MM".
4. Button changes to **"Ending Break"** (orange + pulsing animation).
5. Click **"Ending Break"** → toast shows duration and total break time.
6. You can start another break again (multi-break support).

✅ Pass / ❌ Fail: _______________

---

## TEST 9 — Regularisation Request Modal

**URL:** `http://127.0.0.1:8000/attendance/my`

1. Scroll down or look for the **"Regularisation Request"** ghost button.
2. Click it → modal opens with: Date, Actual Check-in, Actual Check-out, Reason fields.
3. Fill in:
   - Date: any past date
   - Check-in: `09:00`
   - Check-out: `18:00`
   - Reason: `System glitch, forgot to clock in` (min 10 chars)
4. Click **Submit Request** → toast confirmation appears.
5. Check DB:
   ```bash
   php artisan tinker --execute "App\Models\AttendanceRegularisation::latest()->first();"
   ```
   Should show `status: pending`.

✅ Pass / ❌ Fail: _______________

---

## TEST 10 — Audit Logs

After doing several of the tests above (creating incentives, approving reimbursements, editing employees):

```bash
php artisan tinker --execute "App\Models\AuditLog::latest()->take(10)->get()->map(fn(\$l) => [\$l->event, \$l->auditable_type, \$l->created_at->format('H:i:s')])->values();"
```

**Expected:** Rows showing `created` / `updated` events for `Employee`, `Payslip`, `User` models.

✅ Pass / ❌ Fail: _______________

---

## TEST 11 — Scheduled Commands (Dry Run)

Test that all 9 new cron commands exist and run without errors:

```bash
php artisan hrms:check-late-arrivals
php artisan hrms:check-excess-breaks
php artisan hrms:escalate-ot
php artisan hrms:check-document-expiry
php artisan hrms:check-probation-due
php artisan hrms:check-newhire-checkin
php artisan hrms:send-review-reminders
php artisan hrms:prune-notifications
php artisan hrms:generate-attendance-summary
```

Each should run without throwing an exception (they may output "0 records flagged" — that's fine with empty test data).

✅ Pass / ❌ Fail: _______________

---

## Quick Result Summary

| # | Test Area | Result |
|---|-----------|--------|
| 1 | Incentives — Submit / Approve / Reject | |
| 2 | Reimbursements — Submit + Receipt / Approve | |
| 3 | Executive Dashboard | |
| 4 | Finance Dashboard | |
| 5 | Cycle A/B Payroll Split | |
| 6 | Public Holidays (DB + `isHoliday()`) | |
| 7 | December Mandatory Days | |
| 8 | Break Tracking (Start + End) | |
| 9 | Regularisation Request Modal | |
| 10 | Audit Logs auto-recording | |
| 11 | 9 Cron Commands — dry run | |

---

## Common Issues & Fixes

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Incentives page 404 | Route cache stale | `php artisan route:clear` |
| "Class not found" error | Composer autoload stale | `composer dump-autoload` |
| Break buttons not visible | Already checked out today | Use employee who is currently clocked in |
| Payslip cycle split not working | Employee has no `salary_cycle` set | Edit employee → set `salary_cycle` to `cycle_a` |
| Finance dashboard shows `—` everywhere | No payroll run yet | Run payroll first via `/payroll/process` |
| File upload fails | `storage:link` not set | `php artisan storage:link` |
