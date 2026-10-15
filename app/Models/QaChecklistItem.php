<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One cell of a readiness grid: module × dimension, page × viewport/theme,
 * or role × demo step. A cell nobody has checked is simply absent (NOT TESTED).
 */
#[Fillable(['category', 'subject', 'dimension', 'status', 'note', 'commit', 'checked_by', 'checked_at'])]
class QaChecklistItem extends Model
{
    public const MODULE = 'module';

    public const RESPONSIVE = 'responsive';

    public const DEMO = 'demo';

    /** @var array<string, string> */
    public const STATUSES = ['pass' => 'PASS', 'partial' => 'PARTIAL', 'fail' => 'FAIL', 'not_tested' => 'NOT TESTED'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public static function colorFor(string $status): string
    {
        return match ($status) {
            'pass' => 'emerald',
            'partial' => 'amber',
            'fail' => 'rose',
            default => 'zinc',
        };
    }
}
