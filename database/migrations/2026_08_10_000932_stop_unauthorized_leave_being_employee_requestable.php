<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unauthorized Leave is a disciplinary classification HR applies. It is not
 * something an employee asks for, and it was sitting in their leave-type
 * dropdown.
 *
 * There are two rows for the concept: the coded one (UL) is configured
 * correctly — system-controlled, neither paid nor unpaid requestable — and a
 * legacy row predating the codes was left fully requestable. Because the
 * dropdown de-duplicates by name and keeps the first match, the misconfigured
 * legacy row is the one employees actually saw.
 *
 * This aligns the legacy row with its coded twin. Nothing is deleted: the row
 * keeps its id, so the leave balances that reference it are untouched, and any
 * historical request would keep its foreign key. Only three booleans change.
 *
 * Guarded and scoped: it matches on name + absent code + category, and skips
 * silently if no such row exists, so it is safe on a fresh database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')
            ->where('name', 'Unauthorized Leave')
            ->whereNull('code')
            ->where('category', 'unauthorized')
            ->update([
                'is_system_controlled' => true,
                'allow_paid_request' => false,
                'allow_unpaid_request' => false,
            ]);
    }

    /**
     * Restores the previous flags. They were wrong, so this exists for
     * reversibility rather than because anyone should want it.
     */
    public function down(): void
    {
        DB::table('leave_types')
            ->where('name', 'Unauthorized Leave')
            ->whereNull('code')
            ->where('category', 'unauthorized')
            ->update([
                'is_system_controlled' => false,
                'allow_paid_request' => true,
                'allow_unpaid_request' => true,
            ]);
    }
};
