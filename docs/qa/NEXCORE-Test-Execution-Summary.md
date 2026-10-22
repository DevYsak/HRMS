# NEXCORE / Pulse HRMS — Test Execution Summary

**Environment:** Local (`http://127.0.0.1:8000`); schema mismatch detected  
**Build / commit:** `staging` / `02cf1f3d586e79177fc744314c1c6450eb4469ee`  
**Test window:** 8 October 2026; Wave 1 stopped  
**Tester(s):** Not provided  
**Data reset / seed reference:** Not provided; migration status shows pending migrations from `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table`

| Metric | Count |
|---|---:|
| Total Test Cases | 1 executed Wave 1 check |
| Passed | 0 |
| Failed | 1 |
| Blocked | 0 |
| Not Tested | Remaining cases — execution stopped |
| Critical failures | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

## Coverage

| Coverage | Result / notes |
|---|---|
| Role Coverage | Super Admin ☐ HR Admin ☐ Director ☐ Manager ☐ Finance ☐ Coordinator ☐ Employee ☐ |
| Module Coverage | Auth ☐ RBAC ☐ Employees ☐ Attendance ☐ Leave ☐ OT ☐ Payroll ☐ Performance ☐ Documents ☐ Reports ☐ Settings ☐ Help ☐ Audit ☐ |
| Browser Coverage | Chrome ☐ Secondary browser ☐ |
| Responsive Coverage | 1920×1080 ☐ 1366×768 ☐ 768×1024 ☐ 390×844 ☐ |
| Production verification | Read-only/safe checks only; list approved checks and exclusions |

## Failure / blocker register

| Bug ID | Test Case | Severity | Owner | Status | Decision / workaround |
|---|---|---|---|---|---|
| BUG-001 | TC-DSH-001 / TC-E2E-007 | BLOCKER | Development / environment owner | Ready for manual retest | Required local migration is now recorded as applied. Re-run Super Admin landing in a browser and attach screenshot before resuming Wave 1. |

## UAT recommendation

**Recommendation:** NOT READY — Wave 1 awaits manual retest of BUG-001.  
**Release blockers:** BUG-001 is pending manual retest. The local `hrms` migration was applied; browser evidence is still required before Wave 1 resumes.  
**Known-risk decisions verified:** D1 ☐ D2 ☐ D3 ☐ D4 ☐ D5 ☐ D6 ☐ D8 ☐ D9 ☐ D10 ☐  
**Approver / date:**
