<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * "debit X took N days from credit Y". credit_entry_id null means no credit
 * covered those days (a real negative balance). Negative days return days
 * to a credit (a reversal). Append-only.
 */
#[Fillable(['debit_entry_id', 'credit_entry_id', 'days'])]
class LeaveCreditConsumption extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit consumption rows are immutable.'));
        static::deleting(fn () => throw new LogicException('Credit consumption rows cannot be deleted.'));
    }

    public function debitEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class, 'debit_entry_id');
    }

    public function creditEntry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class, 'credit_entry_id');
    }
}
