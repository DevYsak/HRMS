<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'is_paid', 'color', 'category', 'allow_carry_forward', 'carry_forward_limit', 'allow_encashment'])]
class LeaveType extends Model
{
    use SoftDeletes;

    protected $casts = [
        'is_paid' => 'boolean',
        'allow_carry_forward' => 'boolean',
        'carry_forward_limit' => 'integer',
        'allow_encashment' => 'boolean',
    ];

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
