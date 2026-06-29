<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ShiftSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShiftSetting
 */
class ShiftSyncResource extends JsonResource
{
    /**
     * Shift definition consumed by the Python engine for late / OT calculation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'grace_minutes' => $this->grace_minutes,
            'break_minutes' => $this->break_duration,
            'standard_hours' => $this->standard_hours,
            'ot_threshold_hours' => $this->ot_threshold_hours,
            'description' => $this->description,
        ];
    }
}
