<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for query patterns confirmed in the 8 Oct 2026 performance audit
 * (each one backs a hot query that had no usable index). Additive and
 * idempotent; no data changes.
 *
 *  - leave_requests (status, created_at): approval queues / Command Center /
 *    All Leave sort pending requests by age; the hourly escalation job.
 *  - notifications (notifiable_type, notifiable_id, created_at): the header
 *    bell (polled every 30 s per signed-in user) and the inbox page take the
 *    latest N for one user.
 *  - ot_requests (source, nexflow_ref): the 10-minute Nexflow sync looks each
 *    record up by reference.
 *  - audit_logs (action), (created_at): Activity Log filters by action and by
 *    date range.
 *  - attendance_punches (punch_date): device-wide views for one day
 *    (Biometric Control, punch-timeline rebuild) have no employee filter.
 */
return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> table => index name => columns */
    private const INDEXES = [
        'leave_requests' => ['leave_requests_status_created_at_index' => ['status', 'created_at']],
        'notifications' => ['notifications_notifiable_created_at_index' => ['notifiable_type', 'notifiable_id', 'created_at']],
        'ot_requests' => ['ot_requests_source_nexflow_ref_index' => ['source', 'nexflow_ref']],
        'audit_logs' => [
            'audit_logs_action_index' => ['action'],
            'audit_logs_created_at_index' => ['created_at'],
        ],
        'attendance_punches' => ['attendance_punches_punch_date_index' => ['punch_date']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
