<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'qa_phase_id', 'suite', 'environment', 'commit', 'total', 'passed', 'failed', 'skipped',
    'known_baseline', 'new_regressions', 'duration_seconds', 'result', 'notes', 'run_at', 'created_by',
])]
class QaTestRun extends Model
{
    public const RESULT_PASS = 'pass';

    public const RESULT_PASS_WITH_BASELINE = 'pass_with_known_baseline';

    public const RESULT_FAIL = 'fail';

    /** @var array<string, string> */
    public const RESULTS = [
        self::RESULT_PASS => 'PASS',
        self::RESULT_PASS_WITH_BASELINE => 'PASS WITH KNOWN BASELINE',
        self::RESULT_FAIL => 'FAIL — NEW REGRESSIONS',
    ];

    /** @var array<string, string> */
    public const SUITES = ['pest' => 'Pest', 'phpunit' => 'PHPUnit', 'playwright' => 'Playwright', 'manual' => 'Manual'];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
        ];
    }

    /** PASS / PASS WITH KNOWN BASELINE / FAIL from the counts — a known baseline failure is never a regression. */
    public static function resultFor(int $failed, int $newRegressions): string
    {
        return match (true) {
            $failed === 0 => self::RESULT_PASS,
            $newRegressions === 0 => self::RESULT_PASS_WITH_BASELINE,
            default => self::RESULT_FAIL,
        };
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(QaPhase::class, 'qa_phase_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QaTestResult::class);
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->result] ?? strtoupper($this->result);
    }

    public function resultColor(): string
    {
        return match ($this->result) {
            self::RESULT_PASS => 'emerald',
            self::RESULT_PASS_WITH_BASELINE => 'violet',
            default => 'rose',
        };
    }
}
