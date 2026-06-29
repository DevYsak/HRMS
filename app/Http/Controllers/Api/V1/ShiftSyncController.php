<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ShiftSyncResource;
use App\Models\ShiftSetting;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/shifts
 *
 * Shift definitions (start/end, grace, break, standard & OT-threshold hours)
 * the Python engine uses to compute late minutes, working hours and overtime.
 */
class ShiftSyncController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return ShiftSyncResource::collection(
            ShiftSetting::orderBy('name')->get()
        );
    }
}
