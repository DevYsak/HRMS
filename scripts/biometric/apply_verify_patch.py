#!/usr/bin/env python3
"""
One-shot patcher: teach adms_log.py to CAPTURE the punch verify mode
(Face/Card/Fingerprint) and expose it on /api/dashboard so HRMS shows chips.

It makes four edits:
  1. init_db()      — add a `verify` column to the punches table (ALTER, keeps data).
  2. store_punch()  — accept & store the verify value.
  3. ATTLOG parse   — read the device's verify field (tab pos 4 / space pos 5).
  4. build_dashboard— attach first_punch_method / last_punch_method per row
                      (via punch_methods.first_last_verify).

Run once on the engine box:
    cd ~/app/nexc/py-adms/biometric-python-adms
    cp ~/app/nexc/scripts/biometric/apply_verify_patch.py .
    cp ~/app/nexc/scripts/biometric/punch_methods.py .
    python3 apply_verify_patch.py

Idempotent (safe to re-run — already-applied edits are skipped). Writes a
backup to adms_log.py.bak. Nothing is saved unless ALL edits resolve, so the
file can never be left half-patched. Restart the engine afterwards.
"""
import os
import py_compile
import shutil
import sys

TARGET = os.environ.get("ADMS_FILE", "adms_log.py")

# (name, marker_if_already_applied, old_anchor, new_text)
EDITS = [
    (
        "init_db: add verify column",
        'ADD COLUMN verify',
        '        conn.execute("CREATE INDEX IF NOT EXISTS idx_punch_date ON punches(punch_date)")',
        '        conn.execute("CREATE INDEX IF NOT EXISTS idx_punch_date ON punches(punch_date)")\n'
        '        _pcols = [r[1] for r in conn.execute("PRAGMA table_info(punches)")]\n'
        '        if "verify" not in _pcols:\n'
        '            conn.execute("ALTER TABLE punches ADD COLUMN verify TEXT")',
    ),
    (
        "store_punch: signature",
        'raw_status="", verify=""',
        'def store_punch(emp_id, dt_str, device_sn="", raw_status=""):',
        'def store_punch(emp_id, dt_str, device_sn="", raw_status="", verify=""):',
    ),
    (
        "store_punch: insert columns",
        'raw_status, verify, created_at)',
        'device_sn, raw_status, created_at)',
        'device_sn, raw_status, verify, created_at)',
    ),
    (
        "store_punch: insert placeholders",
        'VALUES (?,?,?,?,?,?,?,?)',
        'VALUES (?,?,?,?,?,?,?)',
        'VALUES (?,?,?,?,?,?,?,?)',
    ),
    (
        "store_punch: insert values",
        'device_sn, raw_status, verify,',
        'dt_str, punch_date, device_sn, raw_status,',
        'dt_str, punch_date, device_sn, raw_status, verify,',
    ),
    (
        "ATTLOG parse: tab branch verify",
        'verify = parts[3].strip()',
        'status = parts[2].strip() if len(parts) > 2 else ""',
        'status = parts[2].strip() if len(parts) > 2 else ""\n'
        '                verify = parts[3].strip() if len(parts) > 3 else ""',
    ),
    (
        "ATTLOG parse: space branch verify",
        'verify = parts[4]',
        'status = parts[3] if len(parts) > 3 else ""',
        'status = parts[3] if len(parts) > 3 else ""\n'
        '                verify = parts[4] if len(parts) > 4 else ""',
    ),
    (
        "ATTLOG parse: pass verify to store_punch",
        'store_punch(emp_id, dt, sn, status, verify)',
        'if store_punch(emp_id, dt, sn, status):',
        'if store_punch(emp_id, dt, sn, status, verify):',
    ),
    (
        "build_dashboard: import helper",
        'from punch_methods import first_last_verify',
        'def build_dashboard(date_str):',
        'from punch_methods import first_last_verify\n\n\ndef build_dashboard(date_str):',
    ),
    (
        "build_dashboard: attach methods to row",
        'entry["first_punch_method"]',
        '            entry.pop(k, None)\n        table.append(entry)',
        '            entry.pop(k, None)\n'
        '        _fv, _lv = first_last_verify(emp_id, date_str)\n'
        '        entry["first_punch_method"] = _fv\n'
        '        entry["last_punch_method"] = _lv\n'
        '        table.append(entry)',
    ),
]


def main():
    if not os.path.exists(TARGET):
        print("ERROR: %s not found. Run this from the engine folder." % TARGET)
        return 1

    with open(TARGET, "r", encoding="utf-8") as f:
        src = f.read()

    out = src
    applied, skipped, missing = [], [], []

    for name, marker, old, new in EDITS:
        if marker in out:
            skipped.append(name)
        elif old in out:
            out = out.replace(old, new, 1)
            applied.append(name)
        else:
            missing.append(name)

    for n in applied:
        print("  patched :", n)
    for n in skipped:
        print("  already :", n)
    for n in missing:
        print("  MISSING :", n, "(anchor not found)")

    if missing:
        print("\nAborted — could not find %d anchor(s); nothing written." % len(missing))
        print("Send the adms_log.py sections above to whoever generated this script.")
        return 1

    if not applied:
        print("\nNothing to do — already fully patched.")
        return 0

    shutil.copyfile(TARGET, TARGET + ".bak")
    with open(TARGET, "w", encoding="utf-8") as f:
        f.write(out)

    try:
        py_compile.compile(TARGET, doraise=True)
    except py_compile.PyCompileError as e:
        shutil.copyfile(TARGET + ".bak", TARGET)  # roll back
        print("\nSyntax check FAILED — rolled back. Error:\n", e)
        return 1

    print("\nDone. Backup at %s.bak. Restart the engine to apply." % TARGET)
    return 0


if __name__ == "__main__":
    sys.exit(main())
