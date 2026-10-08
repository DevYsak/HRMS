<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'serial_number', 'ip_address', 'port', 'timeout_seconds', 'is_active',
    'last_synced_at', 'last_sync_count', 'adms_stamp',
    'last_ping_at', 'last_ping_status', 'last_ping_error',
])]
#[Hidden(['adms_token_hash'])]
class BiometricDevice extends Model
{
    /**
     * Addresses this device may push from: its ADMS allow-list, else the
     * address it is registered at.
     *
     * @return array<int, string>
     */
    public function admsAllowedAddresses(): array
    {
        $list = $this->adms_allowed_ips ?: $this->ip_address;

        return array_values(array_filter(array_map('trim', explode(',', (string) $list))));
    }

    public function requiresAdmsToken(): bool
    {
        return $this->adms_token_hash !== null && $this->adms_token_hash !== '';
    }

    public function admsTokenMatches(?string $token): bool
    {
        return $this->requiresAdmsToken()
            && $token !== null && $token !== ''
            && hash_equals((string) $this->adms_token_hash, hash('sha256', $token));
    }

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
