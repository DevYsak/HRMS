<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'template_id', 'phase', 'title', 'description',
    'category', 'owner_role', 'due_days', 'sort_order', 'auto_trigger',
])]
class OnboardingTemplateTask extends Model
{
    protected function casts(): array
    {
        return [
            'due_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class);
    }
}
