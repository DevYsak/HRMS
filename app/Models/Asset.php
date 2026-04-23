<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'name',
        'type',
        'serial_number',
        'status',
        'assigned_date',
        'returned_date',
        'condition_on_return',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'assigned_date' => 'date',
            'returned_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
