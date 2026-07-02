#!/usr/bin/env python3
"""
punch_methods.py — add the punch verify method (Face / Card / Fingerprint) to
the engine's /api/dashboard rows so HRMS can show the method chips.

The ADMS engine already stores every raw punch (with its `verify` code) in its
SQLite DB. This helper reads that DB and returns the verify value of each
employee's FIRST and LAST punch for a day. HRMS maps the value — raw numeric
code (this device: 3=Card, 4=Face, 1=Fingerprint) OR a decoded label — to a
chip; unmapped codes (PIN / Other) show nothing.

Deploy: copy next to adms_log.py on the engine box (ships via the Laravel repo,
like run_with_hrms.py). No change to adms_log.py's logic is required beyond two
lines where it builds each dashboard row (see USAGE below).

USAGE — in adms_log.py, wherever a dashboard row dict is built with
`first_punch` / `last_punch` for an employee (`pin`) on `date` ('YYYY-MM-DD'):

    from punch_methods import first_last_verify
    fv, lv = first_last_verify(pin, date)
    row["first_punch_method"] = fv
    row["last_punch_method"] = lv

That's it — HRMS's every-10-min pull then fills check_in_method / check_out_method.

If auto-detection can't find the punches table/columns, run:
    sqlite3 <your.db> ".schema"
and set _SCHEMA below by hand (table + pin/time/verify column names).
"""
import os
import sqlite3

# Path to the engine's SQLite DB. Override with env ADMS_DB if it isn't in CWD.
DB_PATH = os.environ.get("ADMS_DB", "attendance.db")

# Detected once on first call; hard-set these if detection guesses wrong.
_SCHEMA = {"table": None, "pin": None, "time": None, "verify": None}

# Candidate column names seen across ZKTeco / ADMS schemas.
_PIN_COLS = ("pin", "userid", "user_id", "device_user_id", "enrollid",
             "enroll_id", "badgenumber", "emp_id", "empid", "employee_id")
_TIME_COLS = ("time", "punch_time", "punched_at", "checktime", "check_time",
              "timestamp", "datetime", "log_time", "punchtime", "att_time")


def _detect(con):
    if _SCHEMA["table"]:
        return
    cur = con.cursor()
    tables = [r[0] for r in cur.execute(
        "SELECT name FROM sqlite_master WHERE type='table'")]
    for t in tables:
        cols = [c[1] for c in cur.execute("PRAGMA table_info(%s)" % t)]
        low = {c.lower(): c for c in cols}
        verify = next((low[c] for c in low if "verif" in c or c in ("vmode", "vtype")), None)
        pin = next((low[c] for c in _PIN_COLS if c in low), None)
        tcol = next((low[c] for c in _TIME_COLS if c in low), None)
        if verify and pin and tcol:
            _SCHEMA["table"] = t
            _SCHEMA["pin"] = pin
            _SCHEMA["time"] = tcol
            _SCHEMA["verify"] = verify
            return
    raise RuntimeError(
        "punch_methods: no punches table with pin/time/verify columns found. "
        "Tables seen: %s — set _SCHEMA by hand." % tables)


def first_last_verify(pin, date):
    """(first_verify, last_verify) for `pin` on `date` ('YYYY-MM-DD'), or (None, None)."""
    try:
        con = sqlite3.connect(DB_PATH)
        _detect(con)
        s = _SCHEMA
        rows = con.execute(
            "SELECT %s FROM %s WHERE %s = ? AND substr(%s,1,10) = ? ORDER BY %s ASC"
            % (s["verify"], s["table"], s["pin"], s["time"], s["time"]),
            (str(pin), str(date)),
        ).fetchall()
        con.close()
        if not rows:
            return None, None
        return rows[0][0], rows[-1][0]
    except Exception as e:  # never break the dashboard over a chip
        print("punch_methods:", e)
        return None, None


if __name__ == "__main__":
    # Quick self-check: python3 punch_methods.py <pin> <YYYY-MM-DD>
    import sys
    if len(sys.argv) == 3:
        print(first_last_verify(sys.argv[1], sys.argv[2]))
    else:
        con = sqlite3.connect(DB_PATH)
        _detect(con)
        print("Detected schema:", _SCHEMA)
