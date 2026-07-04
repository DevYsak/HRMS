<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\BiometricDevice;
use App\Services\Biometric\EngineAttendanceSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Livewire\Component;

/**
 * Biometric Control Center — the fleet view for HR/admin: every registered
 * machine plus any device serials discovered in the day's punches, their
 * connection/health, today's punch volume and the Face/RFID/Fingerprint mix.
 *
 * Honest scope: this deployment PULLS from the Python engine, so we surface
 * what the engine and punches actually report (sync recency, punch counts,
 * verify methods, serials). The device does NOT expose firmware, battery,
 * network signal, failed-punch counts or remote restart — those are marked
 * unavailable rather than faked.
 */
class BiometricControl extends Component
{
    public string $date = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);
        $this->date = Carbon::today()->toDateString();
    }

    public function previousDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
    }

    public function today(): void
    {
        $this->date = Carbon::today()->toDateString();
    }

    /** Real action — pull the latest punches for the day from the engine. */
    public function syncNow(EngineAttendanceSyncService $service): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $result = $service->syncDate($this->date);

        if (($result['error'] ?? null) !== null) {
            \Flux::toast(text: $result['error'], variant: 'danger', heading: 'Sync failed');

            return;
        }

        \Flux::toast(
            text: "Synced {$this->date}: {$result['synced']} updated, {$result['skipped']} skipped.",
            variant: 'success',
            heading: 'Machine synced',
        );
    }

    public function exportLogs()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $rows = AttendanceDailySummary::with('employee.user')
            ->whereNotNull('synced_at')
            ->orderByDesc('synced_at')
            ->limit(500)
            ->get();

        $csv = "Employee,Date,Punches,Device,Synced At\n";
        foreach ($rows as $r) {
            $csv .= '"'.($r->employee?->user?->name ?? ('PIN '.$r->employee_code)).'","'
                .$r->date->toDateString().'","'.(int) $r->raw_punch_count.'","'
                .($r->device_serial ?? '').'","'.($r->synced_at ? Carbon::parse($r->synced_at)->format('Y-m-d H:i') : '')."\"\n";
        }

        return Response::streamDownload(fn () => print ($csv), 'biometric-logs-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $date = Carbon::parse($this->date);

        // ── Registered machines ───────────────────────────────────────────────
        $devices = BiometricDevice::orderBy('name')->get()->map(function ($d) {
            $syncAge = $d->last_synced_at ? (int) Carbon::parse($d->last_synced_at)->diffInMinutes(now()) : null;
            [$state, $tone] = match (true) {
                $d->last_ping_status === 'online' || ($syncAge !== null && $syncAge < 30) => ['online', 'emerald'],
                $syncAge !== null && $syncAge < 120 => ['delayed', 'amber'],
                default => ['offline', 'rose'],
            };

            return [
                'name' => $d->name,
                'address' => $d->ip_address.':'.$d->port,
                'active' => (bool) $d->is_active,
                'state' => $state,
                'tone' => $tone,
                'last_sync' => $d->last_synced_at ? Carbon::parse($d->last_synced_at)->format('d M, h:i A') : '—',
                'last_sync_human' => $d->last_synced_at ? Carbon::parse($d->last_synced_at)->diffForHumans() : 'never',
                'sync_count' => (int) $d->last_sync_count,
                'ping_status' => $d->last_ping_status ?? 'unknown',
                'ping_error' => $d->last_ping_error,
                'health' => match ($state) {
                    'online' => 'Healthy', 'delayed' => 'Degraded', default => 'Unreachable'
                },
            ];
        });

        // ── Punches for the day (drives volume + method mix + discovery) ──────
        $punches = AttendancePunch::whereDate('punch_date', $date->toDateString())->get();
        $summaries = AttendanceDailySummary::where('date', $date->toDateString())->get();

        $totalPunches = $punches->count() ?: (int) $summaries->sum('raw_punch_count');
        $syncedEmployees = $summaries->count();

        // Face / RFID / Fingerprint mix
        $byMethod = $punches->groupBy(fn ($p) => $p->method ?? 'other')->map->count();
        $methodTotal = max(1, $punches->count());
        $methods = [
            ['label' => 'Face', 'key' => 'face', 'color' => '#6366f1', 'count' => (int) ($byMethod['face'] ?? 0)],
            ['label' => 'RFID Card', 'key' => 'id_card', 'color' => '#0ea5e9', 'count' => (int) (($byMethod['id_card'] ?? 0) + ($byMethod['physical_card'] ?? 0))],
            ['label' => 'Fingerprint', 'key' => 'fingerprint', 'color' => '#14b8a6', 'count' => (int) ($byMethod['fingerprint'] ?? 0)],
            ['label' => 'Other / GPS', 'key' => 'other', 'color' => '#a1a1aa', 'count' => (int) ($byMethod['other'] ?? 0)],
        ];
        foreach ($methods as &$m) {
            $m['pct'] = (int) round($m['count'] / $methodTotal * 100);
        }
        unset($m);

        // Device serials seen in the day's data, with their punch counts.
        $registeredNames = $devices->pluck('name')->map(fn ($n) => strtolower($n))->all();
        $discovered = $punches->pluck('device_serial')
            ->merge($summaries->pluck('device_serial'))
            ->filter()
            ->countBy()
            ->map(fn ($count, $serial) => ['serial' => $serial, 'punches' => $count])
            ->reject(fn ($d) => in_array(strtolower($d['serial']), $registeredNames, true))
            ->values();

        // ── Sync log (recent) ─────────────────────────────────────────────────
        $logs = AttendanceDailySummary::with('employee.user')
            ->whereNotNull('synced_at')
            ->orderByDesc('synced_at')
            ->limit(12)
            ->get()
            ->map(fn ($s) => [
                'employee' => $s->employee?->user?->name ?? ('PIN '.$s->employee_code),
                'date' => $s->date->format('d M'),
                'punches' => (int) $s->raw_punch_count,
                'device' => $s->device_serial ?? '—',
                'synced' => $s->synced_at ? Carbon::parse($s->synced_at)->format('d M h:i A') : '—',
            ])->all();

        // ── Fleet-wide offline alert ──────────────────────────────────────────
        $newestSync = $devices->pluck('last_sync_human')->first();
        $anyOnline = $devices->contains(fn ($d) => $d['state'] === 'online')
            || AttendanceDailySummary::whereNotNull('synced_at')->where('synced_at', '>=', now()->subMinutes(30))->exists();

        return view('livewire.attendance.biometric-control', array_merge([
            'devices' => $devices->all(),
            'discovered' => $discovered->all(),
            'methods' => $methods,
            'totalPunches' => $totalPunches,
            'syncedEmployees' => $syncedEmployees,
            'logs' => $logs,
            'anyOnline' => $anyOnline,
            'dateLabel' => $date->isToday() ? 'Today' : $date->format('d M Y'),
        ], $this->queueStatus()))->layout('layouts.app', ['title' => 'Biometric Control Center']);
    }

    /**
     * Queue-worker health for the admin indicator: online when the heartbeat
     * job (scheduled every minute) was processed within the last 3 minutes.
     *
     * @return array{queueOnline:bool, queuePending:int, queueFailed:int, queueHeartbeat:?string}
     */
    protected function queueStatus(): array
    {
        $beatAt = Cache::get('queue:heartbeat_at');
        $online = $beatAt !== null && (int) $beatAt >= now()->subMinutes(3)->timestamp;

        try {
            $pending = (int) DB::table('jobs')->count();
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $pending = 0;
            $failed = 0;
        }

        return [
            'queueOnline' => $online,
            'queuePending' => $pending,
            'queueFailed' => $failed,
            'queueHeartbeat' => $beatAt ? Carbon::createFromTimestamp($beatAt)->diffForHumans() : null,
        ];
    }
}
