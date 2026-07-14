<?php

namespace App\Services\Performance;

use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\PerformanceTemplate;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finishes the ReviewCycle → PerformanceCycle cutover (v4 Phase D pre-work).
 *
 * Every legacy review_cycles row gets a mirrored performance_cycles row under
 * a dedicated "Legacy" template, and reviews still pointing only at
 * review_cycle_id are backfilled with the mirrored performance_cycle_id.
 * Legacy rows are never deleted — review_cycle_id stays as a historical
 * pointer. Idempotent: safe to run repeatedly.
 */
class LegacyCycleMigrator
{
    public const LEGACY_TEMPLATE_CODE = 'LEGACY-REVIEW';

    /** @return array{cycles_mirrored: int, reviews_backfilled: int} */
    public function migrate(): array
    {
        $legacyCycles = ReviewCycle::whereHas('reviews')->get();

        if ($legacyCycles->isEmpty()) {
            return ['cycles_mirrored' => 0, 'reviews_backfilled' => 0];
        }

        $actorId = User::whereIn('role', ['super_admin', 'hr_admin'])->value('id')
            ?? User::query()->value('id');

        if ($actorId === null) {
            return ['cycles_mirrored' => 0, 'reviews_backfilled' => 0];
        }

        return DB::transaction(function () use ($legacyCycles, $actorId) {
            $template = PerformanceTemplate::withTrashed()->firstOrCreate(
                ['code' => self::LEGACY_TEMPLATE_CODE],
                [
                    'name' => 'Legacy Review Cycles',
                    'description' => 'Auto-created container for reviews migrated from the retired review_cycles table.',
                    'applies_to_type' => 'global',
                    'cycle_type' => 'quarterly',
                    'is_active' => false,
                    'created_by' => $actorId,
                ],
            );

            $mirrored = 0;
            $backfilled = 0;

            foreach ($legacyCycles as $legacy) {
                $cycle = PerformanceCycle::withTrashed()->firstOrCreate(
                    [
                        'template_id' => $template->id,
                        'name' => $legacy->name,
                        'start_date' => $legacy->start_date->toDateString(),
                        'end_date' => $legacy->end_date->toDateString(),
                    ],
                    [
                        'cycle_type' => 'quarterly',
                        'status' => match ($legacy->status) {
                            'draft' => 'draft',
                            'active' => 'active',
                            default => 'completed',
                        },
                        'created_by' => $actorId,
                    ],
                );

                if ($cycle->wasRecentlyCreated) {
                    $mirrored++;
                }

                $backfilled += PerformanceReview::where('review_cycle_id', $legacy->id)
                    ->whereNull('performance_cycle_id')
                    ->update(['performance_cycle_id' => $cycle->id]);
            }

            return ['cycles_mirrored' => $mirrored, 'reviews_backfilled' => $backfilled];
        });
    }
}
