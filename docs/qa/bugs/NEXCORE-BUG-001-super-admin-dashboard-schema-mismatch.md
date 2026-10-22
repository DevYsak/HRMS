# BUG-001 — Super Admin dashboard fails because the test database lacks a required schema column

**Module:** Dashboard / Attendance Regularisation  
**Role:** Super Admin  
**Environment:** Local — `http://127.0.0.1:8000`, database `hrms`  
**Severity:** BLOCKER  
**Priority:** P0  
**Related Test Case:** TC-DSH-001 / TC-E2E-007; Wave 1 role-specific landing page

## Precondition

Local application is running from the current `staging` workspace. Super Admin account can authenticate. Read-only `php artisan migrate:status` shows `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table` as **Pending**.

## Steps to Reproduce

1. Start the local application at `http://127.0.0.1:8000`.
2. Sign in as Super Admin.
3. Allow the application to load the landing route `GET /`.

## Expected

The Super Admin company dashboard loads without an error page and displays only authorised dashboard content.

## Actual

Authentication completes, then `GET /` returns an Internal Server Error:

`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'attendance_regularisations.deleted_at' in 'where clause'`

The failing query is generated through `App\Services\Dashboards\OrganisationOverview::approvals()` while querying pending `AttendanceRegularisation` records. The model uses soft deletes, but the local table does not contain `deleted_at` because migration `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table` is pending.

## Evidence

- Pasted exception report supplied 8 October 2026; request `GET /`.
- Stack location: `app/Services/Dashboards/OrganisationOverview.php:198`.
- Browser: Chrome `154.0.0.0` on Windows.
- Viewport: Not captured.
- Screenshot: Not captured; capture full error page and focused error text during the post-fix retest if still reproducible.

## Impact

The Super Admin cannot reach the mandatory Wave 1 landing dashboard. The local test environment does not represent the stated application build, so additional manual results would be unreliable.

## Suggested resolution / retest boundary

Environment owner should align the disposable local/staging schema to the intended build using the approved migration/deployment procedure, verify the migration status is current, then re-run only the Super Admin login/landing dashboard check. Do not apply migrations or make any database changes to production as part of this QA record.

## Remediation record

On 8 October 2026, the missing migration was applied **only to the local `hrms` test database** using its single migration path. Read-only migration status subsequently reported `2026_10_07_124339_add_soft_deletes_to_attendance_regularisations_table` as **Ran** (batch 87).

The focused automated dashboard test could not be used as a regression confirmation because its separate `hrms_test` database is not initialized consistently: the run failed before assertions with missing/partially created base tables. That test-environment issue requires separate setup/triage; it does not change this manual defect result.

**Status:** Ready for manual retest  
**Reported by:** Not provided  
**Reported date:** 2026-10-08  
**Owner:** Development / environment owner
