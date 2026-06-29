<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HolidaySyncResource;
use App\Models\PublicHoliday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/holidays
 *
 * Public holidays for the engine to flag non-working days.
 * Filters: ?year=YYYY (default current year), ?country=IN|UK (optional).
 */
class HolidaySyncController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $year = (int) $request->integer('year', (int) now()->year);

        $holidays = PublicHoliday::query()
            ->whereYear('date', $year)
            ->when($request->filled('country'), fn ($query) => $query->where('country', $request->string('country')))
            ->orderBy('date')
            ->get();

        return HolidaySyncResource::collection($holidays);
    }
}
