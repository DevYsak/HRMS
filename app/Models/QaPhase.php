<?php

namespace App\Models;

use App\Enums\QaPhaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'name', 'status', 'owner', 'started_at', 'completed_at', 'notes'])]
class QaPhase extends Model
{
    protected function casts(): array
    {
        return [
            'status' => QaPhaseStatus::class,
            'started_at' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function testCases(): HasMany
    {
        return $this->hasMany(QaTestCase::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QaFinding::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(QaTestRun::class);
    }
}
