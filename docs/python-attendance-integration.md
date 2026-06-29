# Python Attendance Engine ↔ HRMS (Pulse) Integration Spec

This is the contract the Python attendance engine
(`/home/ysak/app/nexc/py-adms/biometric-python-adms`) implements against the
Laravel HRMS (Pulse). HRMS is the **master**: it owns employees, shifts,
holidays and leaves. Python is the **engine**: it owns punch collection and all
attendance calculation, then pushes the calculated daily summary back to HRMS.

```
  Laravel (master)  ──  employees / shifts / holidays / leaves  ──▶  Python (engine)
  Laravel (master)  ◀──  calculated daily attendance summaries  ──   Python (engine)
```

This integration is **additive**. The existing Laravel device path
(`AdmsController` at `/iclock/*` + `BiometricSyncService`) and the existing
`attendances` table are left untouched as a fallback. Python's data lands in a
separate `attendance_daily_summaries` table.

---

## 1. Authentication

All `/api/v1/*` endpoints require a shared secret. Send it as either header:

```
X-Api-Key: <BIOMETRIC_SYNC_API_KEY>
# or
Authorization: Bearer <BIOMETRIC_SYNC_API_KEY>
```

- The key is configured in HRMS via `BIOMETRIC_SYNC_API_KEY` (`.env`).
- Missing/invalid key → `401`. Key not configured server-side → `503` (fails closed).

Base URL: `https://nexcore.wayforweb.tech` → endpoints live under `/api/v1`.

---

## 2. Endpoints HRMS serves (Python pulls these)

All list endpoints return `{ "data": [ ... ] }` (Laravel resource collection).

### GET `/api/v1/employees`
Master employee list. **Maps punches by `employee_code` only — never by name.**
By default returns only employees that have an `employee_code`; pass `?all=1`
to include codeless ones.

```json
{
  "data": [
    {
      "employee_code": 101,
      "employee_id": "EMP-1234",
      "name": "Asha Rao",
      "email": "asha@example.com",
      "department": "Engineering",
      "team": null,
      "designation": "Senior Developer",
      "shift_id": 3,
      "shift": "General Shift",
      "manager": "Ravi Kumar",
      "status": "active",
      "is_active": true,
      "biometric_user_id": null,
      "biometric_device_id": 1
    }
  ]
}
```
> `team` is `null` for now — HRMS has no first-class Team on the employee record yet.

### GET `/api/v1/shifts`
Shift rules for late / working-hours / OT calculation.

```json
{
  "data": [
    {
      "id": 3,
      "name": "General Shift",
      "start_time": "09:00:00",
      "end_time": "18:00:00",
      "grace_minutes": 10,
      "break_minutes": 60,
      "standard_hours": "9.00",
      "ot_threshold_hours": "9.00",
      "description": null
    }
  ]
}
```

### GET `/api/v1/holidays`
Public holidays. Filters: `?year=YYYY` (default = current year), `?country=IN|UK`.

```json
{ "data": [ { "date": "2026-08-15", "name": "Independence Day", "country": "IN" } ] }
```

### GET `/api/v1/leaves`
Approved leaves overlapping a window. Filters: `?from=Y-m-d&?to=Y-m-d`
(default = current month start → next month end). Keyed by `employee_code`.

```json
{
  "data": [
    {
      "employee_code": 201,
      "leave_type": "Casual Leave",
      "leave_code": "CL",
      "start_date": "2026-06-06",
      "end_date": "2026-06-06",
      "is_half_day": false,
      "half_day_period": null,
      "days": "1.00",
      "is_paid": true,
      "status": "approved"
    }
  ]
}
```

---

## 3. Endpoint Python calls (push back results)

### POST `/api/v1/attendance/sync`
Push engine-calculated **daily summaries**. Upserted by `(employee_code, date)`,
so re-sending the same day is safe (idempotent). Send a batch:

```json
{
  "records": [
    {
      "employee_code": 101,
      "date": "2026-06-29",
      "first_punch": "2026-06-29 09:05:00",
      "last_punch": "2026-06-29 18:30:00",
      "break_minutes": 45,
      "working_hours": 8.75,
      "late_minutes": 5,
      "early_leave_minutes": 0,
      "overtime_minutes": 30,
      "status": "present",
      "device_serial": "TBDD253900118",
      "raw_punch_count": 4
    }
  ]
}
```

A single bare record (no `records` wrapper) is also accepted.

**Field notes**
| Field | Required | Type | Notes |
|---|---|---|---|
| `employee_code` | yes | int | device PIN; unknown codes are skipped + reported |
| `date` | yes | date | `Y-m-d` |
| `first_punch` / `last_punch` | no | datetime | full datetime preferred; time-only is anchored to `date` |
| `break_minutes` | no | int | default 0 |
| `working_hours` | no | decimal | default 0 |
| `late_minutes` | no | int | default 0 |
| `early_leave_minutes` | no | int | default 0 |
| `overtime_minutes` | no | int | default 0 |
| `status` | no | string(≤30) | free-form: `present`, `absent`, `late`, `half_day`, `leave`, `holiday`, `weekly_off`, … default `present` |
| `device_serial` | no | string | provenance |
| `raw_punch_count` | no | int | provenance |

**Responses**
- `200` — all records stored: `{ "success": true, "synced": N, "skipped": 0, "skipped_records": [] }`
- `207` — partial: some `employee_code`s unknown:
  `{ "success": true, "synced": N, "skipped": M, "skipped_records": [{ "employee_code": 99999, "date": "...", "reason": "unknown employee_code" }] }`
- `422` — validation error (bad/missing `date` etc.)
- `401` / `503` — auth (see §1)

---

## 4. Suggested Python client behaviour

- **Cache the master data** (employees/shifts/holidays/leaves) in memory; refresh
  every ~60s. No restart should be required to pick up new employees.
- **Remove hardcoded data** (`EMPLOYEES`, `EMPLOYEE_META`, hardcoded departments
  /shifts) — load it all from §2.
- **Match strictly by `employee_code`**. Skip punches whose code isn't in the
  cached employee map; log them.
- **Push summaries** at end-of-day and/or on a rolling interval for the current
  day (the endpoint is idempotent, so re-pushing the live day is fine).
- **Batch** records (e.g. all employees for a date) into one POST.
- Treat `207` as success-with-warnings: surface `skipped_records` so codes can be
  enrolled in HRMS.

### Minimal client sketch

```python
import requests

BASE = "https://nexcore.wayforweb.tech/api/v1"
HEADERS = {"X-Api-Key": "<BIOMETRIC_SYNC_API_KEY>"}

def get(path, **params):
    r = requests.get(f"{BASE}/{path}", headers=HEADERS, params=params, timeout=10)
    r.raise_for_status()
    return r.json()["data"]

def push_attendance(records):
    r = requests.post(f"{BASE}/attendance/sync", headers=HEADERS,
                      json={"records": records}, timeout=15)
    r.raise_for_status()
    return r.json()  # {synced, skipped, skipped_records}

employees = {e["employee_code"]: e for e in get("employees")}
shifts     = {s["id"]: s for s in get("shifts")}
holidays   = get("holidays")
leaves     = get("leaves")
```

---

## 5. HRMS deployment checklist

1. Set `BIOMETRIC_SYNC_API_KEY` in HRMS `.env` (share the same value with Python).
2. Run `php artisan migrate` (creates `attendance_daily_summaries`).
3. `php artisan config:clear` (or `config:cache`) after editing `.env`.
4. Confirm: `GET /api/v1/employees` with the key returns `200`.

> **Not in this phase:** rendering `attendance_daily_summaries` in the HRMS
> attendance dashboard (deliverable #11). The data now flows and is stored;
> wiring the dashboard to read it is the next increment.
