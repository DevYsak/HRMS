<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payslip_id', 'name', 'amount', 'type'])]
class PayslipItem extends Model
{
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
