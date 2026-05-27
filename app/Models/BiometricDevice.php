<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'ip_address', 'port', 'timeout_seconds', 'is_active',
    'last_synced_at', 'last_sync_count',
    'last_ping_at', 'last_ping_status', 'last_ping_error',
])]
class BiometricDevice extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_ping_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BiometricLog::class, 'device_id');
    }

    public function isOnline(): bool
    {
        return $this->last_ping_status === 'online';
    }
}
