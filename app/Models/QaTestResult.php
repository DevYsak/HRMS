<?php

namespace App\Models;

use App\Enums\QaTestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recorded outcome of a test case. Append-only: a retest adds a row, it never edits one. */
#[Fillable([
    'qa_test_case_id', 'qa_test_run_id', 'status', 'actual_result', 'environment', 'commit', 'note',
    'tested_by', 'tested_by_name', 'tested_at', 'is_retest',
])]
class QaTestResult extends Model
{
    protected function casts(): array
    {
        return [
            'status' => QaTestStatus::class,
            'tested_at' => 'datetime',
            'is_retest' => 'boolean',
        ];
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(QaTestCase::class, 'qa_test_case_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(QaTestRun::class, 'qa_test_run_id');
    }
}
