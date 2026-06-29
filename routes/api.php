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
*/
Route::middleware('biometric.api')->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/employees', EmployeeSyncController::class)->name('employees');
    Route::get('/shifts', ShiftSyncController::class)->name('shifts');
    Route::get('/holidays', HolidaySyncController::class)->name('holidays');
    Route::get('/leaves', LeaveSyncController::class)->name('leaves');
    Route::post('/attendance/sync', AttendanceSyncController::class)->name('attendance.sync');
});
