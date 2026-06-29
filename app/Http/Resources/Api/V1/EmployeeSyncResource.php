<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeSyncResource extends JsonResource
{
    /**
     * Master employee record consumed by the Python attendance engine.
     *
     * `employee_code` is the authoritative biometric key: the Python engine
     * matches device punches to employees ONLY by this code — never by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_code' => $this->employee_code,
            'employee_id' => $this->employee_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'department' => $this->department?->name,
            // HRMS has no first-class Team on Employee yet; null until modelled.
            'team' => null,
            'designation' => $this->jobTitle?->name,
            'shift_id' => $this->shift_id,
            'shift' => $this->shift?->name,
            'manager' => $this->manager?->name,
            'status' => $this->status?->value,
            'is_active' => (bool) $this->status?->isActive(),
            'biometric_user_id' => $this->biometric_user_id,
            'biometric_device_id' => $this->biometric_device_id,
        ];
    }
}
