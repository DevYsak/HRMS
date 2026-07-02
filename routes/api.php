<?php

use App\Http\Controllers\Api\V1\AttendanceSyncController;
use App\Http\Controllers\Api\V1\EmployeeSyncController;
use App\Http\Controllers\Api\V1\HolidaySyncController;
use App\Http\Controllers\Api\V1\LeaveSyncController;
use App\Http\Controllers\Api\V1\ShiftSyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Biometric Attendance Sync API (v1)
|--------------------------------------------------------------------------
| Consumed by the external Python attendance engine. All routes are guarded
| by a shared secret (see VerifyBiometricApiKey). URLs are prefixed with
| /api automatically by the api router.
|
|   GET  /api/v1/employees          master employee list (device PIN keyed)
|   GET  /api/v1/shifts             shift definitions + grace/OT thresholds
|   GET  /api/v1/holidays           public holidays (filter: year, country)
|   GET  /api/v1/leaves             approved leaves (filter: from, to)
|   POST /api/v1/attendance/sync    ingest engine-calculated daily summaries
|
| POST /api/v1/attendance/sync record fields (per record; batch under "records"
| or a single bare record):
|   employee_code*    int    device PIN
|   date*             date
|   first_punch       datetime|"HH:MM:SS"   day's first punch
|   last_punch        datetime|"HH:MM:SS"   day's last punch
|   first_punch_method  string   how the FIRST punch was verified
|   last_punch_method   string   how the LAST punch was verified
|   break_minutes, working_hours, late_minutes, early_leave_minutes,
|   overtime_minutes, status, device_serial, raw_punch_count
|
| Punch method values (case-insensitive; see App\Enums\PunchMethod::fromDevice):
|   Face          → "face" | "facial" | ZK verify 15/11
|   ID Card       → "id_card" | "id" | "rfid" | "card" | ZK verify 3/4
|   Physical Card → "physical_card" | "swipe" | "mag" | "mifare"
|   Anything else (fingerprint, password, unknown) is stored as null → no chip.
*/
Route::middleware('biometric.api')->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/employees', EmployeeSyncController::class)->name('employees');
    Route::get('/shifts', ShiftSyncController::class)->name('shifts');
    Route::get('/holidays', HolidaySyncController::class)->name('holidays');
    Route::get('/leaves', LeaveSyncController::class)->name('leaves');
    Route::post('/attendance/sync', AttendanceSyncController::class)->name('attendance.sync');
});
