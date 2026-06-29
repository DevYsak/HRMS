<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PublicHoliday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PublicHoliday
 */
class HolidaySyncResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date?->toDateString(),
            'name' => $this->name,
            'country' => $this->country,
        ];
    }
}
