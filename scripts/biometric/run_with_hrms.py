#!/usr/bin/env python3
"""
Run the ADMS attendance engine with HRMS as the master for employees + shifts.

Deploy helper for the Python attendance engine (lives in the Laravel repo only so
it can be shipped via `git pull`; copy it next to adms_log.py on the engine box).

It imports the existing engine (adms_log.py) WITHOUT modifying it, then pulls
employees and shifts from the HRMS (Laravel) APIs every HRMS_REFRESH_SEC seconds
and rebuilds the engine's EMPLOYEES / EMPLOYEE_META / SHIFTS at runtime.

Effect: add or edit an employee or shift in HRMS and it flows here automatically
(correct name, department, and shift -> correct late / OT) -- no hardcoding, no
restart. Attendance still syncs back to HRMS via the Laravel pull command.

Start the engine with THIS file instead of adms_log.py:
    HRMS_API_KEY=<BIOMETRIC_SYNC_API_KEY> python3 run_with_hrms.py [PORT]

To revert, just go back to running:  python3 adms_log.py
"""
import json
import os
import threading
import time
import urllib.request

import adms_log  # the existing engine, imported unmodified

HRMS_URL = os.environ.get("HRMS_URL", "https://nexcore.wayforweb.tech").rstrip("/")
HRMS_API_KEY = os.environ.get("HRMS_API_KEY", "")   # = BIOMETRIC_SYNC_API_KEY in HRMS .env
HRMS_REFRESH_SEC = int(os.environ.get("HRMS_REFRESH_SEC", "60"))


def _hrms_get(path):
    req = urllib.request.Request(
        HRMS_URL + path,
        headers={"X-Api-Key": HRMS_API_KEY, "Accept": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=10) as r:
        return json.loads(r.read().decode("utf-8")).get("data", [])


def refresh_master_data():
    """Rebuild the engine's SHIFTS + EMPLOYEES + EMPLOYEE_META from HRMS."""
    if not HRMS_API_KEY:
        print("HRMS_API_KEY not set -- skipping HRMS sync (engine uses its built-in lists).")
        return

    try:
        shifts = {}
        for s in _hrms_get("/api/v1/shifts"):
            name = s.get("name")
            if not name:
                continue
            shifts[name] = {
                "start": (s.get("start_time") or "09:00")[:5],
                "end": (s.get("end_time") or "18:00")[:5],
                "grace": int(s.get("grace_minutes") or 0),
                "hours": int(round(float(s.get("standard_hours") or 8) * 60)),
            }
        if shifts:
            shifts.setdefault("General", {"start": "09:00", "end": "18:00", "grace": 15, "hours": 480})
            adms_log.SHIFTS.clear()
            adms_log.SHIFTS.update(shifts)
            print(f"HRMS sync: {len(shifts)} shifts loaded.")
    except Exception as e:
        print("HRMS shift sync failed:", e)

    try:
        emps, meta = {}, {}
        for e in _hrms_get("/api/v1/employees"):
            code = e.get("employee_code")
            if code is None:
                continue
            code = str(code)
            emps[code] = e.get("name") or ("Emp " + code)
            meta[code] = {
                "department": e.get("department") or "General",
                "designation": e.get("designation") or "Staff",
                "shift": e.get("shift") or "General",
            }
        if emps:
            adms_log.EMPLOYEES.clear()
            adms_log.EMPLOYEES.update(emps)
            adms_log.EMPLOYEE_META.clear()
            adms_log.EMPLOYEE_META.update(meta)
            print(f"HRMS sync: {len(emps)} employees loaded.")
    except Exception as e:
        print("HRMS employee sync failed:", e)


def _sync_loop():
    while True:
        time.sleep(HRMS_REFRESH_SEC)
        refresh_master_data()


if __name__ == "__main__":
    refresh_master_data()                                     # initial load before serving
    threading.Thread(target=_sync_loop, daemon=True).start()  # refresh every minute
    adms_log.main()                                           # hand off to the engine
