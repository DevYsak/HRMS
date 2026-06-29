<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the attendance daily-summary payload pushed by the Python engine.
 *
 * Accepts either a batch ({"records": [ {...}, {...} ]}) or a single record
 * ({...}); a lone record is normalised into a one-element batch.
 */
class AttendanceSyncRequest extends FormRequest
{
    /**
     * Authorisation is handled by the biometric.api middleware (shared key).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Wrap a single bare record into the `records` array so callers may post
     * one summary without the wrapper.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('records') && $this->has('employee_code')) {
            $this->merge(['records' => [$this->except('records')]]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1'],
            'records.*.employee_code' => ['required', 'integer'],
            'records.*.date' => ['required', 'date'],
            'records.*.first_punch' => ['nullable', 'date'],
            'records.*.last_punch' => ['nullable', 'date'],
            'records.*.break_minutes' => ['nullable', 'integer', 'min:0'],
            'records.*.working_hours' => ['nullable', 'numeric', 'min:0'],
            'records.*.late_minutes' => ['nullable', 'integer', 'min:0'],
            'records.*.early_leave_minutes' => ['nullable', 'integer', 'min:0'],
            'records.*.overtime_minutes' => ['nullable', 'integer', 'min:0'],
            'records.*.status' => ['nullable', 'string', 'max:30'],
            'records.*.device_serial' => ['nullable', 'string', 'max:255'],
            'records.*.raw_punch_count' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
