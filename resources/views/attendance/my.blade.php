@use('App\Enums\AttendanceMode')
@use('App\Enums\PunchMethod')
@use('App\Support\UserAgent')
@use('Illuminate\Support\Facades\Storage')

<flux:main class="min-h-screen bg-[#F5F6F8] dark:bg-white/5 p-4 md:p-6" x-data="{
    currentTime: '',
    updateClock() { this.currentTime = new Date().toLocaleTimeString('en-IN', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
}" x-init="updateClock(); setInterval(() => updateClock(), 1000)">

@php
    $emp = auth()->user()->employee;

    $presentCount = (int) ($stats['present'] ?? 0);
    $lateCount    = (int) ($stats['late'] ?? 0);
    $leaveCount   = (int) ($stats['leaves'] ?? 0);
    $onTimeCount  = max(0, $presentCount - $lateCount);

    // Mirrors AttendanceTracker::computeStats() so the "of N days" denominator
    // matches the period the stats were actually computed for.
    $pStart = match($statsPeriod) {
        'today'      => now()->startOfDay(),
        'this_week'  => now()->startOfWeek(\Carbon\Carbon::SUNDAY),
        'last_month' => now()->subMonth()->startOfMonth(),
        'quarter'    => now()->firstOfQuarter(),
        '3_months'   => now()->subMonths(2)->startOfMonth(),
        'year'       => now()->startOfYear(),
        'custom'     => \Carbon\Carbon::parse($rangeFrom ?? now()->startOfMonth()),
        default      => now()->startOfMonth(),
    };
    $pEnd = match(true) {
        $statsPeriod === 'last_month' => now()->subMonth()->endOfMonth(),
        $statsPeriod === 'custom' && $rangeTo => \Carbon\Carbon::parse($rangeTo),
        default => now(),
    };
    if ($pEnd->gt(now())) { $pEnd = now(); }
    $totalWorkingDays = max(1, (int) $pStart->diffInDaysFiltered(fn($d) => ! $d->isSunday(), $pEnd));

    $attPct = round(min(100, ($presentCount / $totalWorkingDays) * 100), 1);
    $score  = (int) ($analytics['attendance_score'] ?? 0);
    $compliance = (int) ($analytics['shift_compliance'] ?? 0);
    $avgBreak = (int) ($analytics['avg_break'] ?? 0);

    $stdHours = (float) ($shift->standard_hours ?? 9);
    $otHours  = round(collect($chartDaily)->sum(fn($d) => max(0, (float) $d['hours'] - $stdHours)), 1);
    $otDays   = collect($chartDaily)->filter(fn($d) => (float) $d['hours'] > $stdHours)->count();
    $otMinTotal = (int) round($otHours * 60);
    $avgWorkMin = $presentCount > 0 ? (int) round(collect($chartDaily)->sum('hours') * 60 / max(1, $presentCount)) : 0;

    // Today live state
    $heroMode = AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $workMode);
    $isIn   = $todayAttendance && ! $todayAttendance->check_out;
    $isDone = $todayAttendance && $todayAttendance->check_out;
    // Working minutes come ONLY from validated sessions (PunchTimeline engine)
    // when punch data exists; the attendance row is just the web-punch fallback.
    $workedMin = 0;
    if (($punchJourney['raw_count'] ?? 0) > 0) {
        $workedMin = (int) $punchJourney['working_minutes'];
    } elseif ($todayAttendance && $todayAttendance->check_in) {
        $endT = $todayAttendance->check_out ?? now();
        $workedMin = max(0, (int) $todayAttendance->check_in->diffInMinutes($endT) - (int) ($todayAttendance->break_minutes ?? 0));
    }
    $targetMin = (int) round($stdHours * 60);
    $progress  = $targetMin > 0 ? min(100, (int) round($workedMin / $targetMin * 100)) : 0;
    $workedLabel = intdiv($workedMin, 60).'h '.str_pad((string) ($workedMin % 60), 2, '0', STR_PAD_LEFT).'m';
    $targetLabel = intdiv($targetMin, 60).'h '.($targetMin % 60).'m';
    $remainingMin = max(0, $targetMin - $workedMin);
    $liveStart = $isIn ? $todayAttendance->check_in->timestamp : null;

    // Punch summary
    $sum = $todaySummary;
    $inM  = PunchMethod::tryFrom((string) ($todayAttendance->check_in_method ?? $sum?->first_punch_method));
    $outM = PunchMethod::tryFrom((string) ($todayAttendance->check_out_method ?? $sum?->last_punch_method));
    $punchMethods = collect([$inM, $outM])->filter()->unique();
    $punchSource = $punchMethods->isNotEmpty() ? $punchMethods->map->label()->implode(' + ') : '—';
    $breakMin = ($punchJourney['raw_count'] ?? 0) > 0
        ? (int) $punchJourney['break_minutes']
        : (int) ($todayAttendance->break_minutes ?? $sum?->break_minutes ?? 0);
    $totalPunches = (int) ($sum?->raw_punch_count ?? ($punchJourney['raw_count'] ?? 0));
    $deviceName = $biometricDevice?->name ?? $sum?->device_serial ?? '—';
    $lastSync = $biometricDevice?->last_synced_at ?? $sum?->synced_at;
    $connected = (bool) ($lastSync && \Carbon\Carbon::parse($lastSync)->gt(now()->subMinutes(30)));

    $missingCount = count($attendanceAlerts);
    $expectedLogout = ($shift && $shift->end_time) ? \Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '—';
@endphp


{{-- ══════════════ REDESIGNED HERO (Pulse Attendance · slice 1) ══════════════ --}}
<style>
.pa{--pa-surface:#fff;--pa-surface-2:#F7F6F3;--pa-surface-3:#F0EEEA;--pa-border:#EAE8E4;--pa-border-2:#DDD9D3;
  --pa-ink:#1B1A18;--pa-muted:#6C6862;--pa-faint:#9A958E;--pa-accent:#F97316;--pa-accent-ink:#EA580C;--pa-accent-soft:#FFF1E7;
  --pa-present:#0F9D6E;--pa-present-soft:#E4F6EE;--pa-warn:#B45309;--pa-warn-soft:#FBEFDD;--pa-danger:#D64545;--pa-danger-soft:#FBEBEB;
  --pa-ring:rgba(249,115,22,.32);--pa-ease:cubic-bezier(.32,.72,0,1);
  color:var(--pa-ink);font-variant-numeric:tabular-nums}
.dark .pa{--pa-surface:#151517;--pa-surface-2:#1B1B1E;--pa-surface-3:#222226;--pa-border:#26262B;--pa-border-2:#33333A;
  --pa-ink:#F1EFEC;--pa-muted:#A29C93;--pa-faint:#726D66;--pa-accent:#FB923C;--pa-accent-ink:#FDBA74;--pa-accent-soft:#2A1710;
  --pa-present:#34D399;--pa-present-soft:#122A22;--pa-warn:#F0A94B;--pa-warn-soft:#2C2113;--pa-danger:#F17878;--pa-danger-soft:#2C1717;--pa-ring:rgba(251,146,60,.4)}
.pa .num{font-variant-numeric:tabular-nums}
.pa-cmd{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.pa-cmd h1{margin:0;font-size:21px;font-weight:680;letter-spacing:-.02em;color:var(--pa-ink)}
.pa-cmd p{margin:2px 0 0;color:var(--pa-muted);font-size:13px}
.pa-seg{display:inline-flex;background:var(--pa-surface-2);border:1px solid var(--pa-border);border-radius:10px;padding:3px}
.pa-seg button{border:0;background:transparent;color:var(--pa-muted);font-size:12.5px;font-weight:560;padding:5px 11px;border-radius:7px;transition:all .16s var(--pa-ease)}
.pa-seg button.on{background:var(--pa-surface);color:var(--pa-ink);box-shadow:0 1px 2px rgba(0,0,0,.06);font-weight:620}
.pa-range{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 11px;border:1px solid var(--pa-border-2);border-radius:10px;background:var(--pa-surface);color:var(--pa-muted);transition:all .16s var(--pa-ease)}
.pa-range.on{border-color:var(--pa-accent);box-shadow:0 0 0 3px var(--pa-ring);color:var(--pa-accent-ink)}
.pa-range input{border:0;background:transparent;color:var(--pa-ink);font-size:12px;font-family:inherit;outline:0;width:116px;font-variant-numeric:tabular-nums}
.pa-range .lbl{font-size:9.5px;font-weight:640;text-transform:uppercase;letter-spacing:.05em}
.pa-pill{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 13px;border-radius:10px;border:1px solid var(--pa-border-2);
  background:var(--pa-surface);color:var(--pa-ink);font-size:13px;font-weight:560;transition:all .16s var(--pa-ease)}
.pa-pill:hover{background:var(--pa-surface-2);border-color:var(--pa-faint);transform:translateY(-1px)}
.pa-primary{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 15px;border-radius:10px;border:1px solid var(--pa-accent-ink);
  background:var(--pa-accent);color:#fff;font-size:13px;font-weight:600;box-shadow:0 1px 2px var(--pa-ring);transition:all .16s var(--pa-ease)}
.pa-primary:hover{filter:brightness(1.06);box-shadow:0 4px 14px var(--pa-ring);transform:translateY(-1px)}
.pa-hero{display:grid;grid-template-columns:1.15fr 1fr 1.15fr;gap:1px;background:var(--pa-border);border:1px solid var(--pa-border);
  border-radius:20px;overflow:hidden;box-shadow:0 2px 4px rgba(28,27,26,.04),0 8px 24px rgba(28,27,26,.06)}
.dark .pa-hero{box-shadow:0 2px 4px rgba(0,0,0,.3),0 10px 28px rgba(0,0,0,.5)}
.pa-hero>div{background:var(--pa-surface);padding:20px 22px}
.pa-status{display:inline-flex;align-items:center;gap:7px;font-weight:640;font-size:12px;padding:5px 10px;border-radius:20px}
.pa-status.work{background:var(--pa-present-soft);color:var(--pa-present)}
.pa-status.done{background:var(--pa-surface-3);color:var(--pa-muted)}
.pa-status.out{background:var(--pa-warn-soft);color:var(--pa-warn)}
.pa-status .beat{width:7px;height:7px;border-radius:50%;background:currentColor;animation:pabeat 1.8s var(--pa-ease) infinite}
@keyframes pabeat{0%,100%{box-shadow:0 0 0 0 currentColor}70%{box-shadow:0 0 0 6px transparent}}
.pa-worked{font-size:33px;font-weight:720;letter-spacing:-.03em;margin:14px 0 2px;color:var(--pa-ink)}
.pa-cap{font-size:12.5px;color:var(--pa-muted)}
.pa-io{display:flex;gap:22px;margin-top:16px}
.pa-io .k{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--pa-faint);font-weight:640}
.pa-io .v{font-size:15px;font-weight:640;margin-top:2px;color:var(--pa-ink)}
.pa-io .m{font-size:11px;color:var(--pa-faint)}
.pa-ringwrap{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px}
.pa-ring{position:relative;width:148px;height:148px}
.pa-ring svg{transform:rotate(-90deg)}
.pa-ring .trk{fill:none;stroke:var(--pa-surface-3);stroke-width:11}
.pa-ring .prg{fill:none;stroke:var(--pa-accent);stroke-width:11;stroke-linecap:round;stroke-dasharray:408;
  stroke-dashoffset:408;animation:padraw 1.2s var(--pa-ease) forwards}
.pa-ring .mid{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
.pa-ring .pct{font-size:29px;font-weight:720;letter-spacing:-.03em;color:var(--pa-ink)}
.pa-ring .pl{font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:var(--pa-faint);font-weight:640}
.pa-shiftmeta{display:flex;justify-content:space-between;width:100%;margin-top:12px;font-size:11.5px}
.pa-shiftmeta b{display:block;font-size:13.5px;font-weight:640;color:var(--pa-ink);margin-top:1px}
.pa-shiftmeta .f{color:var(--pa-faint)}
.pa-acts{display:flex;flex-direction:column;gap:9px;justify-content:center}
.pa-actlbl{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--pa-faint);font-weight:640;margin-bottom:2px}
.pa-action{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:11px;border:1px solid var(--pa-border);
  background:var(--pa-surface);text-align:left;width:100%;transition:all .16s var(--pa-ease);color:var(--pa-ink)}
.pa-action:hover{border-color:var(--pa-faint);background:var(--pa-surface-2);transform:translateY(-1px)}
.pa-action[disabled]{opacity:.45;pointer-events:none}
.pa-action .ic{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex:0 0 auto}
.pa-action .t{font-weight:600;font-size:13px}.pa-action .d{font-size:11px;color:var(--pa-faint)}
.pa-ic-pres{background:var(--pa-present-soft);color:var(--pa-present)}.pa-ic-warn{background:var(--pa-warn-soft);color:var(--pa-warn)}
.pa-ic-iris{background:var(--pa-accent-soft);color:var(--pa-accent-ink)}.pa-ic-danger{background:var(--pa-danger-soft);color:var(--pa-danger)}
@keyframes padraw{to{stroke-dashoffset:{{ round(408 - (408 * $progress / 100), 1) }}}}
@media(max-width:1024px){.pa-hero{grid-template-columns:1fr 1fr}.pa-actwrap{grid-column:1/-1;border-top:1px solid var(--pa-border)}}
@media(max-width:640px){.pa-hero{grid-template-columns:1fr}.pa-ringwrap{order:-1}}
@media(prefers-reduced-motion:reduce){.pa-ring .prg{animation:none;stroke-dashoffset:{{ round(408 - (408 * $progress / 100), 1) }}}}

/* Command/filter bar — position:relative + z-index so open dropdowns
   (clean-select is absolute z-50) sit ABOVE the positioned hero that follows. */
.pa-cmd{position:relative;z-index:40;row-gap:10px}
.pa-cmd-title{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:620;color:var(--pa-ink)}
.pa-cmd-title svg{color:var(--pa-faint)}
.pa-cmd-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
@media(max-width:900px){.pa-cmd-right{margin-left:0;width:100%}}
</style>

<div class="pa">
  {{-- Analytics header — global period, comparison & mode filters (GA4-style) --}}
  <div class="pa-cmd">
    <div class="pa-cmd-title"><flux:icon.clock class="size-4" /><span>My Attendance</span></div>
    <div class="pa-seg" role="tablist" aria-label="Range">
      @foreach(['today' => 'Today', 'this_week' => 'Week', 'this_month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year', 'custom' => 'Custom'] as $val => $label)
        <button wire:click="$set('statsPeriod', '{{ $val }}')" class="{{ $statsPeriod === $val ? 'on' : '' }}">{{ $label }}</button>
      @endforeach
    </div>
    {{-- Date range appears only in Custom mode — keeps the bar clean otherwise. --}}
    @if($statsPeriod === 'custom')
      <div class="pa-range on" title="Filter punches, logs & analytics by date range">
        <flux:icon.calendar-days class="size-3.5" />
        <input type="date" wire:model.live="rangeFrom" aria-label="From date" max="{{ now()->toDateString() }}">
        <span class="lbl">to</span>
        <input type="date" wire:model.live="rangeTo" aria-label="To date" max="{{ now()->toDateString() }}">
      </div>
    @endif
    <div class="pa-cmd-right">
      <x-clean-select model="compareMode" :live="true" title="Comparison window for KPI trends"
        :options="[['value' => 'prev_period', 'label' => 'vs Previous period'], ['value' => 'last_month', 'label' => 'vs Last month'], ['value' => 'last_year', 'label' => 'vs Last year']]" />
      <x-clean-select model="analyticsMode" :live="true"
        :options="[['value' => '', 'label' => 'All modes'], ...collect(AttendanceMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all()]" />
      <button wire:click="exportLog" class="pa-pill"><flux:icon.arrow-down-tray class="size-4" /> Export</button>
    </div>
  </div>

  {{-- Premium 3-column hero (45 / 30 / 25) --}}
  @php
      $hr = now()->hour;
      $heroGreet = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
      $heroName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
      $heroSuggIn = $shift ? \Carbon\Carbon::parse($shift->start_time)->subMinutes(10)->format('g:i A') : '10:20 AM';
  @endphp
  <style>
    .pa-hero2{display:grid;grid-template-columns:45% 30% 25%;background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:24px;box-shadow:0 12px 36px rgba(24,24,27,.06),0 2px 8px rgba(24,24,27,.03);overflow:hidden;position:relative}
    .dark .pa-hero2{box-shadow:0 16px 44px rgba(0,0,0,.5)}
    .pa-hero2::before{content:"";position:absolute;right:-70px;top:-110px;width:340px;height:340px;border-radius:50%;background:radial-gradient(circle,var(--pa-accent-soft),transparent 70%);opacity:.7;pointer-events:none}
    .pa-hero2>div{padding:30px 32px;position:relative}
    .pa-hC,.pa-hR{border-left:1px solid var(--pa-border)}
    .pa-hC{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
    .pa-hR{display:flex;flex-direction:column;gap:9px;justify-content:center}
    .pa-h-greet{font-size:14px;color:var(--pa-muted);font-weight:520}
    .pa-h-name{font-size:27px;font-weight:730;letter-spacing:-.025em;color:var(--pa-ink);margin:1px 0 3px}
    .pa-h-date{font-size:12.5px;color:var(--pa-faint);font-weight:500;margin-bottom:16px}
    .pa-h-timer{font-size:58px;font-weight:740;letter-spacing:-.045em;line-height:1;color:var(--pa-ink);margin:18px 0 3px;font-variant-numeric:tabular-nums}
    .pa-h-cap{font-size:12.5px;color:var(--pa-muted)}
    .pa-h-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:22px}
    .pa-h-meta .k{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-faint);font-weight:640}
    .pa-h-meta .v{font-size:14.5px;font-weight:660;color:var(--pa-ink);margin-top:3px;font-variant-numeric:tabular-nums}
    .pa-h-tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
    .pa-h-tag{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:560;color:var(--pa-ink);background:var(--pa-surface-2);border:1px solid var(--pa-border);border-radius:20px;padding:6px 12px}
    .pa-h-tag svg{width:14px;height:14px;color:var(--pa-faint)}
    .pa-h-tag.mode{background:var(--pa-accent-soft);color:var(--pa-accent-ink);border-color:transparent}
    .pa-h-tag.mode svg{color:var(--pa-accent-ink)}
    .pa-h-ring{position:relative;width:172px;height:172px}
    .pa-h-ring svg{transform:rotate(-90deg)}
    .pa-h-ring .trk{fill:none;stroke:var(--pa-surface-3);stroke-width:12}
    .pa-h-ring .prg{fill:none;stroke:var(--pa-accent);stroke-width:12;stroke-linecap:round;stroke-dasharray:471;stroke-dashoffset:471;animation:pahdraw 1.4s var(--pa-ease) forwards}
    .pa-h-ring .mid{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center}
    .pa-h-ring .big{font-size:44px;font-weight:740;letter-spacing:-.03em;color:var(--pa-ink);line-height:1}
    .pa-h-ring .sm{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--pa-faint);font-weight:640;margin-top:4px}
    .pa-h-cprog{width:100%;max-width:200px;margin-top:22px}
    .pa-h-cprog .lbl{display:flex;justify-content:space-between;font-size:11.5px;color:var(--pa-muted);margin-bottom:6px}
    .pa-h-cprog .lbl b{color:var(--pa-ink);font-weight:640}
    .pa-h-bar{height:8px;border-radius:6px;background:var(--pa-surface-3);overflow:hidden}
    .pa-h-bar i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--pa-accent),var(--pa-accent-ink));width:0;animation:pahfill 1.3s var(--pa-ease) forwards}
    .pa-h-cta{display:flex;align-items:center;gap:11px;padding:12px 14px;border-radius:14px;border:1px solid var(--pa-border);background:var(--pa-surface);width:100%;text-align:left;transition:.16s var(--pa-ease);color:var(--pa-ink)}
    .pa-h-cta:hover{border-color:var(--pa-faint);background:var(--pa-surface-2);transform:translateY(-1px)}
    .pa-h-cta.primary{background:var(--pa-accent);border-color:var(--pa-accent-ink);color:#fff;box-shadow:0 4px 14px var(--pa-ring)}
    .pa-h-cta.primary:hover{filter:brightness(1.06);background:var(--pa-accent)}
    .pa-h-cta[disabled]{opacity:.5;pointer-events:none}
    .pa-h-cta .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto}
    .pa-h-cta.primary .ic{background:rgba(255,255,255,.2);color:#fff}
    .pa-h-cta .t{font-weight:620;font-size:13.5px;line-height:1.2}.pa-h-cta .d{font-size:11px;opacity:.72;margin-top:1px}
    .pa-smart{margin-top:5px;border-radius:14px;background:var(--pa-accent-soft);padding:14px 15px}
    .pa-smart .h{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:660;color:var(--pa-accent-ink)}
    .pa-smart p{margin:6px 0 0;font-size:12px;color:var(--pa-ink);line-height:1.45}
    @keyframes pahdraw{to{stroke-dashoffset:{{ round(471 - 471 * min(100, $score) / 100, 1) }}}}
    @keyframes pahfill{to{width:{{ $progress }}%}}
    @media(max-width:1024px){.pa-hero2{grid-template-columns:1fr 1fr}.pa-hR{grid-column:1/-1;border-left:0;border-top:1px solid var(--pa-border);flex-direction:row;flex-wrap:wrap}.pa-hR .pa-h-cta{flex:1;min-width:160px}.pa-smart{flex-basis:100%}}
    @media(max-width:680px){.pa-hero2{grid-template-columns:1fr}.pa-hC{border-left:0;border-top:1px solid var(--pa-border)}.pa-h-timer{font-size:48px}}

    /* ── Greeting ── */
    .pa-greet{margin-bottom:20px}
    .pa-greet-t{font-size:25px;font-weight:760;letter-spacing:-.03em;color:var(--pa-ink)}
    .pa-greet-s{font-size:13.5px;color:var(--pa-muted);margin-top:4px}

    /* ── 5-card hero ── */
    .pa-h4{display:grid;grid-template-columns:1.35fr 1fr 1fr 1fr 1fr;gap:16px}
    @media(max-width:1280px){.pa-h4{grid-template-columns:repeat(3,1fr)}}
    @media(max-width:820px){.pa-h4{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:520px){.pa-h4{grid-template-columns:1fr}}
    .pa-hprog{height:8px;border-radius:6px;background:var(--pa-surface-3);overflow:hidden;margin-top:14px}
    .pa-hprog i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--pa-present),#22c55e);transition:width .8s var(--pa-ease)}
    .pa-hlink{display:inline-flex;align-items:center;gap:4px;margin-top:8px;font-size:12px;font-weight:640;color:var(--pa-accent-ink);background:none;border:0;cursor:pointer;padding:0}
    .pa-hlink:hover{text-decoration:underline}
    .pa-hcard-score .pa-h-ring-sm{width:140px !important;height:140px !important}
    .pa-hcard-score .pa-h-ring-sm .big{font-size:39px}
    .pa-hcard-score .pa-h-ring-sm .sm{font-size:11px}
    .pa-hcard-score .pa-hscore{gap:20px}
    .pa-hcard-score .pa-hband{font-size:18px}
    /* Worked Today — the primary hero card */
    .pa-hcard-worked{border-color:var(--pa-accent);background:linear-gradient(165deg,var(--pa-accent-soft),var(--pa-surface) 62%);box-shadow:0 2px 6px var(--pa-ring),0 14px 32px rgba(24,24,27,.07)}
    .pa-hcard-worked .pa-hbig{font-size:38px}
    .pa-hcard-worked .pa-hct,.pa-hcard-worked .pa-hct svg{color:var(--pa-accent-ink)}
    .pa-hcard-worked .pa-hprog{height:9px}
    .pa-hcard{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;padding:22px 24px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05);display:flex;flex-direction:column;transition:transform .2s var(--pa-ease),box-shadow .2s}
    .pa-hcard:hover{transform:translateY(-3px);box-shadow:0 2px 4px rgba(24,24,27,.05),0 16px 34px rgba(24,24,27,.08)}
    .pa-hct{font-size:12px;font-weight:640;color:var(--pa-muted);display:flex;align-items:center;gap:8px;margin-bottom:14px}
    .pa-hct svg{color:var(--pa-faint)}
    .pa-hbig{font-size:29px;font-weight:760;letter-spacing:-.03em;color:var(--pa-ink);line-height:1;font-variant-numeric:tabular-nums}
    .pa-hsub{font-size:12px;color:var(--pa-muted);margin-top:7px}
    .pa-hfoot{font-size:11.5px;color:var(--pa-faint);margin-top:auto;padding-top:12px}
    .pa-hspark{width:100%;height:26px;display:block;margin-top:12px}
    .pa-hbadges{margin-top:11px}
    .pa-hbadge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:660;padding:4px 11px;border-radius:999px}
    .pa-hbadge.ok{background:var(--pa-present-soft);color:var(--pa-present)}
    .pa-hbadge.warn{background:var(--pa-warn-soft);color:var(--pa-warn)}
    .pa-hbadge.live{background:var(--pa-present-soft);color:var(--pa-present)}
    .pa-hscore{display:flex;align-items:center;gap:16px}
    .pa-h-ring-sm{width:112px !important;height:112px !important;flex:0 0 auto}
    .pa-h-ring-sm .big{font-size:29px}
    .pa-h-ring-sm .sm{font-size:10px;letter-spacing:0}
    .pa-hband{font-size:16px;font-weight:740;color:var(--pa-ink)}
    .pa-htrend{font-size:12px;font-weight:640;margin-top:4px}
    .pa-htrend.up{color:var(--pa-present)}
    .pa-htrend.down{color:var(--pa-danger)}
    .pa-livedot{width:7px;height:7px;border-radius:50%;background:var(--pa-present);display:inline-block;animation:pabeat 1.6s var(--pa-ease) infinite;margin-left:auto}
    .pa-actbar{display:flex;flex-wrap:wrap;gap:14px;align-items:stretch;margin-top:20px}
    .pa-actbtns{display:flex;gap:10px;flex-wrap:wrap;flex:2;min-width:280px}
    .pa-actbtns .pa-h-cta{flex:1;min-width:150px}
    /* Clock In — the prominent primary action */
    .pa-actbtns .pa-h-cta.primary{flex:1.5;padding:16px 18px}
    .pa-actbtns .pa-h-cta.primary .t{font-size:15px}
    .pa-actbtns .pa-h-cta.primary .ic{width:40px;height:40px}
    /* Smart Status — richer insight card */
    .pa-actsmart{margin:0;flex:1.6;min-width:250px;display:flex;flex-direction:column;justify-content:center;background:linear-gradient(135deg,var(--pa-accent-soft),var(--pa-surface) 75%);border:1px solid var(--pa-border);border-radius:16px;padding:16px 20px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.04)}
    .pa-actsmart .h{font-size:12.5px;font-weight:700}
    .pa-actsmart p{font-size:13.5px;font-weight:500;line-height:1.5;margin-top:7px}
  </style>
  @php
      // Live hero ticker counts VALIDATED working time (engine sessions), not
      // raw elapsed-since-check-in: closed-session minutes + the running
      // session. Web-punch fallback: elapsed since check-in minus breaks.
      $heroLiveStartMs = null;
      $heroBaseMin = $workedMin;
      if (($punchJourney['raw_count'] ?? 0) > 0) {
          if ($punchJourney['live']) {
              $heroLiveStartMs = $punchJourney['live_start_ms'];
              $heroBaseMin = $workedMin - (int) $punchJourney['live_elapsed_minutes'];
          }
      } elseif ($isIn && $todayAttendance?->check_in) {
          $heroLiveStartMs = $todayAttendance->check_in->getTimestampMs();
          $heroBaseMin = -$breakMin;
      }
  @endphp
  {{-- Greeting --}}
  <div class="pa-greet">
    <div class="pa-greet-t">{{ $heroGreet }}, {{ $heroName }} 👋</div>
    <div class="pa-greet-s">Here's your attendance summary for {{ now()->format('l, d F Y') }}.</div>
  </div>

  @php
    $pj = $punchJourney;
    $scoreBand = $score >= 90 ? 'Excellent' : ($score >= 75 ? 'Good' : ($score >= 60 ? 'Fair' : 'Needs work'));
    $scoreDelta = (isset($monthlyScore, $prevMonthlyScore) && $prevMonthlyScore !== null) ? (int) round($monthlyScore - $prevMonthlyScore) : null;
    $workedPct = min(100, (int) round($workedMin / max(1, $targetMin) * 100));
    $firstInLate = (bool) ($todayAttendance?->is_late);
    $shiftEndMin2 = $shift?->end_time ? ((int) \Carbon\Carbon::parse($shift->end_time)->format('H')) * 60 + (int) \Carbon\Carbon::parse($shift->end_time)->format('i') : null;
    $lastOutMin2 = $todayAttendance?->check_out ? $todayAttendance->check_out->hour * 60 + $todayAttendance->check_out->minute : null;
    $earlyExit = $shiftEndMin2 !== null && $lastOutMin2 !== null && $lastOutMin2 < $shiftEndMin2;
    $breakLabel = intdiv($breakMin, 60).'h '.str_pad((string) ($breakMin % 60), 2, '0', STR_PAD_LEFT).'m';
    $breakAllowance = (int) ($shift->break_duration ?? 0);
    $breakOver = max(0, $breakMin - $breakAllowance);
    $scoreMsg = $score >= 75 ? 'Keep it up!' : ($score >= 60 ? 'Room to improve' : 'Needs attention');
  @endphp

  {{-- 5-card hero: Score · Worked · First In · Last Out · Total Break --}}
  <div class="pa-h4" data-reveal
      x-data="{ baseMin: {{ $heroBaseMin }}, startMs: {{ $heroLiveStartMs ?? 'null' }}, live: '{{ $workedLabel }}',
        tick(){ if(this.startMs===null) return; const t=Math.max(0,this.baseMin+Math.floor((Date.now()-this.startMs)/60000)); this.live=Math.floor(t/60)+'h '+String(t%60).padStart(2,'0')+'m'; } }"
      x-init="tick(); if(startMs!==null) setInterval(()=>tick(),1000)">
    {{-- Attendance Score --}}
    <div class="pa-hcard pa-hcard-score" data-reveal-item>
      <div class="pa-hct"><flux:icon.shield-check class="size-4" /> Attendance Score</div>
      <div class="pa-hscore">
        <div class="pa-h-ring pa-h-ring-sm">
          <svg width="104" height="104" viewBox="0 0 172 172"><circle class="trk" cx="86" cy="86" r="75"/><circle class="prg" cx="86" cy="86" r="75"/></svg>
          <div class="mid"><div class="big num" x-data="countUp('{{ $score }}')" x-text="display" wire:key="hs-{{ $score }}">{{ $score }}</div><div class="sm">/100</div></div>
        </div>
        <div>
          <div class="pa-hband" style="color:{{ $score >= 75 ? 'var(--pa-present)' : ($score >= 60 ? 'var(--pa-warn)' : 'var(--pa-danger)') }}">{{ $scoreBand }}</div>
          <div class="pa-hsub" style="margin-top:2px">{{ $scoreMsg }}</div>
          <button type="button" wire:click="showScoreDecision('{{ today()->toDateString() }}')" class="pa-hlink">View details <flux:icon.arrow-right class="size-3" /></button>
        </div>
      </div>
    </div>
    {{-- Worked Today · primary card --}}
    <div class="pa-hcard pa-hcard-worked" data-reveal-item>
      <div class="pa-hct"><flux:icon.clock class="size-4" /> Worked Today @if($pj['live'])<span class="pa-livedot"></span>@endif</div>
      <div class="pa-hbig num" x-text="live">{{ $workedLabel }}</div>
      <div class="pa-hsub">of {{ $targetLabel }} expected</div>
      <div class="pa-hprog"><i style="width: {{ $workedPct }}%"></i></div>
      <div class="pa-hfoot"><b style="color:var(--pa-present)">{{ $workedPct }}%</b> of expected</div>
    </div>
    {{-- First In --}}
    <div class="pa-hcard" data-reveal-item>
      <div class="pa-hct"><flux:icon.arrow-right-end-on-rectangle class="size-4" style="color:var(--pa-present)" /> First In</div>
      <div class="pa-hbig num">{{ $pj['first_in'] ?? '—' }}</div>
      <div class="pa-hbadges">@if($pj['first_in'])<span class="pa-hbadge {{ $firstInLate ? 'warn' : 'ok' }}">{{ $firstInLate ? 'Late' : 'On Time' }}</span>@endif</div>
      <div class="pa-hfoot">Shift: {{ $shift ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A').' – '.\Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '—' }}</div>
    </div>
    {{-- Last Out --}}
    <div class="pa-hcard" data-reveal-item>
      <div class="pa-hct"><flux:icon.arrow-left-start-on-rectangle class="size-4" style="color:var(--pa-danger)" /> Last Out</div>
      <div class="pa-hbig num">{{ $pj['last_out'] ?? '—' }}</div>
      <div class="pa-hbadges">@if($pj['last_out'])<span class="pa-hbadge {{ $earlyExit ? 'warn' : 'ok' }}">{{ $earlyExit ? 'Early Exit' : 'On Time' }}</span>@elseif($pj['live'])<span class="pa-hbadge live">Not yet punched out</span>@endif</div>
      <div class="pa-hfoot">Expected: {{ $expectedLogout }}</div>
    </div>
    {{-- Total Break --}}
    <div class="pa-hcard" data-reveal-item>
      <div class="pa-hct"><flux:icon.pause-circle class="size-4" style="color:var(--pa-warn)" /> Total Break</div>
      <div class="pa-hbig num">{{ $breakLabel }}</div>
      <div class="pa-hbadges">@if($breakOver > 0)<span class="pa-hbadge warn">↑ {{ $breakOver }}m over limit</span>@elseif($breakAllowance > 0)<span class="pa-hbadge ok">within allowance</span>@endif</div>
      <div class="pa-hfoot">Allowed: {{ $breakAllowance }}m</div>
    </div>
  </div>

  {{-- Action bar: clock in/out, break, regularize + smart status (all preserved) --}}
  <div class="pa-actbar" data-reveal>
    <div class="pa-actbtns">
      @if(! $todayAttendance)
        <button type="button" class="pa-h-cta primary" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })"><span class="ic"><flux:icon.arrow-right-end-on-rectangle class="size-4" /></span><div><div class="t">Clock in</div><div class="d">Selfie + location</div></div></button>
      @elseif($isIn)
        <button type="button" class="pa-h-cta primary" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })"><span class="ic"><flux:icon.arrow-left-start-on-rectangle class="size-4" /></span><div><div class="t">Clock out</div><div class="d">End day · {{ $expectedLogout }}</div></div></button>
      @else
        <button type="button" class="pa-h-cta" disabled><span class="ic pa-ic-pres"><flux:icon.check-circle class="size-4" /></span><div><div class="t">Day complete</div><div class="d">See you tomorrow</div></div></button>
      @endif
      @if($activeBreak)
        <button type="button" class="pa-h-cta" wire:click="endBreak"><span class="ic pa-ic-pres"><flux:icon.play class="size-4" /></span><div><div class="t">End break</div><div class="d">Resume timer</div></div></button>
      @else
        <button type="button" class="pa-h-cta" wire:click="startBreak" @if(! $isIn) disabled @endif><span class="ic pa-ic-warn"><flux:icon.pause class="size-4" /></span><div><div class="t">Start break</div><div class="d">Pause timer</div></div></button>
      @endif
      <button type="button" class="pa-h-cta" wire:click="openRegularisation('{{ today()->toDateString() }}')"><span class="ic pa-ic-iris"><flux:icon.pencil-square class="size-4" /></span><div><div class="t">Regularize</div><div class="d">Fix a punch</div></div></button>
    </div>
    <div class="pa-smart pa-actsmart">
      <div class="h"><flux:icon.sparkles class="size-4" /> Smart status</div>
      <p>{{ $isIn ? "On track — ".intdiv($remainingMin,60)."h ".($remainingMin%60)."m to your ".$targetLabel." goal." : ($isDone ? "Day complete — great work today!" : "Clock in by ".$heroSuggIn." to stay on time.") }}</p>
    </div>
  </div>
</div>


<div class="space-y-6 mt-6">

{{-- ═══════════════ ATTENDANCE HEALTH + QUICK ACTIONS ═══════════════ --}}
{{-- ═══════════════ ATTENDANCE HEALTH + QUICK ACTIONS (slice 4a) ═══════════════ --}}
<style>
.pa-panel{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05);padding:16px 18px}
.pa-panel-h{font-size:14px;font-weight:640;color:var(--pa-ink);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.pa-panel-sub{margin-left:auto;font-size:11px;font-weight:500;color:var(--pa-faint);text-transform:capitalize}
.pa-kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
@media(min-width:640px){.pa-kpis{grid-template-columns:repeat(3,1fr)}}
@media(min-width:1024px){.pa-kpis{grid-template-columns:repeat(5,1fr)}}
.pa-kpi{display:flex;align-items:center;gap:11px;border:1px solid var(--pa-border);background:var(--pa-surface-2);border-radius:13px;padding:11px 12px;transition:all .16s var(--pa-ease)}
.pa-kpi:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.06);border-color:var(--pa-border-2)}
.pa-kpi-ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto}
.pa-kpi-v{font-size:16px;font-weight:700;letter-spacing:-.02em;color:var(--pa-ink);line-height:1;font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pa-kpi-l{font-size:9.5px;font-weight:640;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-muted);margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pa-kpi-t{font-size:9.5px;color:var(--pa-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pa-qa{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.pa-qa-item{display:flex;flex-direction:column;align-items:center;gap:7px;border:1px solid var(--pa-border);background:var(--pa-surface-2);border-radius:12px;padding:12px 8px;text-align:center;text-decoration:none;transition:all .16s var(--pa-ease)}
.pa-qa-item:hover{transform:translateY(-2px);border-color:var(--pa-accent);background:var(--pa-surface)}
.pa-qa-ic{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:var(--pa-accent-soft);color:var(--pa-accent-ink)}
.pa-qa-l{font-size:10.5px;font-weight:600;color:var(--pa-muted)}
.pa-kpis2{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
@media(min-width:680px){.pa-kpis2{grid-template-columns:repeat(3,1fr)}}
@media(min-width:1100px){.pa-kpis2{grid-template-columns:repeat(5,1fr)}}
.pa-kpi2{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;padding:18px 20px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 4px 12px rgba(24,24,27,.03);transition:transform .2s var(--pa-ease),box-shadow .2s,border-color .2s}
.pa-kpi2:hover{transform:translateY(-3px);box-shadow:0 2px 4px rgba(24,24,27,.05),0 14px 30px rgba(24,24,27,.08);border-color:var(--pa-border-2)}
.pa-kpi2 .top{display:flex;align-items:center;justify-content:space-between}
.pa-kpi2 .ic{width:36px;height:36px;border-radius:11px;display:grid;place-items:center}
.pa-kpi2 .tr{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:640;padding:3px 8px;border-radius:999px;background:var(--pa-surface-2);color:var(--pa-muted)}
.pa-kpi2 .tr.up{background:var(--pa-present-soft);color:var(--pa-present)}
.pa-kpi2 .tr.down{background:var(--pa-danger-soft);color:var(--pa-danger)}
.pa-kpi2 .v{font-size:26px;font-weight:720;letter-spacing:-.025em;margin-top:12px;line-height:1;color:var(--pa-ink);font-variant-numeric:tabular-nums}
.pa-kpi2 .l{font-size:12.5px;color:var(--pa-muted);margin-top:5px;font-weight:500}
.pa-kpi2 .cmp{font-size:11px;color:var(--pa-faint);margin-top:1px}
.pa-kpi2 .spark{margin-top:11px;height:28px;width:100%;display:block}
</style>
<div class="pa">
  {{-- KPI overview · full width --}}
  <div>
    <div class="pa-panel-h" style="margin-bottom:16px;font-size:15px">Attendance health
      <span class="pa-panel-sub">{{ str_replace('_', ' ', $statsPeriod) }}@if(($comparison['has_data'] ?? false)) · {{ $comparison['label'] }}@endif</span>
    </div>
    @php
        $prodIndex = (int) min(100, max(0, $compliance));
        $workingH = round(collect($chartDaily)->sum('hours'), 1);

        // Real sparkline from the period's daily hours (last 14 days, normalised
        // into the 120×28 viewBox, higher hours → higher line). Null when there
        // aren't 2+ points — the UI simply omits the spark.
        $sparkDays = collect($chartDaily)->filter(fn ($d) => $d['hours'] !== null)->take(-14)->values();
        $hoursSpark = null;
        if ($sparkDays->count() >= 2) {
            $maxH = max(0.1, (float) $sparkDays->max('hours'));
            $stepX = 120 / ($sparkDays->count() - 1);
            $hoursSpark = $sparkDays->map(fn ($d, $i) => round($i * $stepX, 1).','.round(26 - ((float) $d['hours'] / $maxH * 22), 1))->implode(' ');
        }

        // Real trend chips from the comparison engine — null delta hides the chip.
        $chip = function (?int $delta, string $suffix = '', bool $goodWhenUp = true) {
            if ($delta === null || $delta === 0) { return null; }
            $up = $delta > 0;
            return ['txt' => ($up ? '▲ ' : '▼ ').abs($delta).$suffix, 'dir' => ($up === $goodWhenUp) ? 'up' : 'down'];
        };
        $cmpChips = [
            'present' => $chip($comparison['present'] ?? null),
            'hours' => $chip($comparison['hours'] ?? null, 'h'),
            'ontime' => $chip($comparison['on_time_pct'] ?? null, '%'),
            'late' => $chip($comparison['late'] ?? null, '', false),
        ];

        // [label, value, icon, color, chip|plainText, caption, spark?]
        // Five compact health cards (reference layout), all from real data.
        $monthlyPct = $monthlyScore !== null ? (int) round($monthlyScore) : $score;
        $healthBand = $monthlyPct >= 90 ? 'Excellent' : ($monthlyPct >= 75 ? 'Good' : ($monthlyPct >= 60 ? 'Fair' : 'Needs work'));
        $streakWord = \Illuminate\Support\Str::plural('day', $onTimeStreak);
        $kpis = [
            ['Attendance Health', $monthlyPct.'%', 'shield-check', '#0F9D6E', $healthBand, 'this month', $hoursSpark],
            ['Attendance Streak', $onTimeStreak.' '.$streakWord, 'fire', '#F97316', $onTimeStreak >= $bestStreak && $onTimeStreak > 0 ? 'personal best' : 'best '.$bestStreak, 'on-time run', null],
            ['Monthly Goal', $monthlyPct.'%', 'flag', '#8B5CF6', null, 'attendance goal', null],
            ['Productivity', $prodIndex.'%', 'chart-bar', '#0F9D6E', $cmpChips['ontime'] ?? null, 'focus '.intdiv($workedMin,60).'h '.($workedMin%60).'m', $hoursSpark],
            ['Leave Balance', rtrim(rtrim(number_format($leaveBalance, 1), '0'), '.').' days', 'calendar-days', '#2F6FEB', 'available', 'CSL + MDL pool', null],
        ];
    @endphp
    <div class="pa-kpis2" data-reveal>
      @foreach($kpis as [$lbl, $val, $ic, $clr, $tr, $cmp, $spk])
        <x-attendance.kpi-card :label="$lbl" :value="$val" :icon="$ic" :color="$clr"
          :trend="$tr" :caption="$cmp" :spark="$spk" :comparison-label="$comparison['label'] ?? ''" />
      @endforeach
    </div>
  </div>
</div>

{{-- ═══════════════ AI ATTENDANCE COACH (analytics engine · Rule/Priority 2) ═══════════════ --}}
@php
    $riskToneMap = ['danger' => 'var(--pa-danger)', 'warn' => 'var(--pa-warn)', 'good' => 'var(--pa-present)', 'muted' => 'var(--pa-faint)'];
    $cRisk = $coach['risk'] ?? ['level' => 'Low', 'tone' => 'good', 'text' => ''];
    $cScore = $coach['score_change'] ?? [];
    $cArrival = $coach['arrival_trend'] ?? [];
    $cLogout = $coach['logout_trend'] ?? [];
    $cBreak = $coach['break_analysis'] ?? [];
    $cConsistency = $coach['consistency'] ?? ['pct' => 100, 'text' => ''];
    $cHealth = $coach['health'] ?? ['label' => '—', 'tone' => 'muted', 'text' => ''];
    $cMetrics = $coach['metrics'] ?? ['predicted_score' => $score, 'consistency' => 100, 'overtime_pred' => 0, 'risk' => $cRisk];
@endphp
<style>
.pa-copilot{background:linear-gradient(155deg,var(--pa-accent-soft),var(--pa-surface) 52%);border:1px solid var(--pa-border);border-radius:18px;padding:26px 28px;position:relative;overflow:hidden;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05)}
.dark .pa-copilot{box-shadow:0 12px 36px rgba(0,0,0,.45)}
.pa-copilot .head{display:flex;align-items:center;gap:13px;margin-bottom:20px}
.pa-copilot .avatar{width:42px;height:42px;border-radius:13px;background:linear-gradient(145deg,var(--pa-accent),var(--pa-accent-ink));display:grid;place-items:center;color:#fff;box-shadow:0 6px 18px var(--pa-ring);flex:0 0 auto}
.pa-copilot .avatar svg{width:22px;height:22px}
.pa-copilot .title{font-size:16px;font-weight:660;color:var(--pa-ink)}
.pa-copilot .sub{font-size:12px;color:var(--pa-faint)}
.pa-aichip{display:inline-flex;align-items:center;gap:6px;background:var(--pa-surface);border:1px solid var(--pa-border);color:var(--pa-accent-ink);font-size:11px;font-weight:640;padding:5px 11px;border-radius:20px}
.pa-aichip .ld{width:6px;height:6px;border-radius:50%;background:var(--pa-present);box-shadow:0 0 0 0 var(--pa-present);animation:pabeat 1.8s var(--pa-ease) infinite}
.pa-msg{font-size:17px;line-height:1.55;color:var(--pa-ink);font-weight:500;max-width:680px;letter-spacing:-.01em}
.pa-msg b{font-weight:680;color:var(--pa-accent-ink)}
.pa-copilot .stats{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin:22px 0 6px}
@media(min-width:820px){.pa-copilot .stats{grid-template-columns:repeat(4,1fr)}}
.pa-cstat{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:16px;padding:16px 18px}
.pa-cstat .k{font-size:11px;color:var(--pa-muted);font-weight:600}
.pa-cstat .v{font-size:26px;font-weight:730;color:var(--pa-ink);margin-top:6px;font-variant-numeric:tabular-nums;line-height:1}
.pa-clabel{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--pa-faint);margin:20px 0 9px}
.pa-rec{display:flex;gap:13px;background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:16px;padding:16px 18px;margin-top:14px}
.pa-rec .ic{width:32px;height:32px;border-radius:10px;background:var(--pa-accent-soft);color:var(--pa-accent-ink);display:grid;place-items:center;flex:0 0 auto}
.pa-rec .k{font-size:10.5px;font-weight:660;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-faint)}
.pa-rec p{margin:3px 0 0;font-size:13.5px;color:var(--pa-ink);line-height:1.5}
.pa-copilot .foot{display:flex;flex-wrap:wrap;gap:12px 22px;margin-top:16px;align-items:center}
.pa-chip-in{display:inline-flex;align-items:center;gap:8px;background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:20px;padding:8px 14px;font-size:13px;font-weight:560;color:var(--pa-ink)}
.pa-chip-in svg{width:15px;height:15px;color:var(--pa-faint)}
.pa-qastrip{display:flex;flex-wrap:wrap;gap:10px}
.pa-qas{display:inline-flex;align-items:center;gap:9px;padding:10px 15px;border-radius:14px;border:1px solid var(--pa-border);background:var(--pa-surface);font-size:13px;font-weight:560;color:var(--pa-ink);text-decoration:none;transition:.16s var(--pa-ease)}
.pa-qas:hover{border-color:var(--pa-accent);background:var(--pa-surface-2);transform:translateY(-2px)}
.pa-qas .ic{width:30px;height:30px;border-radius:9px;background:var(--pa-accent-soft);color:var(--pa-accent-ink);display:grid;place-items:center}
</style>
<div class="pa">
  <div class="pa-copilot" data-reveal>
    <div class="head">
      <span class="avatar"><flux:icon.sparkles /></span>
      <div style="flex:1"><div class="title">AI Attendance Coach</div><div class="sub">Your personal attendance assistant</div></div>
      <span class="pa-aichip"><span class="ld"></span>AI · live</span>
    </div>
    <p class="pa-msg">{{ $coach['headline'] ?? 'Building your attendance coaching profile.' }}</p>

    {{-- Why your score changed — computed from the score breakdown factors --}}
    @if(($cScore['reason'] ?? null))
      <div class="pa-rec" style="margin-top:2px">
        <span class="ic" style="background:{{ ($cScore['delta'] ?? 0) < 0 ? 'rgba(244,63,94,.12)' : 'var(--pa-accent-soft)' }};color:{{ ($cScore['delta'] ?? 0) < 0 ? '#f43f5e' : 'var(--pa-present)' }}"><flux:icon.chart-bar-square class="size-4" /></span>
        <div><div class="k">Why your score changed</div><p>{{ $cScore['reason'] }}</p></div>
      </div>
    @endif

    <div class="pa-clabel" style="margin-top:16px">Health &amp; risk</div>
    <div class="stats" style="margin-top:0">
      <div class="pa-cstat"><div class="k">Predicted score</div><div class="v num">{{ $cMetrics['predicted_score'] }}</div></div>
      <div class="pa-cstat"><div class="k">Consistency</div><div class="v num">{{ $cMetrics['consistency'] }}%</div></div>
      <div class="pa-cstat"><div class="k">Attendance health</div><div class="v" style="font-size:20px;color:{{ $riskToneMap[$cHealth['tone']] ?? 'var(--pa-ink)' }}">{{ $cHealth['label'] }}</div></div>
      <div class="pa-cstat"><div class="k">Warning risk</div><div class="v" style="font-size:20px;color:{{ $riskToneMap[$cRisk['tone']] ?? 'var(--pa-ink)' }}">{{ $cRisk['level'] }}</div></div>
    </div>

    {{-- Key insights (dynamic trend read-outs) --}}
    <div class="pa-clabel">Key insights</div>
    <div class="stats" style="grid-template-columns:1fr;gap:9px;margin-top:0">
      @foreach([
        ['arrow-right-end-on-rectangle', $cArrival['text'] ?? null],
        ['arrow-left-start-on-rectangle', $cLogout['text'] ?? null],
        ['pause', $cBreak['text'] ?? null],
        ['shield-check', $cRisk['text'] ?? null],
      ] as [$icon, $line])
        @if($line)
          <div style="display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--pa-ink);background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:11px;padding:10px 12px">
            <flux:icon :icon="$icon" class="size-4" style="color:var(--pa-accent-ink);flex:0 0 auto;margin-top:1px" /><span>{{ $line }}</span>
          </div>
        @endif
      @endforeach
    </div>

    <div class="pa-rec">
      <span class="ic"><flux:icon.light-bulb class="size-4" /></span>
      <div><div class="k">Recommendation</div><p>{{ $coach['recommendation'] ?? 'Keep your routine steady.' }}</p></div>
    </div>

    {{-- Weekly coaching tips --}}
    @if(!empty($coach['tips']))
      <div style="margin-top:12px">
        <div class="k" style="font-size:10.5px;font-weight:660;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-faint);margin-bottom:6px">Weekly coaching tips</div>
        <div style="display:flex;flex-direction:column;gap:6px">
          @foreach($coach['tips'] as $tip)
            <div style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--pa-muted)"><flux:icon.check-circle class="size-3.5" style="color:var(--pa-accent-ink);flex:0 0 auto;margin-top:2px" /><span>{{ $tip }}</span></div>
          @endforeach
        </div>
      </div>
    @endif

    <div class="foot">
      @forelse($coach['achievements'] ?? [] as $ach)
        <span class="pa-chip-in"><flux:icon :icon="$ach['icon']" class="size-4" style="color:var(--pa-accent-ink)" /> {{ $ach['label'] }}</span>
      @empty
        <span style="font-size:12.5px;color:var(--pa-muted)">Earn achievements by building an on-time streak and perfect-score days.</span>
      @endforelse
    </div>
  </div>
</div>

{{-- Quick actions strip (relocated below the AI Coach so it doesn't compete with the hero) --}}
@php
    $canApprove = auth()->user()->canApproveLeave();
    $qa = [
        ['Attendance History', 'clock', $canApprove && \Route::has('attendance.employees') ? route('attendance.employees') : '#attendance-log', null],
        ['Apply Leave', 'calendar-days', \Route::has('time-off.my') ? route('time-off.my') : '#', null],
        ['Regularization', 'pencil-square', '#', 'regularise'],
        ['Download Report', 'arrow-down-tray', '#', 'export'],
        ['My Overtime', 'bolt', \Route::has('overtime.my') ? route('overtime.my') : '#', null],
        $canApprove && \Route::has('attendance.team')
            ? ['My Team', 'users', route('attendance.team'), null]
            : ['WFH Requests', 'home', \Route::has('wfh.my') ? route('wfh.my') : '#attendance-log', null],
    ];
@endphp
<div class="pa">
  <div class="pa-panel-h" style="margin-bottom:14px;font-size:15px">Quick actions <span class="pa-panel-sub">jump to the tasks you use most</span></div>
  <div class="pa-qastrip" data-reveal>
    @foreach($qa as [$label, $icon, $href, $action])
      <a href="{{ $href }}"
         @if($action === 'regularise') wire:click.prevent="openRegularisation('{{ today()->toDateString() }}')" @endif
         @if($action === 'export') wire:click.prevent="exportLog" @endif
         class="pa-qas"><span class="ic"><flux:icon :icon="$icon" class="size-4" /></span>{{ $label }}</a>
    @endforeach
  </div>
</div>

{{-- ═══════════════ SMART ALERTS ═══════════════ --}}
@if(empty($attendanceAlerts))
    <div class="flex items-center gap-3 rounded-[18px] border border-emerald-200 bg-gradient-to-r from-emerald-50 to-white p-4 shadow-sm">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-lg shadow-emerald-200"><flux:icon.check-badge class="size-5" /></span>
        <div><div class="text-sm font-black text-emerald-900">No attendance issues today</div><div class="text-xs text-emerald-700">All your punches are complete and reconciled.</div></div>
    </div>
@else
    <div class="rounded-[18px] border border-amber-300 bg-amber-50 dark:bg-amber-900/15 p-4 shadow-sm">
        <div class="flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-200"><flux:icon.exclamation-triangle class="size-5" /></span>
            <div><div class="text-sm font-black text-amber-900">{{ count($attendanceAlerts) }} attendance {{ \Illuminate\Support\Str::plural('alert', count($attendanceAlerts)) }}</div><div class="text-xs text-amber-700">Regularise to correct working hours, overtime &amp; attendance.</div></div>
        </div>
        <div class="mt-3 space-y-2">
            @foreach($attendanceAlerts as $alert)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 px-3 py-2">
                    <div class="flex items-center gap-2 text-xs"><flux:icon.exclamation-circle class="size-4 text-amber-500" /><span class="font-bold text-amber-900">{{ $alert['label'] }}</span><span class="text-amber-700">· {{ $alert['detail'] }}</span></div>
                    @if($alert['action'] ?? true)
                        <button wire:click="openRegularisation('{{ $alert['date'] }}')" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.pencil-square class="size-3" /> Regularize</button>
                    @else
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Info</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ═══════════════ LATE-MARK WARNING (Rule 10: 3+ lates this month) ═══════════════ --}}
@if($analytics['late_warning'] ?? false)
    <div class="rounded-[18px] border border-rose-300 bg-gradient-to-r from-rose-50 to-white dark:from-rose-950/25 dark:to-zinc-900 p-4 shadow-sm" data-reveal>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-rose-500 text-white shadow-lg shadow-rose-200"><flux:icon.shield-exclamation class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-black text-rose-900 dark:text-rose-200">Late-mark warning — {{ $analytics['late_month_count'] }} late arrivals this month</div>
                <div class="text-xs text-rose-700 dark:text-rose-300">
                    {{ $analytics['late_threshold'] ?? 3 }}+ late marks trigger a formal warning letter and reduce your attendance &amp; performance scores
                    (−{{ $analytics['late_penalty'] }} pts applied).
                    @if(($analytics['late_consecutive'] ?? 0) >= 2) {{ $analytics['late_consecutive'] }} consecutive late days — @endif
                    Arrive before your shift's grace cutoff to recover.
                </div>
            </div>
            <span class="rounded-full bg-rose-100 px-3 py-1 text-[10px] font-black uppercase tracking-wider text-rose-600 dark:bg-rose-900/40 dark:text-rose-300">Warning</span>
        </div>
    </div>
@endif

{{-- ═══════════════ TODAY'S ATTENDANCE JOURNEY (slice 2b) ═══════════════ --}}
<style>
.pa-jcard{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:16px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05)}
.pa-jhead{display:flex;align-items:center;gap:10px;padding:15px 18px 6px}
.pa-jhead h3{margin:0;font-size:14px;font-weight:640;color:var(--pa-ink)}
.pa-jhead .sub{font-size:12px;color:var(--pa-faint)}
.pa-jlink{margin-left:auto;color:var(--pa-accent-ink);font-weight:600;font-size:12px;background:none;border:0}
.pa-jlink:hover{text-decoration:underline}
.pa-tl{padding:4px 18px 14px;max-height:440px;overflow-y:auto}
.pa-tl-item{display:grid;grid-template-columns:56px 26px 1fr;align-items:start}
.pa-tl-time{font-size:12.5px;font-weight:640;text-align:right;padding:8px 12px 0 0;white-space:nowrap;color:var(--pa-ink);font-variant-numeric:tabular-nums}
.pa-tl-time small{display:block;font-size:10px;color:var(--pa-faint);font-weight:500}
.pa-tl-rail{position:relative;display:flex;justify-content:center}
.pa-tl-dot{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;z-index:2;margin-top:6px;border:2px solid var(--pa-surface);box-shadow:0 0 0 1px var(--pa-border);color:#fff}
.pa-tl-line{position:absolute;top:26px;bottom:-8px;width:2px;background:var(--pa-border);left:50%;transform:translateX(-50%)}
.pa-tl-body{padding:6px 0 12px 12px;min-width:0}
.pa-tl-c{background:var(--pa-surface-2);border:1px solid var(--pa-border);border-radius:11px;padding:9px 12px;cursor:pointer;transition:all .16s var(--pa-ease);text-align:left;width:100%}
.pa-tl-c:hover{border-color:var(--pa-border-2);background:var(--pa-surface);transform:translateX(2px)}
.pa-tl-t{font-weight:600;font-size:13.5px;display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--pa-ink)}
.pa-tl-meta{font-size:11.5px;color:var(--pa-faint);margin-top:3px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.pa-tl-meta .mi{display:inline-flex;align-items:center;gap:3px}
.pa-tl-more{margin-top:7px;border-top:1px solid var(--pa-border);padding-top:7px;font-size:11px;color:var(--pa-muted);display:flex;flex-wrap:wrap;gap:10px}
.pa-b{font-size:10px;font-weight:640;padding:2px 8px;border-radius:20px;white-space:nowrap}
.pa-b-pres{background:var(--pa-present-soft);color:var(--pa-present)}.pa-b-warn{background:var(--pa-warn-soft);color:var(--pa-warn)}
.pa-b-dan{background:var(--pa-danger-soft);color:var(--pa-danger)}.pa-b-iris{background:var(--pa-accent-soft);color:var(--pa-accent-ink)}
.pa-d-pres{background:var(--pa-present)}.pa-d-warn{background:var(--pa-warn)}.pa-d-dan{background:var(--pa-danger)}.pa-d-iris{background:var(--pa-accent)}.pa-d-mut{background:var(--pa-faint)}
.pa-gap{font-size:11px;color:var(--pa-faint);display:flex;align-items:center;gap:8px;padding:1px 0 1px 56px}
.pa-gap .ln{flex:1;height:1px;background:repeating-linear-gradient(90deg,var(--pa-border) 0 4px,transparent 4px 8px)}
.pa-tl-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:var(--pa-faint);padding:44px 0}
.pa-live-dot{width:9px;height:9px;border-radius:50%;background:var(--pa-accent);animation:pabeat 1.8s var(--pa-ease) infinite}
.pa-work{display:grid;grid-template-columns:1.55fr 1fr;gap:18px;align-items:start}
@media(max-width:960px){.pa-work{grid-template-columns:1fr}}
.pa-rail{display:flex;flex-direction:column;gap:16px;align-self:start}
.pa-rc{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;padding:16px 18px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05)}
.pa-rc-h{font-size:13.5px;font-weight:640;color:var(--pa-ink);display:flex;align-items:center;gap:8px}
.pa-rc-sub{font-size:11px;color:var(--pa-faint);font-weight:500}
.pa-streak{background:linear-gradient(140deg,rgba(234,106,44,.08),var(--pa-surface))}
.pa-flame{width:44px;height:44px;border-radius:12px;background:linear-gradient(150deg,#F7A34B,#EA6A2C);display:grid;place-items:center;color:#fff;box-shadow:0 4px 14px rgba(234,106,44,.28);flex:0 0 auto}
.pa-streak-n{font-size:27px;font-weight:720;letter-spacing:-.03em;line-height:1;color:var(--pa-ink)}
.pa-streak-l{font-size:12px;color:var(--pa-muted)}
.pa-streak-week{display:flex;gap:6px;margin-top:14px}
.pa-sd{flex:1;height:6px;border-radius:4px;background:var(--pa-surface-3)}
.pa-sd.on{background:#EA6A2C}
.pa-ins{display:flex;gap:10px;padding:10px 0;border-top:1px solid var(--pa-border)}
.pa-ins:first-of-type{border-top:0}
.pa-ins .mk{width:22px;height:22px;border-radius:6px;display:grid;place-items:center;flex:0 0 auto;margin-top:1px}
.pa-ins p{margin:0;font-size:12.5px;line-height:1.45;color:var(--pa-ink)}
.pa-bm{display:flex;align-items:center;gap:10px;margin-top:11px}
.pa-bm .who{font-size:12px;width:70px;color:var(--pa-muted)}
.pa-bm.me .who{color:var(--pa-ink);font-weight:640}
.pa-bm .bar{flex:1;height:8px;border-radius:6px;background:var(--pa-surface-3);overflow:hidden}
.pa-bm .fill{height:100%;border-radius:6px}
.pa-bm .val{font-size:12px;font-weight:640;width:34px;text-align:right;color:var(--pa-ink);font-variant-numeric:tabular-nums}
</style>
{{-- ═══ Enterprise punch timeline (session-based · neutral IN/OUT · GSAP) ═══ --}}
<style>
.pa-jsec{margin-top:18px}
.pa-jcard2{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05)}
.pa-jhead2{display:flex;align-items:center;gap:12px;padding:18px 22px 14px}
.pa-jhead2 h3{margin:0;font-size:15px;font-weight:680;letter-spacing:-.01em;color:var(--pa-ink)}
.pa-jhead2 .sub{font-size:12px;color:var(--pa-faint);margin-top:2px}
.pa-jhead2 .fixlink{margin-left:auto;color:var(--pa-accent-ink);font-weight:620;font-size:12.5px;background:none;border:0;cursor:pointer}
.pa-jhead2 .fixlink:hover{text-decoration:underline}
/* Attendance stat card */
.pa-acard{display:grid;grid-template-columns:1.5fr repeat(5,1fr);gap:0;border-top:1px solid var(--pa-border);border-bottom:1px solid var(--pa-border);background:var(--pa-surface-2)}
@media(max-width:900px){.pa-acard{grid-template-columns:1fr 1fr 1fr}}
.pa-atile{padding:16px 20px;border-right:1px solid var(--pa-border)}
.pa-atile:last-child{border-right:0}
.pa-atile .lbl{font-size:10.5px;font-weight:640;text-transform:uppercase;letter-spacing:.07em;color:var(--pa-faint);display:flex;align-items:center;gap:6px}
.pa-atile .val{font-size:19px;font-weight:720;letter-spacing:-.02em;color:var(--pa-ink);margin-top:6px;font-variant-numeric:tabular-nums}
.pa-atile.hero .val{font-size:30px;font-weight:760}
.pa-atile .sub2{font-size:11px;color:var(--pa-muted);margin-top:2px}
.pa-livebadge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:720;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-present);background:var(--pa-present-soft);padding:2px 8px;border-radius:20px;vertical-align:middle;margin-left:8px}
.pa-livebadge .dot{width:6px;height:6px;border-radius:50%;background:var(--pa-present);animation:pabeat 1.6s var(--pa-ease) infinite}
/* Horizontal timeline */
.pa-hz-scroll{overflow-x:auto;overflow-y:hidden;padding:0 10px}
.pa-hz-track{position:relative;display:flex;align-items:flex-start;gap:14px;padding:104px 34px 26px;min-width:max-content;margin:0 auto}
.pa-hz-rail{position:absolute;left:76px;right:76px;top:124px;height:3px;border-radius:3px;background:var(--pa-border);z-index:0}
.pa-hz-rail-fill{position:absolute;left:76px;top:124px;height:3px;border-radius:3px;width:0;background:linear-gradient(90deg,var(--pa-present),#3B82F6 40%,#F59E0B 72%,var(--pa-danger));z-index:1}
.pa-hz-node{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;min-width:98px;flex:0 0 auto}
.pa-hz-dot{width:46px;height:46px;border-radius:50%;display:grid;place-items:center;color:#fff;border:3.5px solid var(--pa-surface);box-shadow:0 3px 10px rgba(24,24,27,.16);cursor:pointer;transition:box-shadow .18s var(--pa-ease),transform .18s var(--pa-ease)}
.pa-hz-node:hover .pa-hz-dot{box-shadow:0 6px 20px rgba(24,24,27,.26);transform:translateY(-3px)}
.pa-hz-node:hover .pa-hz-time{color:var(--pa-accent-ink)}
.pa-hz-dot.t-first_in{background:var(--pa-present)}
.pa-hz-dot.t-in{background:#3B82F6}
.pa-hz-dot.t-out{background:#F59E0B}
.pa-hz-dot.t-last_out{background:var(--pa-danger)}
.pa-hz-dot.t-live{background:var(--pa-present);animation:pahzpulse 2s var(--pa-ease) infinite}
@keyframes pahzpulse{0%{box-shadow:0 0 0 0 rgba(15,157,110,.4)}70%{box-shadow:0 0 0 13px rgba(15,157,110,0)}100%{box-shadow:0 0 0 0 rgba(15,157,110,0)}}
@media(prefers-reduced-motion:reduce){.pa-hz-dot.t-live{animation:none;box-shadow:0 0 0 6px var(--pa-present-soft)}}
.pa-hz-dot.t-missing{background:var(--pa-warn-soft);border:2px dashed var(--pa-warn);color:var(--pa-warn);box-shadow:none;animation:pashake 2.4s var(--pa-ease) infinite}
@keyframes pashake{0%,88%,100%{transform:translateX(0)}90%{transform:translateX(-2.5px)}92%{transform:translateX(2.5px)}94%{transform:translateX(-2px)}96%{transform:translateX(2px)}98%{transform:translateX(-1px)}}
@media(prefers-reduced-motion:reduce){.pa-hz-dot.t-missing{animation:none}}
.pa-hz-node.miss .pa-hz-time{color:var(--pa-warn)}
.pa-hz-dir.d-missing{color:var(--pa-warn);background:var(--pa-warn-soft)}
/* Needs-regularization banner */
.pa-regbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 22px 16px;padding:13px 16px;border-radius:14px;background:var(--pa-warn-soft);border:1px solid var(--pa-warn)}
.pa-regbar .ic{width:34px;height:34px;border-radius:10px;background:var(--pa-warn);color:#fff;display:grid;place-items:center;flex:0 0 auto}
.pa-regbar .tx{font-size:12.5px;color:var(--pa-ink);line-height:1.45}
.pa-regbar .tx b{font-weight:680}
.pa-regbar .cta{margin-left:auto;display:inline-flex;align-items:center;gap:7px;background:var(--pa-warn);color:#fff;border:0;border-radius:10px;padding:9px 15px;font-size:12.5px;font-weight:640;cursor:pointer;white-space:nowrap}
.pa-regbar .cta:hover{filter:brightness(.95)}
.pa-srow.miss .sd{color:var(--pa-warn)}
.pa-srow.miss .sp{color:var(--pa-warn)}
/* Approved-regularization punch: emerald ring so the corrected punch reads as verified */
.pa-hz-node.reg .pa-hz-dot{box-shadow:0 0 0 3px var(--pa-present-soft),0 2px 8px rgba(24,24,27,.14)}
.pa-hz-node.reg .pa-hz-time::after{content:"✓";margin-left:4px;color:var(--pa-present);font-weight:800}
/* System auto punch-out: violet ring to distinguish it from a real punch */
.pa-hz-node.auto .pa-hz-dot{background:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,.16),0 2px 8px rgba(24,24,27,.14)}
.pa-hz-time{font-size:13px;font-weight:680;color:var(--pa-ink);margin-top:10px;font-variant-numeric:tabular-nums;white-space:nowrap}
.pa-hz-dir{font-size:10px;font-weight:720;letter-spacing:.06em;margin-top:3px;padding:1px 8px;border-radius:20px}
.pa-hz-dir.d-in{color:var(--pa-present);background:var(--pa-present-soft)}
.pa-hz-dir.d-out{color:var(--pa-danger);background:var(--pa-danger-soft)}
/* tooltip */
.pa-hz-tip{position:absolute;bottom:calc(100% - 84px);left:50%;transform:translate(-50%,-8px) scale(.96);transform-origin:bottom center;width:200px;background:var(--pa-ink);color:var(--pa-surface);border-radius:12px;padding:11px 13px;font-size:11.5px;line-height:1.5;box-shadow:0 12px 30px rgba(24,24,27,.28);opacity:0;pointer-events:none;transition:opacity .16s var(--pa-ease),transform .16s var(--pa-ease);z-index:20}
.pa-hz-tip::after{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);border:6px solid transparent;border-top-color:var(--pa-ink)}
.pa-hz-node:hover .pa-hz-tip{opacity:1;transform:translate(-50%,0) scale(1)}
.pa-hz-tip .tt{font-size:13px;font-weight:720;margin-bottom:5px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.pa-hz-tip .tr{display:flex;align-items:center;gap:6px;opacity:.82}
.pa-hz-tip .tr .k{width:78px;opacity:.7;flex:0 0 auto}
.pa-hz-tip a{color:#93C5FD;font-weight:640}
/* live banner */
.pa-livebar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:0 22px 18px;padding:14px 18px;border-radius:14px;background:linear-gradient(135deg,var(--pa-present-soft),var(--pa-surface));border:1px solid var(--pa-present-soft)}
.pa-livebar .lw{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:680;color:var(--pa-present)}
.pa-livebar .lw .dot{width:9px;height:9px;border-radius:50%;background:var(--pa-present);animation:pabeat 1.6s var(--pa-ease) infinite}
.pa-livebar .el{margin-left:auto;font-size:24px;font-weight:760;letter-spacing:-.02em;color:var(--pa-ink);font-variant-numeric:tabular-nums}
.pa-livebar .st{font-size:12px;color:var(--pa-muted)}
/* bottom grid: sessions + rail */
.pa-jgrid{display:grid;grid-template-columns:1.35fr 1fr;gap:18px;margin-top:18px}
@media(max-width:960px){.pa-jgrid{grid-template-columns:1fr}}
/* 3-column row: Session Summary (35%) · Working Hours Breakdown (40%) · rail (25%) */
/* Balanced 3-column row: equal-height columns, 24px gutters, cards that fill */
.pa-jgrid-3{grid-template-columns:35fr 40fr 25fr;gap:24px;align-items:stretch;margin-top:20px}
@media(max-width:1180px){.pa-jgrid-3{grid-template-columns:1fr 1fr}}
@media(max-width:820px){.pa-jgrid-3{grid-template-columns:1fr}}
/* Breakdown fills the middle column; tiles 2-up, stacked bar pinned to the bottom */
.pa-hb-col{display:flex;flex-direction:column}
.pa-hb-col .pa-hb-grid{grid-template-columns:repeat(2,1fr) !important}
.pa-hb-col .pa-hb-tile{padding:12px 13px}
.pa-hb-col .pa-hb-v{font-size:14px}
.pa-hb-col .pa-hb-bar{margin-top:auto;height:14px}
.pa-hb-col .pa-hb-key{margin-bottom:2px}
.pa-sesscard{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;padding:15px 16px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05);display:flex;flex-direction:column}
.pa-sesscard .sh{font-size:13.5px;font-weight:660;color:var(--pa-ink);display:flex;align-items:center;gap:8px;margin-bottom:12px}
.pa-srow{display:grid;grid-template-columns:78px 1fr auto;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--pa-border)}
.pa-srow:first-of-type{border-top:0}
.pa-srow .sn{font-size:11px;font-weight:680;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-faint)}
.pa-srow .sp{font-size:13px;font-weight:600;color:var(--pa-ink);font-variant-numeric:tabular-nums}
.pa-srow .sd{font-size:14px;font-weight:720;color:var(--pa-ink);font-variant-numeric:tabular-nums;text-align:right}
.pa-srow.live .sd{color:var(--pa-present)}
.pa-stot{display:flex;align-items:center;justify-content:space-between;margin-top:12px;padding-top:13px;border-top:2px solid var(--pa-border)}
.pa-stot .tl{font-size:12px;font-weight:640;color:var(--pa-muted)}
.pa-stot .tv{font-size:20px;font-weight:760;letter-spacing:-.02em;color:var(--pa-ink);font-variant-numeric:tabular-nums}
/* Premium per-session card */
.pa-sess{border:1px solid var(--pa-border);border-radius:13px;padding:11px 13px;margin-bottom:8px;background:var(--pa-surface-2);transition:border-color .16s,box-shadow .16s}
.pa-sess:hover{border-color:var(--pa-border-2);box-shadow:0 4px 14px rgba(24,24,27,.05)}
.pa-sess.live{border-color:var(--pa-present);background:var(--pa-present-soft)}
.pa-sess.miss{border-color:var(--pa-warn);background:var(--pa-warn-soft)}
.pa-sess-h{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px}
.pa-sess-n{font-size:12.5px;font-weight:700;color:var(--pa-ink);display:flex;align-items:center;gap:7px}
.pa-sess-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-present);background:var(--pa-surface);padding:2px 7px;border-radius:999px}
.pa-sess-t{font-size:11.5px;font-weight:600;color:var(--pa-muted);font-variant-numeric:tabular-nums}
.pa-sess-m{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.pa-sess-m .l{display:block;font-size:9px;font-weight:640;text-transform:uppercase;letter-spacing:.04em;color:var(--pa-faint)}
.pa-sess-m .v{display:block;font-size:14px;font-weight:720;color:var(--pa-ink);margin-top:2px;font-variant-numeric:tabular-nums}
.pa-sess-bar{height:6px;border-radius:5px;background:var(--pa-surface-3);overflow:hidden;margin-top:11px}
.pa-sess-bar i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,var(--pa-present),#22c55e);transition:width .8s var(--pa-ease)}
.pa-snote{margin-top:12px;font-size:11.5px;color:var(--pa-faint);display:flex;align-items:center;gap:7px}
.pa-swarn{margin-top:12px;font-size:12px;font-weight:600;color:var(--pa-danger);background:var(--pa-danger-soft);padding:9px 12px;border-radius:10px;display:flex;align-items:center;gap:8px}
.pa-hz-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:var(--pa-faint);padding:52px 0}
/* legend */
.pa-hz-leg{display:flex;gap:16px;flex-wrap:wrap;padding:12px 22px 18px;border-top:1px solid var(--pa-border)}
.pa-hz-leg span{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:560;color:var(--pa-muted)}
.pa-hz-leg i{width:10px;height:10px;border-radius:50%;display:inline-block}
</style>
@php
    $pj = $punchJourney;
    $hasPunch = ($pj['raw_count'] ?? 0) > 0;
    $fmt = function ($m) { $m = (int) $m; return $m < 60 ? $m.'m' : intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m'; };
@endphp
<div class="pa pa-jsec" x-data="punchTimeline({ live: {{ $pj['live'] ? 'true' : 'false' }}, startedAtMs: {{ $pj['live_start_ms'] ?? 'null' }} })">
  <div class="pa-jcard2">
    <div class="pa-jhead2">
      <div>
        <h3>Attendance journey</h3>
        <div class="sub">{{ $hasPunch ? $pj['session_count'].' '.\Illuminate\Support\Str::plural('session', $pj['session_count']).' · '.$pj['raw_count'].' '.\Illuminate\Support\Str::plural('punch', $pj['raw_count']) : 'No punches recorded today' }}</div>
      </div>
      <button type="button" class="fixlink" wire:click="openRegularisation('{{ today()->toDateString() }}')">Fix a punch →</button>
    </div>

    {{-- Attendance stat card --}}
    <div class="pa-acard">
      <div class="pa-atile hero">
        <div class="lbl"><flux:icon.clock class="size-3.5" /> Working today</div>
        <div class="val">{{ $hasPunch ? $fmt($pj['working_minutes']) : '—' }}
          @if($pj['live'])<span class="pa-livebadge"><span class="dot"></span>Live</span>@endif
        </div>
      </div>
      <div class="pa-atile"><div class="lbl">Started</div><div class="val">{{ $pj['first_in'] ? \Illuminate\Support\Str::before($pj['first_in'], ' ') : '—' }}</div><div class="sub2">{{ $pj['first_in'] ? \Illuminate\Support\Str::after($pj['first_in'], ' ') : 'not yet' }}</div></div>
      <div class="pa-atile"><div class="lbl">Current session</div><div class="val">@if($pj['live'])<span x-text="elapsed">{{ $fmt($pj['live_elapsed_minutes']) }}</span>@else{{ $hasPunch && count($pj['sessions']) ? end($pj['sessions'])['label'] : '—' }}@endif</div></div>
      <div class="pa-atile"><div class="lbl">Punches</div><div class="val">{{ $pj['raw_count'] }}</div>@if($pj['duplicate_count'] > 0)<div class="sub2">{{ $pj['duplicate_count'] }} duplicate ignored</div>@endif</div>
      <div class="pa-atile"><div class="lbl">First in</div><div class="val">{{ $pj['first_in'] ? \Illuminate\Support\Str::before($pj['first_in'], ' ') : '—' }}</div></div>
      <div class="pa-atile"><div class="lbl">Break</div><div class="val">{{ $fmt($pj['break_minutes']) }}</div></div>
    </div>

    @if($pj['live'])
      <div class="pa-livebar">
        <div class="lw"><span class="dot"></span>Currently working</div>
        <div class="st">Started {{ $pj['live_start_label'] }}</div>
        <div class="el" x-text="elapsed">{{ $fmt($pj['live_elapsed_minutes']) }}</div>
      </div>
    @endif

    {{-- Missing-punch notification --}}
    @if(! empty($pj['needs_regularization']))
      <div class="pa-regbar">
        <span class="ic"><flux:icon.exclamation-triangle class="size-4" /></span>
        <div class="tx"><b>A missing punch was detected in today's attendance.</b> Working hours can't be finalised until it's corrected — please submit a regularization request.</div>
        <button type="button" class="cta" wire:click="openRegularisation('{{ today()->toDateString() }}')"><flux:icon.pencil-square class="size-3.5" /> Request Regularization</button>
      </div>
    @endif

    {{-- Horizontal punch timeline --}}
    @if($hasPunch)
      <div class="pa-hz-scroll" wire:loading.class="opacity-40" wire:target="statsPeriod,rangeFrom,rangeTo">
        <div class="pa-hz-track">
          <div class="pa-hz-rail"></div>
          <div class="pa-hz-rail-fill" data-tl-progress></div>
          @foreach($pj['nodes'] as $node)
            @php
              $isMissing = $node['type'] === 'missing';
              $src = $node['source'] ?? '';
              // Unique icon per punch type: missing → alert, regularized → verified
              // badge, face/card → their auth icon, otherwise an IN/OUT arrow.
              $nodeIcon = match(true) {
                  $isMissing => 'exclamation-triangle',
                  $src === 'regularisation' => 'check-badge',
                  $src === 'auto' => 'bolt',
                  ! empty($node['method_icon']) => $node['method_icon'],
                  default => $node['dir'] === 'IN' ? 'arrow-right-end-on-rectangle' : 'arrow-left-start-on-rectangle',
              };
            @endphp
            <div class="pa-hz-node {{ $isMissing ? 'miss' : '' }} {{ $src === 'regularisation' ? 'reg' : '' }} {{ $src === 'auto' ? 'auto' : '' }}" data-tl-node @if($src === 'regularisation') data-tl-reg @endif>
              <div class="pa-hz-tip">
                <div class="tt"><span>{{ $node['time'] }}</span><span>{{ $isMissing ? 'Missing '.$node['dir'] : $node['dir'] }}</span></div>
                @if($isMissing)
                  <div class="tr">No matching punch — needs regularization.</div>
                @else
                  @if($node['method_label'])<div class="tr"><span class="k">Authentication</span><flux:icon :icon="$node['method_icon']" class="size-3" /> {{ $node['method_label'] }}</div>@endif
                  @if($node['device'])<div class="tr"><span class="k">Machine</span>{{ $node['device'] }}</div>@endif
                  @if($node['location'])<div class="tr"><span class="k">Gate</span>{{ $node['location'] }}</div>@endif
                  @if($node['verify'])<div class="tr"><span class="k">Verify</span>{{ $node['verify'] }}</div>@endif
                  @if($node['source'])<div class="tr"><span class="k">Source</span>{{ $node['source'] === 'web' ? 'Web punch' : ucfirst($node['source']) }}</div>@endif
                  @if(! empty($node['lat']) && ! empty($node['lng']))<div class="tr"><a href="https://www.google.com/maps?q={{ $node['lat'] }},{{ $node['lng'] }}" target="_blank" rel="noopener">View on map →</a></div>@endif
                @endif
              </div>
              <div class="pa-hz-dot t-{{ $node['type'] }}" @if($node['type'] === 'live') data-tl-live @endif><flux:icon :icon="$nodeIcon" class="size-4" /></div>
              <div class="pa-hz-time">{{ $node['time'] }}</div>
              <div class="pa-hz-dir {{ $isMissing ? 'd-missing' : 'd-'.strtolower($node['dir']) }}">{{ $isMissing ? 'MISSING' : $node['dir'] }}</div>
            </div>
          @endforeach
        </div>
      </div>
      @php
        $nodeSources = collect($pj['nodes']);
        $hasReg = $nodeSources->contains(fn ($n) => ($n['source'] ?? '') === 'regularisation');
        $hasAuto = $nodeSources->contains(fn ($n) => ($n['source'] ?? '') === 'auto');
        $hasFace = $nodeSources->contains(fn ($n) => str_contains(strtolower($n['method_label'] ?? ''), 'face'));
        $hasCard = $nodeSources->contains(fn ($n) => str_contains(strtolower($n['method_label'] ?? ''), 'card'));
      @endphp
      <div class="pa-hz-leg">
        <span><i style="background:var(--pa-present)"></i> First in</span>
        <span><i style="background:#3B82F6"></i> In</span>
        <span><i style="background:#F59E0B"></i> Out</span>
        <span><i style="background:var(--pa-danger)"></i> Last out</span>
        @if($hasFace)<span><flux:icon.face-smile class="size-3.5" /> Face</span>@endif
        @if($hasCard)<span><flux:icon.identification class="size-3.5" /> Card</span>@endif
        @if($hasReg)<span><flux:icon.check-badge class="size-3.5 text-emerald-500" /> Regularized</span>@endif
        @if($hasAuto)<span><i style="background:#8B5CF6"></i> Auto punch</span>@endif
        @if($pj['live'])<span><i style="background:var(--pa-present)"></i> Live</span>@endif
        @if(! empty($pj['needs_regularization']))<span><i style="background:var(--pa-warn)"></i> Missing punch</span>@endif
      </div>
    @else
      <div class="pa-hz-empty"><flux:icon.clock class="mb-2 size-8" style="opacity:.4" /><p style="font-size:12.5px;margin:0">No punches recorded today.</p></div>
    @endif
  </div>{{-- /pa-jcard2 --}}

  @php
    // Working-hours breakdown (moved into the 3-column row). Engine-computed.
    $breakAllowance = (int) ($shift->break_duration ?? 0);
    $hb = app(\App\Services\Attendance\PunchTimeline::class)->hoursBreakdown($workedMin, $breakMin, $targetMin, $breakAllowance);
    $hbTiles = [
        ['Expected', $hb['expected'], 'calendar-days', '#6B7280'],
        ['Worked', $hb['worked'], 'clock', '#0F9D6E'],
        ['Break', $hb['break'], 'pause', '#F59E0B'],
        ['Idle', $hb['idle'], 'exclamation-triangle', '#D64545'],
        ['Overtime', $hb['overtime'], 'bolt', '#8B5CF6'],
        ['Net Hours', $hb['net'], 'check-badge', '#2F6FEB'],
        ['Remaining', $hb['remaining'], 'arrow-path', '#6B7280'],
    ];
    $hbSpan = max(1, $hb['worked'] + $hb['break']);
    $legitBreak = max(0, $hb['break'] - $hb['idle']);
    $segWorked = round($hb['worked'] / $hbSpan * 100, 1);
    $segBreak = round($legitBreak / $hbSpan * 100, 1);
    $segIdle = round($hb['idle'] / $hbSpan * 100, 1);
  @endphp
  {{-- 3-column row: Session Summary · Working Hours Breakdown · streak/benchmark rail --}}
  <div class="pa-jgrid pa-jgrid-3" data-reveal>
    <div class="pa-sesscard">
      <div class="sh"><flux:icon.squares-2x2 class="size-4 text-orange-500" /> Session summary</div>
      @if($hasPunch && count($pj['sessions']))
        @foreach($pj['sessions'] as $s)
          @php $miss = ! empty($s['missing']); @endphp
          <div class="pa-sess {{ $s['live'] ? 'live' : '' }} {{ $miss ? 'miss' : '' }}">
            <div class="pa-sess-h">
              <span class="pa-sess-n">Session {{ $s['index'] }}@if($s['live'])<span class="pa-sess-badge">Current</span>@endif</span>
              <span class="pa-sess-t">{{ $s['in'] ? \Illuminate\Support\Str::before($s['in'], ' ') : '⚠' }} – {{ $s['out'] ? \Illuminate\Support\Str::before($s['out'], ' ') : ($miss ? '⚠' : 'now') }}</span>
            </div>
            @if($miss)
              <div class="pa-sess-m"><div><span class="l">Status</span><span class="v" style="color:var(--pa-warn);font-size:12px">{{ $s['label'] }}</span></div></div>
            @else
              <div class="pa-sess-m">
                <div><span class="l">Worked</span><span class="v">{{ $s['label'] }}</span></div>
                <div><span class="l">Break</span><span class="v">{{ $s['break_after_label'] ?? '—' }}</span></div>
                <div><span class="l">Productivity</span><span class="v" style="color:var(--pa-present)">{{ $s['productivity'] ?? 0 }}%</span></div>
              </div>
              <div class="pa-sess-bar"><i style="width: {{ $s['productivity'] ?? 0 }}%"></i></div>
            @endif
          </div>
        @endforeach
        <div class="pa-stot"><span class="tl">Total working hours</span><span class="tv">{{ $fmt($pj['working_minutes']) }}</span></div>
        <div class="pa-stot" style="border-top:1px solid var(--pa-border);padding-top:11px;margin-top:11px"><span class="tl">Total break</span><span class="tv" style="font-size:16px;color:var(--pa-muted)">{{ $fmt($pj['break_minutes']) }}</span></div>
        @if($pj['missing_out'])
          <div class="pa-swarn"><flux:icon.exclamation-triangle class="size-4" /> Missing OUT punch — this day needs regularization.</div>
        @endif
        @php $noise = ($pj['duplicate_count'] ?? 0) + ($pj['conflict_count'] ?? 0); @endphp
        @if($noise > 0)
          <div class="pa-snote"><flux:icon.funnel class="size-3.5" /> {{ $noise }} duplicate/double {{ \Illuminate\Support\Str::plural('punch', $noise) }} detected and ignored in calculations.</div>
        @endif
        @if(($pj['ignored_count'] ?? 0) > 0)
          <div class="pa-snote"><flux:icon.no-symbol class="size-3.5" /> {{ $pj['ignored_count'] }} card {{ \Illuminate\Support\Str::plural('scan', $pj['ignored_count']) }} ignored — attendance starts with Face recognition.</div>
        @endif
      @else
        <p style="font-size:12.5px;color:var(--pa-faint);margin:0">No sessions yet — your first punch starts session 1.</p>
      @endif
    </div>

  {{-- Center column: Working Hours Breakdown --}}
  <div class="pa-hb pa-hb-col" data-reveal-item>
    <div class="pa-panel-h" style="font-size:14px;margin:0">
      <flux:icon.chart-bar-square class="size-4 text-orange-500" /> Working Hours Breakdown
    </div>
    <div class="pa-hb-grid">
      @foreach($hbTiles as [$hbL, $hbM, $hbI, $hbC])
        <div class="pa-hb-tile">
          <span class="pa-hb-ic" style="background: {{ $hbC }}1a; color: {{ $hbC }};"><flux:icon :icon="$hbI" class="size-4" /></span>
          <div><div class="pa-hb-v">{{ $fmt($hbM) }}</div><div class="pa-hb-l">{{ $hbL }}</div></div>
        </div>
      @endforeach
    </div>
    @if($hb['worked'] + $hb['break'] > 0)
      <div class="pa-hb-bar">
        <i style="width: {{ $segWorked }}%; background: #0F9D6E;"></i>
        <i style="width: {{ $segBreak }}%; background: #F59E0B;"></i>
        <i style="width: {{ $segIdle }}%; background: #D64545;"></i>
      </div>
      <div class="pa-hb-key">
        <span><i style="background:#0F9D6E"></i> Worked {{ $fmt($hb['worked']) }} ({{ (int) round($segWorked) }}%)</span>
        <span><i style="background:#F59E0B"></i> Break {{ $fmt($legitBreak) }} ({{ (int) round($segBreak) }}%)</span>
        @if($hb['idle'] > 0)<span><i style="background:#D64545"></i> Idle {{ $fmt($hb['idle']) }} ({{ (int) round($segIdle) }}%)</span>@endif
      </div>
    @endif
  </div>

  {{-- Right column: streak · benchmark (slice 3) --}}
  <div class="pa-rail">
    <div class="pa-rc pa-streak">
      <div style="display:flex;align-items:center;gap:12px">
        <div class="pa-flame"><flux:icon.fire class="size-6" /></div>
        <div><div class="pa-streak-n">{{ $onTimeStreak }} {{ \Illuminate\Support\Str::plural('day', $onTimeStreak) }}</div><div class="pa-streak-l">On-time streak</div></div>
      </div>
      <div class="pa-streak-week">
        @for($i = 0; $i < 7; $i++)<span class="pa-sd {{ $i < min($onTimeStreak, 7) ? 'on' : '' }}"></span>@endfor
      </div>
      <p style="margin:12px 0 0;font-size:12px;color:var(--pa-muted)">
        @if($onTimeStreak > 0 && $onTimeStreak >= $bestStreak) New personal best — keep it alive!
        @elseif($bestStreak > $onTimeStreak) {{ $bestStreak - $onTimeStreak }} more on-time {{ \Illuminate\Support\Str::plural('day', $bestStreak - $onTimeStreak) }} beats your record of {{ $bestStreak }}.
        @else Clock in on time to start a streak. @endif
      </p>
    </div>

    <div class="pa-rc">
      <div class="pa-rc-h">How you compare <span class="pa-rc-sub" style="margin-left:auto">{{ $teamName ? 'vs '.$teamName : 'this month' }}</span></div>
      <div class="pa-bm me"><span class="who">You</span><div class="bar"><div class="fill" style="width:{{ $myOnTimeRate }}%;background:var(--pa-accent)"></div></div><span class="val">{{ $myOnTimeRate }}%</span></div>
      @if($teamName)<div class="pa-bm"><span class="who">Team avg</span><div class="bar"><div class="fill" style="width:{{ $teamOnTimeRate }}%;background:var(--pa-faint)"></div></div><span class="val">{{ $teamOnTimeRate }}%</span></div>@endif
      <div class="pa-bm"><span class="who">Company</span><div class="bar"><div class="fill" style="width:{{ $companyOnTimeRate }}%;background:var(--pa-faint)"></div></div><span class="val">{{ $companyOnTimeRate }}%</span></div>
      @php $bench = $teamName ? $teamOnTimeRate : $companyOnTimeRate; $above = $myOnTimeRate - $bench; @endphp
      <p style="margin:12px 0 0;font-size:12px;font-weight:560;display:flex;align-items:center;gap:6px;color:{{ $above >= 0 ? 'var(--pa-present)' : 'var(--pa-warn)' }}">
        <flux:icon :icon="$above >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-4" />
        {{ $above >= 0 ? 'On-time rate '.$above.'% above your '.($teamName ? 'team' : 'company') : abs($above).'% below — aim to arrive earlier' }}
      </p>
    </div>

    {{-- Rule 11 · monthly attendance score, trend vs last month, rankings --}}
    @if($monthlyScore !== null)
      <div class="pa-rc">
        <div class="pa-rc-h">Attendance score <span class="pa-rc-sub" style="margin-left:auto">this month</span></div>
        <div style="display:flex;align-items:baseline;gap:8px">
          <span style="font-size:26px;font-weight:800;color:{{ $monthlyScore >= 85 ? 'var(--pa-present)' : ($monthlyScore >= 60 ? 'var(--pa-warn)' : '#f43f5e') }}">{{ number_format($monthlyScore, 1) }}</span>
          <span style="font-size:11px;color:var(--pa-faint)">/100</span>
          @if($prevMonthlyScore !== null)
            @php $sdelta = round($monthlyScore - $prevMonthlyScore, 1); @endphp
            <span style="margin-left:auto;font-size:11px;font-weight:700;display:flex;align-items:center;gap:3px;color:{{ $sdelta >= 0 ? 'var(--pa-present)' : 'var(--pa-warn)' }}">
              <flux:icon :icon="$sdelta >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-3.5" /> {{ $sdelta >= 0 ? '+' : '' }}{{ $sdelta }} vs last month
            </span>
          @endif
        </div>
        <div style="display:flex;gap:10px;margin-top:10px">
          @if($deptRank)
            <div style="flex:1;background:var(--pa-bg);border-radius:10px;padding:8px 10px;text-align:center">
              <div style="font-size:15px;font-weight:800;color:var(--pa-ink)">#{{ $deptRank[0] }}<span style="font-size:10px;color:var(--pa-faint)">/{{ $deptRank[1] }}</span></div>
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--pa-faint)">Dept rank</div>
            </div>
          @endif
          @if($companyRank)
            <div style="flex:1;background:var(--pa-bg);border-radius:10px;padding:8px 10px;text-align:center">
              <div style="font-size:15px;font-weight:800;color:var(--pa-ink)">#{{ $companyRank[0] }}<span style="font-size:10px;color:var(--pa-faint)">/{{ $companyRank[1] }}</span></div>
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--pa-faint)">Company rank</div>
            </div>
          @endif
        </div>
        <p style="margin:10px 0 0;font-size:10.5px;color:var(--pa-faint)">Engine-scored daily · tap “Why?” on any day for the full breakdown.</p>
      </div>
    @endif
  </div>{{-- /pa-rail --}}
  </div>{{-- /pa-jgrid --}}
</div>

{{-- Working Hours Breakdown styles — the card now lives in the 3-column journey row above. --}}
<style>
.pa-hb{background:var(--pa-surface);border:1px solid var(--pa-border);border-radius:18px;padding:22px 24px;box-shadow:0 1px 2px rgba(24,24,27,.04),0 8px 24px rgba(24,24,27,.05)}
.pa-hb-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:16px}
@media(min-width:640px){.pa-hb-grid{grid-template-columns:repeat(4,1fr)}}
@media(min-width:1100px){.pa-hb-grid{grid-template-columns:repeat(7,1fr)}}
.pa-hb-tile{display:flex;align-items:center;gap:11px;border:1px solid var(--pa-border);background:var(--pa-surface-2);border-radius:14px;padding:13px 14px;transition:transform .18s var(--pa-ease),box-shadow .18s}
.pa-hb-tile:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(24,24,27,.06)}
.pa-hb-ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto}
.pa-hb-v{font-size:15.5px;font-weight:740;letter-spacing:-.02em;color:var(--pa-ink);line-height:1.1;font-variant-numeric:tabular-nums;white-space:nowrap}
.pa-hb-l{font-size:10px;font-weight:640;text-transform:uppercase;letter-spacing:.05em;color:var(--pa-faint);margin-top:3px}
.pa-hb-bar{display:flex;height:12px;border-radius:8px;overflow:hidden;margin-top:20px;background:var(--pa-surface-3)}
.pa-hb-bar i{display:block;height:100%;transition:width .8s var(--pa-ease)}
.pa-hb-key{display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;font-size:11.5px;color:var(--pa-muted)}
.pa-hb-key span{display:inline-flex;align-items:center;gap:6px;font-weight:540}
.pa-hb-key i{width:10px;height:10px;border-radius:3px}
</style>

{{-- ═══════════════ ATTENDANCE ANALYTICS (enterprise grid) ═══════════════ --}}
@php
    $ck = $statsPeriod.'-'.$analyticsMode.'-'.($rangeFrom ?? '').'-'.($rangeTo ?? '');
    $axis = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
    $baseChart = fn (string $type, int $h) => ['type' => $type, 'height' => $h, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]];
    $labels = collect($chartDaily)->pluck('label')->all();
    $tick = min(8, max(1, count($chartDaily)));

    // 1 · Working Hours Trend — smooth line
    $hoursChart = [
        'chart' => $baseChart('line', 250),
        'colors' => ['#F97316'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'markers' => ['size' => 0, 'hover' => ['size' => 5]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($chartDaily)->pluck('hours')->all()]],
    ];

    // 2 · Attendance Score Trend — area. Real engine scores (Rule 11) with an
    // hours-based proxy only for days the nightly scorer hasn't covered yet.
    $scoreSeries = collect($chartDaily)->map(fn ($d) => $d['score'] !== null
        ? (int) round($d['score'])
        : min(100, (int) round(((float) $d['hours']) / max(1, $stdHours) * 100)))->all();
    $scoreChart = [
        'chart' => $baseChart('area', 250),
        'colors' => ['#8b5cf6'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Score', 'data' => $scoreSeries]],
    ];

    // 3 · Weekly Attendance — Mon–Sun column, coloured by status
    $weekColors = collect($weekSummary)->map(fn ($wd) => match($wd['status']) {
        'present' => '#10b981', 'late' => '#f59e0b', 'leave' => '#8b5cf6', 'holiday' => '#3b82f6',
        'weekend' => '#d4d4d8', 'future' => '#e4e4e7', default => '#f43f5e',
    })->all();
    $weeklyChart = [
        'chart' => $baseChart('bar', 240),
        'colors' => $weekColors,
        'plotOptions' => ['bar' => ['borderRadius' => 5, 'columnWidth' => '55%', 'distributed' => true]],
        'dataLabels' => ['enabled' => false], 'legend' => ['show' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($weekSummary)->pluck('label')->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($weekSummary)->map(fn ($wd) => round((float) $wd['hours'], 1))->all()]],
    ];

    // 4 · Monthly Attendance — Present / Late / Absent donut
    $monthlyDonut = [
        'chart' => $baseChart('donut', 210),
        'labels' => ['On-time', 'Late', 'Absent'],
        'colors' => ['#10b981', '#f59e0b', '#ef4444'],
        'series' => [$onTimeCount, $lateCount, (int) ($stats['absent'] ?? 0)],
        'legend' => ['position' => 'bottom', 'fontSize' => '11px', 'labels' => ['colors' => '#9CA3AF']],
        'dataLabels' => ['enabled' => false], 'stroke' => ['width' => 0],
        'plotOptions' => ['pie' => ['donut' => ['size' => '70%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Days', 'fontSize' => '11px', 'color' => '#9CA3AF']]]]],
        'tooltip' => ['theme' => 'light'],
    ];

    // 5 · Late Arrival Trend — column (fixed 6-month window)
    $lateTrend = $analytics['late_trend'] ?? [];
    $lateChart = [
        'chart' => $baseChart('bar', 240),
        'colors' => ['#F59E0B'], 'plotOptions' => ['bar' => ['borderRadius' => 5, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false], 'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($lateTrend)->pluck('month')->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Late days', 'data' => collect($lateTrend)->pluck('late')->all()]],
    ];

    // 5b · Arrival Trend — minutes vs shift start (negative = early), with the
    // shift-start and grace-cutoff boundaries drawn from the assigned shift.
    $shiftStartMin = $shift?->start_time ? (int) \Illuminate\Support\Carbon::parse($shift->start_time)->format('H') * 60 + (int) \Illuminate\Support\Carbon::parse($shift->start_time)->format('i') : null;
    $graceMin = (int) ($shift->grace_minutes ?? 0);
    $arrivalSeries = collect($chartDaily)->map(fn ($d) => ($d['in_min'] !== null && $shiftStartMin !== null) ? $d['in_min'] - $shiftStartMin : null)->all();
    $arrivalChart = [
        'chart' => $baseChart('line', 240),
        'colors' => ['#f59e0b'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'markers' => ['size' => 3, 'hover' => ['size' => 5]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'annotations' => ['yaxis' => array_values(array_filter([
            $shiftStartMin !== null ? ['y' => 0, 'borderColor' => '#10b981', 'strokeDashArray' => 5, 'label' => ['text' => 'Shift start', 'style' => ['color' => '#10b981', 'background' => '#ECFDF5', 'fontSize' => '10px']]] : null,
            ($shiftStartMin !== null && $graceMin > 0) ? ['y' => $graceMin, 'borderColor' => '#f43f5e', 'strokeDashArray' => 5, 'label' => ['text' => 'Grace +'.$graceMin.'m', 'style' => ['color' => '#f43f5e', 'background' => '#FFF1F2', 'fontSize' => '10px']]] : null,
        ]))],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Minutes vs shift start', 'data' => $arrivalSeries]],
    ];

    // 5c · Logout Trend — minutes vs shift end (positive = stayed late, negative
    // = left early), from the same engine sessions that feed the arrival trend.
    $shiftEndMin = $shift?->end_time ? (int) \Illuminate\Support\Carbon::parse($shift->end_time)->format('H') * 60 + (int) \Illuminate\Support\Carbon::parse($shift->end_time)->format('i') : null;
    $logoutSeries = collect($chartDaily)->map(fn ($d) => ($d['out_min'] !== null && $shiftEndMin !== null) ? $d['out_min'] - $shiftEndMin : null)->all();
    $logoutChart = [
        'chart' => $baseChart('line', 240),
        'colors' => ['#0ea5e9'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'markers' => ['size' => 3, 'hover' => ['size' => 5]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'annotations' => ['yaxis' => array_values(array_filter([
            $shiftEndMin !== null ? ['y' => 0, 'borderColor' => '#6366f1', 'strokeDashArray' => 5, 'label' => ['text' => 'Shift end', 'style' => ['color' => '#6366f1', 'background' => '#EEF2FF', 'fontSize' => '10px']]] : null,
        ]))],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Minutes vs shift end', 'data' => $logoutSeries]],
    ];

    // 6 · Break Analysis — daily break minutes + average line
    $breakVals = collect($chartDaily)->pluck('break');
    $avgBreakLine = (int) ($analytics['avg_break'] ?? 0);
    $longBreaks = $breakVals->filter(fn ($b) => $b > 60)->count();
    $shortBreaks = $breakVals->filter(fn ($b) => $b > 0 && $b < 20)->count();
    $breakChart = [
        'chart' => $baseChart('bar', 240),
        'colors' => ['#0ea5e9'], 'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '55%']],
        'dataLabels' => ['enabled' => false], 'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'annotations' => ['yaxis' => [['y' => $avgBreakLine, 'borderColor' => '#F97316', 'strokeDashArray' => 5, 'label' => ['text' => 'Avg '.$avgBreakLine.'m', 'style' => ['color' => '#F97316', 'background' => '#FFF7ED', 'fontSize' => '10px']]]]],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Break (min)', 'data' => $breakVals->all()]],
    ];

    // 7 · Office vs WFH vs Hybrid — horizontal bars
    $modeBreakdown = $analytics['mode_breakdown'] ?? [];
    $modeChart = [
        'chart' => $baseChart('bar', 240),
        'colors' => collect($modeBreakdown)->keys()->map(fn ($k) => AttendanceMode::tryFromValue($k)->hex())->all(),
        'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 5, 'barHeight' => '55%', 'distributed' => true]],
        'dataLabels' => ['enabled' => true, 'style' => ['fontSize' => '11px']], 'legend' => ['show' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($modeBreakdown)->keys()->map(fn ($k) => AttendanceMode::tryFromValue($k)->label())->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Days', 'data' => collect($modeBreakdown)->values()->map(fn ($v) => (int) $v)->all()]],
    ];

    // 9 · Overtime Trend — daily hours beyond the standard day
    $otSeries = collect($chartDaily)->map(fn ($d) => round(max(0, (float) $d['hours'] - $stdHours), 1))->all();
    $otChart = [
        'chart' => $baseChart('area', 240),
        'colors' => ['#8b5cf6'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 2.5],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'OT hours', 'data' => $otSeries]],
    ];

    // 10 · Productivity Score — worked vs expected across the period
    $expectedMin = max(1, $totalWorkingDays * $stdHours * 60);
    $workedTotalMin = (int) round(collect($chartDaily)->sum('hours') * 60);
    $productivity = min(100, (int) round($workedTotalMin / $expectedMin * 100));
    $productivityChart = [
        'chart' => $baseChart('radialBar', 210),
        'colors' => ['#F97316'],
        'plotOptions' => ['radialBar' => [
            'hollow' => ['size' => '62%'],
            'track' => ['background' => '#FFEDD5'],
            'dataLabels' => ['name' => ['show' => true, 'fontSize' => '11px', 'color' => '#9CA3AF', 'offsetY' => 18], 'value' => ['fontSize' => '26px', 'fontWeight' => 800, 'color' => '#18181b', 'offsetY' => -12]],
        ]],
        'labels' => ['Productivity'],
        'series' => [$productivity],
    ];

    // 8 · Heatmap intensity (GitHub-style, CSS)
    $heatColor = fn ($h) => match (true) {
        $h <= 0 => 'bg-zinc-100 dark:bg-zinc-800',
        $h < 4 => 'bg-orange-200',
        $h < 7 => 'bg-orange-300',
        $h < $stdHours => 'bg-orange-400',
        default => 'bg-orange-600',
    };
@endphp

{{-- Collapsible section shell — state persists per user in localStorage --}}
<div x-data="{ o: JSON.parse(localStorage.getItem('pa-sec-analytics') ?? 'true') }" x-init="$watch('o', v => localStorage.setItem('pa-sec-analytics', JSON.stringify(v)))">
<button type="button" @click="o = !o" class="flex w-full items-center justify-between rounded-xl px-1 py-1 text-left transition hover:bg-orange-50/40 dark:hover:bg-zinc-800/40">
    <div class="flex items-center gap-2">
        <span class="inline-flex size-8 items-center justify-center rounded-xl bg-orange-50 text-orange-500"><flux:icon.chart-bar class="size-4" /></span>
        <div class="text-sm font-black text-zinc-900 dark:text-white">Attendance Analytics</div>
        <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">{{ ucwords(str_replace('_', ' ', $statsPeriod)) }}{{ $analyticsMode !== '' ? ' · '.AttendanceMode::tryFromValue($analyticsMode)->label() : '' }}</span>
    </div>
    <span class="flex items-center gap-3">
        @if($analyticsMode !== '')
            <span wire:click.stop="$set('analyticsMode', '')" class="text-[11px] font-bold text-orange-500 hover:underline">Clear mode filter</span>
        @endif
        <flux:icon.chevron-down class="size-4 text-zinc-400 transition-transform" ::class="o ? '' : '-rotate-90'" />
    </span>
</button>

<div x-show="o" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
     class="mt-2 grid grid-cols-1 gap-4 lg:grid-cols-12" data-reveal wire:loading.class="opacity-50" wire:target="statsPeriod,analyticsMode,rangeFrom,rangeTo">
    {{-- Row 1 --}}
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-5">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Working Hours Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$hoursChart" id="hours-chart" wire:key="hours-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[220px] items-center justify-center text-xs text-zinc-300">No data in this period.</div>@endif
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-3">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Monthly Attendance</div>
        @if($presentCount + ($stats['absent'] ?? 0) > 0)<x-dashboard.chart :options="$monthlyDonut" id="monthly-donut" wire:key="donut-{{ $ck }}" class="grid place-items-center" />@else<div class="flex h-[210px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
        <div class="mt-1 grid grid-cols-3 gap-1 text-center text-[9px] font-bold">
            <span class="text-emerald-600">{{ $attPct }}% Present</span>
            <span class="text-amber-600">{{ $presentCount > 0 ? round($lateCount / $presentCount * 100) : 0 }}% Late</span>
            <span class="text-rose-500">{{ $totalWorkingDays > 0 ? round(($stats['absent'] ?? 0) / $totalWorkingDays * 100) : 0 }}% Absent</span>
        </div>
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Attendance Score Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$scoreChart" id="score-chart" wire:key="score-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[220px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>

    {{-- Row 2 --}}
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Weekly Attendance</div>
            <div class="flex gap-1.5 text-[8px] font-bold">
                <span class="text-emerald-600">● Present</span><span class="text-amber-600">● Late</span><span class="text-violet-600">● Leave</span><span class="text-blue-600">● Holiday</span>
            </div>
        </div>
        <x-dashboard.chart :options="$weeklyChart" id="weekly-chart" wire:key="weekly-{{ $ck }}" class="-mb-2" />
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Late Arrival Trend <span class="text-[10px] font-bold text-zinc-400">· 6 months</span></div>
        <x-dashboard.chart :options="$lateChart" id="late-chart" wire:key="late-{{ $ck }}" class="-mb-2" />
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Break Analysis</div>
            <div class="flex gap-2 text-[9px] font-bold text-zinc-400"><span>Avg <strong class="text-sky-600">{{ $avgBreakLine }}m</strong></span><span>Long <strong class="text-amber-600">{{ $longBreaks }}</strong></span><span>Short <strong class="text-emerald-600">{{ $shortBreaks }}</strong></span></div>
        </div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$breakChart" id="break-chart" wire:key="break-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>

    {{-- Row 3 --}}
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Office vs WFH vs Hybrid</div>
        @if(! empty($modeBreakdown))<x-dashboard.chart :options="$modeChart" id="mode-chart" wire:key="mode-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No attendance yet.</div>@endif
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-5">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Overtime Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$otChart" id="ot-chart" wire:key="ot-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-3">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Productivity Score</div>
        <x-dashboard.chart :options="$productivityChart" id="productivity-chart" wire:key="prod-{{ $ck }}" class="grid place-items-center" />
        <div class="text-center text-[10px] text-zinc-400">worked vs expected ({{ $totalWorkingDays }} working days × {{ $stdHours }}h)</div>
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Arrival Trend <span class="text-[10px] font-bold text-zinc-400">· vs your shift start</span></div>
        @if($shiftStartMin !== null && collect($arrivalSeries)->filter(fn ($v) => $v !== null)->isNotEmpty())
            <x-dashboard.chart :options="$arrivalChart" id="arrival-chart" wire:key="arrival-{{ $ck }}" class="-mb-2" />
        @else
            <div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No arrival data in this period.</div>
        @endif
    </div>
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Logout Trend <span class="text-[10px] font-bold text-zinc-400">· vs your shift end</span></div>
        @if($shiftEndMin !== null && collect($logoutSeries)->filter(fn ($v) => $v !== null)->isNotEmpty())
            <x-dashboard.chart :options="$logoutChart" id="logout-chart" wire:key="logout-{{ $ck }}" class="-mb-2" />
        @else
            <div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No logout data in this period.</div>
        @endif
    </div>

    {{-- Row 4 · Heatmap --}}
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-sm transition hover:shadow-md lg:col-span-12">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Attendance Heatmap <span class="text-[10px] font-bold text-zinc-400">· hours per day</span></div>
            <div class="flex items-center gap-1 text-[10px] text-zinc-400">Less
                <span class="size-2.5 rounded-sm bg-zinc-100 dark:bg-zinc-800"></span><span class="size-2.5 rounded-sm bg-orange-200"></span><span class="size-2.5 rounded-sm bg-orange-400"></span><span class="size-2.5 rounded-sm bg-orange-600"></span>
            More</div>
        </div>
        @if(count($chartDaily) > 0)
            <div class="flex flex-wrap gap-1.5">
                @foreach($chartDaily as $d)
                    <div class="size-6 rounded-md {{ $heatColor((float) $d['hours']) }} transition-transform hover:scale-125"
                        title="{{ $d['label'] }} · {{ $d['hours'] }}h{{ $d['late'] ? ' · late' : '' }}"></div>
                @endforeach
            </div>
        @else
            <div class="py-6 text-center text-xs text-zinc-300">No data for this period.</div>
        @endif
    </div>
</div>
</div>{{-- /collapsible: analytics --}}

{{-- ═══════════════ RECENT ACTIVITY (real regularization / alert log) ═══════════════ --}}
@php
    // Built from the already-loaded log timeline — no extra queries. Each item is
    // a real regularization, approval, missing-punch or late event.
    $activity = collect();
    foreach (collect($logTimeline)->take(30) as $d) {
        $when = $d['label'] ?? '';
        if (($d['reg_status'] ?? null) === 'pending') {
            $activity->push(['ic' => 'pencil-square', 'tone' => 'amber', 'title' => 'Regularization Request', 'desc' => $when.' — pending review', 'badge' => 'Pending', 'bt' => 'amber']);
        } elseif (($d['is_regularized'] ?? false) || ($d['reg_status'] ?? null) === 'approved') {
            $activity->push(['ic' => 'check-badge', 'tone' => 'emerald', 'title' => 'Manager Approval', 'desc' => $when.' — regularization approved', 'badge' => 'Approved', 'bt' => 'emerald']);
        } elseif (($d['reg_status'] ?? null) === 'rejected') {
            $activity->push(['ic' => 'x-circle', 'tone' => 'rose', 'title' => 'Regularization Rejected', 'desc' => $when, 'badge' => 'Rejected', 'bt' => 'rose']);
        } elseif ($d['missing'] ?? false) {
            $activity->push(['ic' => 'exclamation-circle', 'tone' => 'rose', 'title' => 'Missing Punch Alert', 'desc' => $when.' — a punch needs regularization', 'badge' => 'Alert', 'bt' => 'rose']);
        } elseif ($d['is_late'] ?? false) {
            $activity->push(['ic' => 'clock', 'tone' => 'amber', 'title' => 'Attendance Warning', 'desc' => $when.' — late arrival', 'badge' => 'Warning', 'bt' => 'amber']);
        }
    }
    $activity = $activity->take(6);
@endphp
@if($activity->isNotEmpty())
<div class="pa">
  <div class="pa-panel" data-reveal>
    <div class="pa-panel-h" style="font-size:15px">
      <flux:icon.bell-alert class="size-4 text-orange-500" /> Recent Activity
      <span class="pa-panel-sub">latest attendance events</span>
    </div>
    <div class="pa-actlist" data-reveal>
      @foreach($activity as $a)
        @php
          $toneBg = ['emerald' => 'var(--pa-present-soft)', 'amber' => 'var(--pa-warn-soft)', 'rose' => 'var(--pa-danger-soft)'][$a['tone']] ?? 'var(--pa-surface-2)';
          $toneFg = ['emerald' => 'var(--pa-present)', 'amber' => 'var(--pa-warn)', 'rose' => 'var(--pa-danger)'][$a['tone']] ?? 'var(--pa-muted)';
          $badgeCls = ['emerald' => 'bg-emerald-50 text-emerald-600', 'amber' => 'bg-amber-50 text-amber-600', 'rose' => 'bg-rose-50 text-rose-500'][$a['bt']] ?? 'bg-zinc-100 text-zinc-500';
        @endphp
        <div class="pa-actitem" data-reveal-item>
          <span class="pa-actic" style="background:{{ $toneBg }};color:{{ $toneFg }}"><flux:icon :icon="$a['ic']" class="size-4" /></span>
          <div class="min-w-0 flex-1">
            <div class="pa-actt">{{ $a['title'] }}</div>
            <div class="pa-actd">{{ $a['desc'] }}</div>
          </div>
          <span class="shrink-0 rounded-full px-2.5 py-0.5 text-[10px] font-bold {{ $badgeCls }}">{{ $a['badge'] }}</span>
        </div>
      @endforeach
    </div>
  </div>
</div>
<style>
.pa-actlist{display:flex;flex-direction:column;gap:4px}
.pa-actitem{display:flex;align-items:center;gap:12px;padding:11px 8px;border-radius:12px;transition:background .16s}
.pa-actitem:hover{background:var(--pa-surface-2)}
.pa-actic{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto}
.pa-actt{font-size:13px;font-weight:660;color:var(--pa-ink)}
.pa-actd{font-size:11.5px;color:var(--pa-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
@endif

{{-- ═══════════════ AI ATTENDANCE INSIGHTS ═══════════════ --}}
@if(! empty($insightStats))
    @php
        $ai = $insightStats;
        $trendChip = function ($delta, bool $goodWhenUp = true) {
            if ($delta === null || $delta === 0) { return null; }
            $up = $delta > 0;
            $good = $up === $goodWhenUp;

            return ['icon' => $up ? 'arrow-trending-up' : 'arrow-trending-down',
                'class' => $good ? 'text-emerald-600' : 'text-rose-500',
                'text' => ($up ? '+' : '').$delta.' vs prev'];
        };
        $aiCards = [
            ['Attendance Score', $ai['score'].'/100', 'shield-check', '#10b981', $trendChip($ai['present_trend'])],
            ['Average Check-in', $ai['avg_in'] ?? '—', 'arrow-right-end-on-rectangle', '#F97316', null],
            ['Average Check-out', $ai['avg_out'] ?? '—', 'arrow-left-start-on-rectangle', '#ef4444', null],
            ['Average Break', $ai['avg_break'] ? $ai['avg_break'].'m' : '—', 'pause', '#0ea5e9', null],
            ['Avg Working Hours', $ai['avg_hours'] ?? '—', 'clock', '#6366f1', null],
            ['Best Attendance Day', $ai['best_day'] ?? '—', 'star', '#f59e0b', null],
            ['Longest Working Day', $ai['longest_day'] ?? '—', 'bolt', '#8b5cf6', null],
            ['Longest Break', $ai['longest_break'] ?? '—', 'moon', '#14b8a6', null],
            ['Perfect Streak', $ai['streak'].' '.\Illuminate\Support\Str::plural('day', $ai['streak']), 'fire', '#F97316', null],
            ['Late Count', $ai['late_count'], 'exclamation-triangle', $ai['late_count'] ? '#f59e0b' : '#10b981', $trendChip($ai['late_trend'], false)],
            ['Missing Punches', $ai['missing_count'], 'flag', $ai['missing_count'] ? '#ef4444' : '#10b981', null],
            ['Attendance Prediction', $ai['prediction'].'%', 'sparkles', '#F97316', null],
        ];
    @endphp
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm"
         x-data="{ o: JSON.parse(localStorage.getItem('pa-sec-ai') ?? 'true') }" x-init="$watch('o', v => localStorage.setItem('pa-sec-ai', JSON.stringify(v)))">
        <button type="button" @click="o = !o" class="flex w-full flex-wrap items-center justify-between gap-2 text-left" :class="o ? 'mb-4' : ''">
            <div class="flex items-center gap-2">
                <span class="inline-flex size-8 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-400 text-white shadow"><flux:icon.sparkles class="size-4" /></span>
                <div class="text-sm font-black text-zinc-900 dark:text-white">AI Attendance Insights</div>
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ ucwords(str_replace('_', ' ', $statsPeriod)) }}</span>
            </div>
            <span class="flex items-center gap-3">
                <span class="rounded-full bg-orange-50 px-2.5 py-1 text-[10px] font-bold text-orange-500">Predicted {{ $ai['prediction'] }}% by period end</span>
                <flux:icon.chevron-down class="size-4 text-zinc-400 transition-transform" ::class="o ? '' : '-rotate-90'" />
            </span>
        </button>

        <div x-show="o" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100">
        {{-- Stat cards --}}
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">
            @foreach($aiCards as [$label, $value, $icon, $color, $trend])
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-gradient-to-b from-white to-orange-50/30 p-3 transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
                    <span class="inline-flex size-8 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
                    <div class="mt-2 truncate text-base font-black leading-none tabular-nums text-zinc-900 dark:text-white" title="{{ $value }}">{{ $value }}</div>
                    <div class="mt-1 truncate text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                    @if($trend)
                        <div class="mt-0.5 flex items-center gap-1 text-[9px] font-bold {{ $trend['class'] }}"><flux:icon :icon="$trend['icon']" class="size-3" /> {{ $trend['text'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Suggestions --}}
        @if(! empty($ai['suggestions']))
            <div class="mt-4 border-t border-zinc-200/70 dark:border-zinc-800 pt-3">
                <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Suggestions</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($ai['suggestions'] as $s)
                        <span class="inline-flex items-center gap-1.5 rounded-full {{ $s['good'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }} px-3 py-1.5 text-xs font-semibold">
                            <flux:icon :icon="$s['good'] ? 'check-circle' : 'light-bulb'" class="size-3.5 {{ $s['good'] ? 'text-emerald-500' : 'text-amber-500' }}" /> {{ $s['text'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Detailed observations (existing generated insights) --}}
        @if(count($insights) > 0)
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($insights as $ins)
                    <div class="flex items-center gap-2 rounded-xl {{ $ins['good'] ? 'bg-emerald-50/60' : 'bg-amber-50/60' }} px-3 py-2 text-xs">
                        <flux:icon :icon="$ins['good'] ? 'check-circle' : 'exclamation-circle'" class="size-4 shrink-0 {{ $ins['good'] ? 'text-emerald-500' : 'text-amber-500' }}" />
                        <span class="font-semibold {{ $ins['good'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $ins['text'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
        </div>{{-- /collapsible body: ai insights --}}
    </div>
@endif

{{-- ═══════════════ WFH DAILY REPORT (WFH/Hybrid days only) ═══════════════ --}}
@if(in_array($heroMode->value, ['wfh', 'hybrid'], true))
    <div class="rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2"><span class="inline-flex size-8 items-center justify-center rounded-xl bg-violet-50 text-violet-600"><flux:icon.home class="size-4" /></span><div class="text-sm font-black text-zinc-900 dark:text-white">WFH Daily Report</div></div>
            @if($wfhReport)<span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-bold text-emerald-700"><flux:icon.check class="size-3" /> Submitted</span>@endif
        </div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">What did you work on today? <span class="text-rose-400">*</span></label>
                <textarea wire:model="wfhForm.work_summary" rows="2" placeholder="Tasks, tickets, meetings…" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea>
                @error('wfhForm.work_summary')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div><label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">Achievements</label><textarea wire:model="wfhForm.achievements" rows="2" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea></div>
            <div><label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">Blockers</label><textarea wire:model="wfhForm.blockers" rows="2" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea></div>
        </div>
        <div class="mt-3 flex justify-end">
            <button wire:click="saveWfhReport" class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-violet-700"><flux:icon.paper-airplane class="size-4" /> {{ $wfhReport ? 'Update Report' : 'Submit Report' }}</button>
        </div>
    </div>
@endif

{{-- ═══════════════ PUNCH IN / OUT TIMELINE ═══════════════ --}}
<div id="attendance-log" class="overflow-hidden rounded-[18px] border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm scroll-mt-6"
     x-data="{ o: JSON.parse(localStorage.getItem('pa-sec-log') ?? 'true') }" x-init="$watch('o', v => localStorage.setItem('pa-sec-log', JSON.stringify(v)))">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200/70 dark:border-zinc-800 px-5 py-3.5">
        <button type="button" @click="o = !o" class="flex flex-1 items-center gap-2 text-left">
            <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white"><flux:icon.clock class="size-4 text-orange-500" /> Punch In / Out Timeline
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                    · {{ $statsPeriod === 'custom' && $rangeFrom && $rangeTo ? \Carbon\Carbon::parse($rangeFrom)->format('d M').' – '.\Carbon\Carbon::parse($rangeTo)->format('d M Y') : $calendarMonth->format('M Y') }}
                </span>
            </h3>
            <flux:icon.chevron-down class="size-4 text-zinc-400 transition-transform" ::class="o ? '' : '-rotate-90'" />
        </button>
        <div class="flex flex-wrap items-center gap-2">
            <x-clean-select model="logMode" :live="true"
                :options="[['value' => '', 'label' => 'All modes'], ...collect(AttendanceMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all()]" />
            <button wire:click="exportLog" class="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-orange-600"><flux:icon.arrow-down-tray class="size-3.5" /> Export</button>
        </div>
    </div>

    <div x-show="o">
    @php $days = $logMode !== '' ? collect($logTimeline)->where('mode', $logMode)->values() : collect($logTimeline); @endphp
    @if($days->count() > 0)
        <div class="divide-y divide-orange-50">
            @foreach($days as $day)
                @php
                    [$dayBadge, $dayLabel] = match(true) {
                        $day['is_late'] => ['bg-amber-50 text-amber-600', 'Late'],
                        $day['status'] === 'on_time' => ['bg-emerald-50 text-emerald-600', 'Present'],
                        default => ['bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400', ucfirst($day['status'] ?? '—')],
                    };
                    // Colored left status border on each day row.
                    $dayBorder = match(true) {
                        $day['missing'] => 'border-l-amber-400',
                        $day['is_late'] => 'border-l-amber-400',
                        $day['status'] === 'on_time' => 'border-l-emerald-400',
                        default => 'border-l-zinc-200 dark:border-l-zinc-700',
                    };
                    $dayMode = AttendanceMode::tryFromValue($day['mode'] ?? 'office');
                @endphp
                <div x-data="{ open: {{ $day['is_today'] ? 'true' : 'false' }} }" class="border-l-[3px] {{ $dayBorder }} {{ $day['missing'] ? 'bg-amber-50/30' : '' }}">
                    {{-- Day header --}}
                    <button type="button" @click="open = !open" class="flex w-full flex-wrap items-center gap-3 px-5 py-3.5 text-left transition hover:bg-orange-50/50 dark:hover:bg-zinc-800/40">
                        <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400 transition-transform" ::class="open ? 'rotate-90' : ''" />
                        <div class="min-w-[7.5rem]">
                            <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $day['label'] }} @if($day['is_today'])<span class="text-orange-500">· Today</span>@endif</div>
                            <div class="text-[10px] text-zinc-400">{{ $day['dayname'] }}</div>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $dayBadge }}">{{ $dayLabel }}</span>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[9px] font-bold uppercase {{ $dayMode->chipClass() }}">{{ $dayMode->shortLabel() }}</span>
                        @if($day['is_regularized'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-[9px] font-bold uppercase text-blue-600 dark:bg-blue-500/15 dark:text-blue-300"><flux:icon.check-badge class="size-3" /> Regularized</span>
                        @elseif($day['reg_status'])
                            @php $regC = match($day['reg_status']) { 'rejected' => 'bg-rose-50 text-rose-500', default => 'bg-amber-50 text-amber-600' }; @endphp
                            <span class="rounded-full px-2 py-0.5 text-[8px] font-bold uppercase {{ $regC }}">Reg. {{ $day['reg_status'] }}</span>
                        @endif
                        <span class="ml-auto flex items-center gap-3 text-[10px] text-zinc-400">
                            <span>{{ count($day['events']) }} {{ \Illuminate\Support\Str::plural('punch', count($day['events'])) }}</span>
                            @if($day['worked'])<span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $day['worked'] }}</span>@endif
                            <span class="rounded-lg p-1 text-zinc-400 transition hover:bg-orange-100 hover:text-orange-600" wire:click.stop="showPunchDetail('{{ $day['date'] }}')" title="Full day details"><flux:icon.eye class="size-4" /></span>
                            <span class="inline-flex items-center gap-0.5 rounded-lg px-1.5 py-1 text-[10px] font-black text-zinc-400 transition hover:bg-blue-50 hover:text-blue-600" wire:click.stop="showScoreDecision('{{ $day['date'] }}')" title="Why? — how the engine decided this day"><flux:icon.scale class="size-3.5" /> Why?</span>
                        </span>
                    </button>

                    <div x-show="open" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100" class="px-5 pb-4">
                        {{-- Missing punch banner --}}
                        @if($day['missing'])
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/15 px-3 py-2">
                                <div class="flex items-center gap-2 text-xs">
                                    <flux:icon.exclamation-triangle class="size-4 text-amber-500" />
                                    <span class="font-black text-amber-900">Missing Clock Out</span>
                                    @if($day['worked'])<span class="text-amber-700">· Worked {{ $day['worked'] }}</span>@endif
                                </div>
                                <button wire:click="openRegularisation('{{ $day['date'] }}')" class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.pencil-square class="size-3" /> Request Regularization</button>
                            </div>
                        @endif

                        {{-- System auto punch-out banner — regularizable --}}
                        @if($day['is_auto_checkout'] ?? false)
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-orange-300 bg-orange-50 dark:bg-orange-900/15 px-3 py-2">
                                <div class="flex items-center gap-2 text-xs">
                                    <flux:icon.bolt class="size-4 text-orange-500" />
                                    <span class="font-black text-orange-900 dark:text-orange-200">Auto Punch-Out</span>
                                    <span class="text-orange-700 dark:text-orange-300">· system closed this day at {{ $day['corrected_out'] ?? '—' }} (no OUT punch received)</span>
                                </div>
                                <button wire:click="openRegularisation('{{ $day['date'] }}')" class="inline-flex items-center gap-1 rounded-lg bg-orange-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-orange-600"><flux:icon.pencil-square class="size-3" /> Fix Time</button>
                            </div>
                        @endif

                        {{-- Regularized: original vs corrected — history is never lost --}}
                        @if($day['is_regularized'] && (($day['original_in'] ?? null) || ($day['original_out'] ?? null)))
                            <div class="mb-3 rounded-xl border border-blue-200 bg-blue-50/70 dark:border-blue-900 dark:bg-blue-950/20 px-3 py-2 text-xs">
                                <div class="mb-1 flex items-center gap-1.5 font-black text-blue-800 dark:text-blue-200"><flux:icon.check-badge class="size-4" /> Regularized — original punches preserved</div>
                                <div class="flex flex-wrap gap-x-5 gap-y-1 font-mono tabular-nums">
                                    <span class="text-zinc-500 dark:text-zinc-400">IN: <span class="line-through">{{ $day['original_in'] ?? '—' }}</span> <flux:icon.arrow-right class="inline size-3 text-blue-400" /> <span class="font-bold text-blue-700 dark:text-blue-300">{{ $day['corrected_in'] ?? '—' }}</span></span>
                                    <span class="text-zinc-500 dark:text-zinc-400">OUT: <span class="line-through">{{ $day['original_out'] ?? '—' }}</span> <flux:icon.arrow-right class="inline size-3 text-blue-400" /> <span class="font-bold text-blue-700 dark:text-blue-300">{{ $day['corrected_out'] ?? '—' }}</span></span>
                                </div>
                            </div>
                        @endif

                        {{-- Engine work sessions — validated IN→OUT pairs --}}
                        @if(count($day['sessions'] ?? []) > 0)
                            <div class="mb-3 flex flex-wrap items-center gap-2">
                                @foreach($day['sessions'] as $s)
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[10px] font-bold tabular-nums {{ ($s['missing'] ?? false) ? 'border-amber-300 bg-amber-50 text-amber-700' : 'border-emerald-200 bg-emerald-50/70 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300' }}">
                                        <span class="text-[8px] font-black uppercase tracking-wide opacity-60">S{{ $s['index'] }}</span>
                                        {{ $s['in'] ?? '⚠' }} → {{ $s['out'] ?? (($s['live'] ?? false) ? 'now' : '⚠') }}
                                        <span class="opacity-70">· {{ $s['label'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Punches the engine ignored (Rule 1/2) — collapsed, with reasons --}}
                        @if(count($day['ignored_events'] ?? []) > 0 || ($day['noise_count'] ?? 0) > 0)
                            <div x-data="{ ig: false }" class="mb-3">
                                <button type="button" @click="ig = !ig" class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-100 px-2.5 py-1 text-[10px] font-bold text-zinc-500 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-400">
                                    <flux:icon.funnel class="size-3" />
                                    {{ count($day['ignored_events'] ?? []) + ($day['noise_count'] ?? 0) }} {{ \Illuminate\Support\Str::plural('punch', count($day['ignored_events'] ?? []) + ($day['noise_count'] ?? 0)) }} ignored by Attendance Engine
                                    <flux:icon.chevron-down class="size-3 transition-transform" ::class="ig ? 'rotate-180' : ''" />
                                </button>
                                <div x-show="ig" x-transition class="mt-2 space-y-1">
                                    @foreach($day['ignored_events'] ?? [] as $ie)
                                        <div class="flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-1.5 text-[11px] text-zinc-400 dark:bg-zinc-800/50">
                                            <flux:icon.no-symbol class="size-3.5 shrink-0" />
                                            <span class="font-mono font-bold line-through">{{ $ie['time'] }}</span>
                                            <span class="font-bold">{{ $ie['method'] }}</span>
                                            <span class="truncate">— {{ $ie['reason'] }}</span>
                                        </div>
                                    @endforeach
                                    @if(($day['noise_count'] ?? 0) > 0)
                                        <div class="flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-1.5 text-[11px] text-zinc-400 dark:bg-zinc-800/50">
                                            <flux:icon.document-duplicate class="size-3.5 shrink-0" />
                                            {{ $day['noise_count'] }} duplicate/double {{ \Illuminate\Support\Str::plural('read', $day['noise_count']) }} merged into the kept punches.
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Vertical punch timeline --}}
                        @if(count($day['events']) > 0)
                            <div class="relative ml-2 space-y-2.5">
                                @foreach($day['events'] as $ev)
                                    @php
                                        [$dot, $ic] = match($ev['type']) {
                                            'in'     => ['bg-emerald-500', 'arrow-right-end-on-rectangle'],
                                            'late'   => ['bg-rose-500', 'exclamation-triangle'],
                                            'break'  => ['bg-orange-500', 'pause'],
                                            'resume' => ['bg-blue-500', 'play'],
                                            'out'    => ['bg-rose-600', 'arrow-left-start-on-rectangle'],
                                            default  => ['bg-zinc-400', 'clock'],
                                        };
                                        $evMethod = PunchMethod::tryFrom((string) ($ev['method'] ?? ''));
                                        $srcLabel = $evMethod?->label() ?? match($ev['source'] ?? '') { 'web' => 'Web Punch', 'mobile' => 'Mobile GPS', 'manual' => 'Manual Approval', default => 'Biometric' };
                                        $hasHover = ! empty($ev['lat']) || ! empty($ev['ip']) || ! empty($ev['photo']) || ! empty($ev['device']);
                                    @endphp
                                    <div class="group/event relative flex items-start gap-3">
                                        @unless($loop->last)<span class="absolute left-[13px] top-8 h-[calc(100%-6px)] w-px bg-orange-100"></span>@endunless
                                        <span class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full {{ $dot }} text-white shadow"><flux:icon :icon="$ic" class="size-3.5" /></span>
                                        <div class="flex-1 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/40 px-3 py-2 transition group-hover/event:border-orange-200 group-hover/event:bg-white group-hover/event:shadow-sm">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                                <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $ev['time'] }}</span>
                                                @if($evMethod)
                                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold {{ $evMethod->chipClass() }}"><flux:icon :icon="$evMethod->icon()" class="size-3.5" /> {{ $evMethod->label() }}</span>
                                                @else
                                                    <span class="text-[10px] font-bold text-zinc-500 dark:text-zinc-400">{{ $srcLabel }}</span>
                                                @endif
                                                <span class="text-[10px] text-zinc-400">{{ $ev['title'] }}</span>
                                                <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Success</span>
                                            </div>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[10px] text-zinc-400">
                                                @if(! empty($ev['device']) && is_string($ev['device']))<span class="inline-flex items-center gap-0.5"><flux:icon.cpu-chip class="size-3" /> {{ $ev['device'] }}</span>@endif
                                                @if(! empty($ev['location']))<span class="inline-flex items-center gap-0.5"><flux:icon.map-pin class="size-3" /> {{ $ev['location'] }}</span>@endif
                                            </div>
                                            @if($hasHover)
                                                <div class="mt-1.5 hidden flex-wrap items-center gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-1.5 text-[10px] text-zinc-500 dark:text-zinc-400 group-hover/event:flex">
                                                    @if(! empty($ev['lat']) && ! empty($ev['lng']))
                                                        <a href="https://www.google.com/maps?q={{ $ev['lat'] }},{{ $ev['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-orange-500 hover:underline"><flux:icon.map-pin class="size-3" /> {{ $ev['lat'] }}, {{ $ev['lng'] }}</a>
                                                    @endif
                                                    @if(! empty($ev['ip']))<span class="inline-flex items-center gap-1"><flux:icon.globe-alt class="size-3" /> {{ $ev['ip'] }}</span>@endif
                                                    @if(! empty($ev['photo']))
                                                        <a href="{{ Storage::url($ev['photo']) }}" target="_blank" class="inline-flex items-center gap-1 font-semibold text-orange-500 hover:underline"><flux:icon.camera class="size-3" /> Photo verification</a>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-3 text-center text-xs text-zinc-400">No punches recorded for this day.</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="py-12 text-center text-sm text-zinc-400"><flux:icon.clock class="mx-auto mb-2 size-8 opacity-30" /> No records for {{ $calendarMonth->format('F Y') }}</div>
    @endif

    {{-- Today's Summary footer --}}
    @if($todayAttendance)
        @php
            $firstPunch = $todayAttendance->check_in ?? $sum?->first_punch;
            $lastPunch = $todayAttendance->check_out ?? $sum?->last_punch;
        @endphp
        <div class="grid grid-cols-3 gap-2 border-t border-zinc-200/70 dark:border-zinc-800 bg-orange-50/30 px-5 py-3 sm:grid-cols-6">
            @foreach([
                ['Working Hours', $workedLabel],
                ['Break Time', intdiv($breakMin, 60) > 0 ? intdiv($breakMin, 60).'h '.($breakMin % 60).'m' : $breakMin.'m'],
                ['Overtime', intdiv($otMinTotal, 60).'h '.($otMinTotal % 60).'m'],
                ['Total Punches', $totalPunches ?: '—'],
                ['First Punch', $firstPunch ? \Carbon\Carbon::parse($firstPunch)->format('h:i A') : '—'],
                ['Last Punch', $lastPunch ? \Carbon\Carbon::parse($lastPunch)->format('h:i A') : '—'],
            ] as [$k, $v])
                <div class="text-center">
                    <div class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ $k }}</div>
                </div>
            @endforeach
        </div>
    @endif
    <div class="border-t border-zinc-200/70 dark:border-zinc-800 px-5 py-2 text-center text-[11px] text-zinc-400">All times are based on your shift timezone (IST)</div>
    </div>{{-- /collapsible body: punch log --}}
</div>

</div>{{-- end spacing wrapper --}}

{{-- ═══════════════════════════════════════════════
     PUNCH DETAIL MODAL
═══════════════════════════════════════════════ --}}
{{-- ═══════════ ATTENDANCE DECISION — "Why?" audit popup (Rule 11) ═══════════ --}}
<flux:modal name="score-decision" class="max-w-lg">
    @if($decision)
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg">Attendance Decision</flux:heading>
                    <flux:subheading>{{ $decision['date'] }}</flux:subheading>
                </div>
                @if($decision['score'] !== null)
                    @php $sc = (int) round($decision['score']); @endphp
                    <div class="text-center">
                        <div class="text-2xl font-black tabular-nums {{ $sc >= 85 ? 'text-emerald-600' : ($sc >= 60 ? 'text-amber-500' : 'text-rose-500') }}">{{ $sc }}<span class="text-xs text-zinc-400">/100</span></div>
                        <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Attendance Score</div>
                    </div>
                @endif
            </div>

            {{-- How the engine read the day --}}
            <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/40 p-3 text-xs">
                <div class="mb-2 flex items-center gap-1.5 font-black text-zinc-700 dark:text-zinc-200"><flux:icon.cog-6-tooth class="size-3.5 text-orange-500" /> Engine inputs</div>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 tabular-nums">
                    @if($decision['shift'])
                        <dt class="text-zinc-400">Shift</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['shift']['window'] }}</dd>
                        <dt class="text-zinc-400">Grace</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['shift']['grace'] }}</dd>
                    @endif
                    <dt class="text-zinc-400">First IN</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['first_in'] ?? '—' }}</dd>
                    <dt class="text-zinc-400">Last OUT</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['last_out'] ?? '—' }}{{ $decision['auto_punch_out'] ? ' (auto)' : '' }}</dd>
                    <dt class="text-zinc-400">Worked</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['worked'] }}</dd>
                    <dt class="text-zinc-400">Break</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['break'] }}</dd>
                    @if($decision['late'])<dt class="text-zinc-400">Late</dt><dd class="text-right font-bold text-amber-600">{{ $decision['late'] }}</dd>@endif
                    @if($decision['sessions'])<dt class="text-zinc-400">Sessions</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['sessions'] }}</dd>@endif
                    @if($decision['duplicates'] > 0)<dt class="text-zinc-400">Duplicates merged</dt><dd class="text-right font-bold text-zinc-800 dark:text-zinc-100">{{ $decision['duplicates'] }}</dd>@endif
                </dl>
                @foreach($decision['ignored'] as $ig)
                    <div class="mt-1.5 flex items-center gap-1.5 text-[11px] text-zinc-400"><flux:icon.no-symbol class="size-3" /> {{ $ig }}</div>
                @endforeach
            </div>

            {{-- Deductions & bonuses — the persisted audit trail --}}
            <div>
                <div class="mb-1.5 flex items-center gap-1.5 text-xs font-black text-zinc-700 dark:text-zinc-200"><flux:icon.scale class="size-3.5 text-blue-500" /> Score breakdown</div>
                @forelse($decision['breakdown'] as $line)
                    <div class="flex items-start justify-between gap-3 border-b border-zinc-50 dark:border-zinc-800/60 py-1.5 text-xs">
                        <div class="min-w-0">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $line['label'] }}</div>
                            <div class="text-[10px] text-zinc-400">{{ $line['detail'] }}</div>
                        </div>
                        <span class="shrink-0 font-black tabular-nums {{ $line['points'] < 0 ? 'text-rose-500' : 'text-emerald-600' }}">{{ $line['points'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($line['points'], 1), '0'), '.') }}</span>
                    </div>
                @empty
                    <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/25 px-3 py-2 text-xs font-bold text-emerald-700 dark:text-emerald-300">Perfect day — no deductions applied.</div>
                @endforelse
                @if($decision['score'] === null)
                    <div class="mt-2 text-[10px] text-zinc-400">This day hasn't been scored yet — the engine scores each day just after midnight.</div>
                @endif
            </div>

            <div class="flex items-center justify-between rounded-xl bg-zinc-50 dark:bg-zinc-800/40 px-3 py-2">
                <span class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">Final status</span>
                <span class="text-xs font-black {{ $decision['status'] === 'late' ? 'text-amber-600' : ($decision['status'] === 'absent' ? 'text-rose-500' : 'text-emerald-600') }}">
                    {{ ucwords(str_replace('_', ' ', $decision['status'])) }}{{ $decision['regularized'] ? ' · Regularized' : '' }}
                </span>
            </div>
        </div>
    @endif
</flux:modal>

<flux:modal name="punch-detail" class="max-w-lg">
    @if($detail)
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Punch Details</flux:heading>
                    <flux:subheading>{{ $detail['date'] }}</flux:subheading>
                </div>
                @php $dMode = AttendanceMode::tryFromValue($detail['mode']); @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $dMode->chipClass() }}">
                    <flux:icon :icon="$dMode->icon()" class="size-3.5" /> {{ $dMode->label() }}
                </span>
            </div>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['total_hours'] ?? '—' }}<span class="text-[10px] text-zinc-400">h</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Worked</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['break_minutes'] }}<span class="text-[10px] text-zinc-400">m</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Break</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black {{ $detail['is_late'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $detail['is_late'] ? 'Late' : 'On Time' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Status</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['is_late'] ? $detail['late_minutes'].'m' : '—' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Late by</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach(['in' => 'Check In', 'out' => 'Check Out'] as $key => $title)
                    @php $p = $detail[$key]; @endphp
                    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 p-4 dark:border-zinc-800">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-widest {{ $key === 'in' ? 'text-emerald-600' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $title }}</span>
                            <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $p['time'] ?? '—' }}</span>
                        </div>
                        @if($p['photo'])
                            <a href="{{ Storage::url($p['photo']) }}" target="_blank">
                                <img src="{{ Storage::url($p['photo']) }}" alt="Punch selfie" class="mb-2 h-24 w-full rounded-xl object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                            </a>
                        @endif
                        <div class="space-y-1.5 text-[11px]">
                            @if($p['method'])
                                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$p['method_icon']" class="size-3.5 text-zinc-400" /> {{ $p['method'] }}</div>
                            @endif
                            <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon.computer-desktop class="size-3.5 text-zinc-400" /> {{ $p['device'] }}</div>
                            @if($p['ip'])
                                <div class="flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"><flux:icon.globe-alt class="size-3.5 text-zinc-400" /> {{ $p['ip'] }}</div>
                            @endif
                            @if($p['lat'] && $p['lng'])
                                <a href="https://www.google.com/maps?q={{ $p['lat'] }},{{ $p['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-semibold text-orange-500 hover:underline"><flux:icon.map-pin class="size-3.5" /> View location</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Attendance Replay — every raw punch of the day --}}
            @if(! empty($detail['punches']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Punch Timeline · {{ count($detail['punches']) }} punches</div>
                    <div class="max-h-48 space-y-1.5 overflow-y-auto pr-1">
                        @foreach($detail['punches'] as $i => $pp)
                            <div class="flex items-center gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-3 py-1.5 text-xs dark:bg-zinc-800/40">
                                <span class="w-16 shrink-0 font-black tabular-nums text-zinc-900 dark:text-white">{{ $pp['time'] }}</span>
                                @if($pp['method'])<span class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$pp['method_icon']" class="size-3.5 text-zinc-400" /> {{ $pp['method'] }}</span>@endif
                                <span class="ml-auto flex items-center gap-2 text-[10px] text-zinc-400">
                                    @if($pp['location'])<span>{{ $pp['location'] }}</span>@endif
                                    @if($pp['device'])<span>{{ $pp['device'] }}</span>@endif
                                    <span class="uppercase">{{ $pp['source'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Audit History — regularisations touching this day --}}
            @if(! empty($detail['audits']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Audit History</div>
                    <div class="space-y-2">
                        @foreach($detail['audits'] as $audit)
                            @php
                                $ac = match($audit['status']) { 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-600', default => 'bg-amber-100 text-amber-700' };
                            @endphp
                            <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 p-3 text-xs dark:border-zinc-800">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100">Corrected to {{ $audit['requested_in'] }} → {{ $audit['requested_out'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $ac }}">{{ $audit['status'] }}</span>
                                </div>
                                <div class="mt-1 text-zinc-500 dark:text-zinc-400">“{{ $audit['reason'] }}”</div>
                                <div class="mt-1 text-[10px] text-zinc-400">
                                    Submitted {{ $audit['submitted_at'] }}
                                    @if($audit['reviewer']) · {{ ucfirst($audit['status']) }} by {{ $audit['reviewer'] }} on {{ $audit['reviewed_at'] }}@endif
                                </div>

                                {{-- Multi-stage manager approval trail (L1 → L2 → …) --}}
                                @if(! empty($audit['trail']))
                                    <ol class="mt-2.5 space-y-1.5 border-t border-zinc-100 pt-2.5 dark:border-zinc-800">
                                        @foreach($audit['trail'] as $step)
                                            @php
                                                $stepDot = match(strtolower($step['action'])) {
                                                    'approved' => 'bg-emerald-500',
                                                    'rejected' => 'bg-rose-500',
                                                    default => 'bg-amber-400',
                                                };
                                            @endphp
                                            <li class="flex items-start gap-2">
                                                <span class="mt-1 size-1.5 shrink-0 rounded-full {{ $stepDot }}"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-[11px] font-bold capitalize text-zinc-700 dark:text-zinc-200">{{ $step['stage'] ?: 'Review' }} · {{ ucfirst($step['action']) }}</span>
                                                        @if($step['at'])<span class="shrink-0 text-[9px] text-zinc-400">{{ $step['at'] }}</span>@endif
                                                    </div>
                                                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                                        {{ $step['by'] ?? 'System' }}@if($step['comment']) — “{{ $step['comment'] }}”@endif
                                                    </div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-1">
                <flux:button @click="$flux.modal('punch-detail').close()">Close</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

{{-- ═══════════════════════════════════════════════
     REGULARISATION MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="regularisation-modal" class="max-w-lg">
    <div class="space-y-5">
        <div class="flex items-start gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-orange-600"><flux:icon.pencil-square class="size-5" /></span>
            <div>
                <flux:heading size="lg">Request Regularization</flux:heading>
                <flux:subheading>Fix a missing or wrong punch. Raw device logs stay untouched — the correction applies only after final approval.</flux:subheading>
            </div>
        </div>

        {{-- 1 · When --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">1</span> Which day?</div>
            <flux:input wire:model="regDate" type="date" />
        </div>

        {{-- 2 · Type of fix --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">2</span> What do you need to fix?</div>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regType === 'punch' ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="radio" wire:model.live="regType" value="punch" class="border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regType === 'punch' ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.clock class="size-4" /> Fix a punch</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regType === 'half_day' ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="radio" wire:model.live="regType" value="half_day" class="border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regType === 'half_day' ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.sun class="size-4" /> Mark half day</span>
                </label>
            </div>
        </div>

        {{-- Half-day period (only for half-day requests) --}}
        @if($regType === 'half_day')
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Which half?</div>
            <flux:select wire:model="regHalfDayPeriod">
                <flux:select.option value="first">First half</flux:select.option>
                <flux:select.option value="second">Second half</flux:select.option>
            </flux:select>
        </div>
        @endif

        {{-- Punch fix — which punch (only when fixing a punch) --}}
        @if($regType === 'punch')
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Which punch is missing or wrong?</div>
            <div class="grid grid-cols-2 gap-3">
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regFixIn ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="checkbox" wire:model.live="regFixIn" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regFixIn ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.arrow-right-end-on-rectangle class="size-4" /> IN punch</span>
                </label>
                <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 transition {{ $regFixOut ? 'border-orange-400 bg-orange-50 dark:bg-orange-500/10' : 'border-zinc-200 dark:border-zinc-800' }}">
                    <input type="checkbox" wire:model.live="regFixOut" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    <span class="flex items-center gap-1.5 text-sm font-semibold {{ $regFixOut ? 'text-orange-700 dark:text-orange-400' : 'text-zinc-500 dark:text-zinc-400' }}"><flux:icon.arrow-left-start-on-rectangle class="size-4" /> OUT punch</span>
                </label>
            </div>
        </div>

        {{-- 3 · Expected time + method --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">3</span> Expected time</div>
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2 {{ $regFixIn ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckIn" label="IN at" type="time" :disabled="! $regFixIn" />
                    <flux:select wire:model="regCheckInMethod" :disabled="! $regFixIn">
                        <flux:select.option value="id_card">via ID Card</flux:select.option>
                        <flux:select.option value="face">via Face</flux:select.option>
                    </flux:select>
                </div>
                <div class="space-y-2 {{ $regFixOut ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckOut" label="OUT at" type="time" :disabled="! $regFixOut" />
                    <flux:select wire:model="regCheckOutMethod" :disabled="! $regFixOut">
                        <flux:select.option value="id_card">via ID Card</flux:select.option>
                        <flux:select.option value="face">via Face</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <p class="mt-1.5 text-[11px] text-zinc-400">Unticked punches keep their recorded time.</p>
        </div>
        @endif

        {{-- 4 · Why --}}
        <div>
            <div class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-widest text-zinc-400"><span class="inline-flex size-4 items-center justify-center rounded-full bg-orange-100 text-[9px] text-orange-600">4</span> Reason &amp; proof</div>
            <flux:textarea wire:model="regReason" placeholder="e.g. Forgot to clock out — left through the loading gate…" rows="2" />
            @error('regReason')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            <div class="mt-2">
                <input type="file" wire:model="regAttachment" accept=".jpg,.jpeg,.png,.webp,.pdf"
                       class="block w-full cursor-pointer rounded-xl border border-zinc-200 dark:border-zinc-800 text-sm text-zinc-500 file:mr-3 file:cursor-pointer file:rounded-l-xl file:border-0 file:bg-orange-50 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-orange-600 hover:file:bg-orange-100" />
                <p class="mt-1 text-[11px] text-zinc-400">Optional — gate pass, screenshot or medical slip (jpg/png/pdf, max 5 MB).</p>
                <div wire:loading wire:target="regAttachment" class="mt-1 text-xs text-orange-500">Uploading…</div>
                @if($regAttachment)<div class="mt-1 flex items-center gap-1.5 text-xs font-semibold text-emerald-600"><flux:icon.check-circle class="size-3.5" /> {{ $regAttachment->getClientOriginalName() }}</div>@endif
                @error('regAttachment')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
        </div>

        {{-- Approval flow --}}
        <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2.5">
            <div class="mb-1.5 text-[10px] font-bold uppercase tracking-widest text-zinc-400">What happens next</div>
            <div class="flex items-center gap-1.5 text-[11px] font-semibold text-zinc-500 dark:text-zinc-400">
                Manager <flux:icon.chevron-right class="size-3 text-zinc-300" /> HR <flux:icon.chevron-right class="size-3 text-zinc-300" /> Admin <flux:icon.chevron-right class="size-3 text-zinc-300" /> <span class="text-emerald-600">Approved — hours recalculated</span>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
            <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
            <flux:button wire:click="submitRegularisation" variant="primary" wire:loading.attr="disabled" wire:target="regAttachment,submitRegularisation">
                <span wire:loading.remove wire:target="submitRegularisation">Submit Request</span>
                <span wire:loading wire:target="submitRegularisation">Submitting…</span>
            </flux:button>
        </div>
    </div>
</flux:modal>

{{-- ═══════════════════════════════════════════════
     PUNCH CAPTURE MODAL — selfie + geolocation
═══════════════════════════════════════════════ --}}
<flux:modal name="punch-capture" class="max-w-md"
    x-data="{
        action: 'in', lat: null, lng: null, photo: null,
        stream: null, status: 'idle', geoStatus: 'pending', busy: false,
        async openCapture(action) {
            this.action = action; this.photo = null; this.lat = null; this.lng = null;
            this.geoStatus = 'pending'; this.busy = false;
            this.getLocation();
            await this.startCamera();
        },
        getLocation() {
            if (! ('geolocation' in navigator)) { this.geoStatus = 'unavailable'; return; }
            navigator.geolocation.getCurrentPosition(
                p => { this.lat = +p.coords.latitude.toFixed(6); this.lng = +p.coords.longitude.toFixed(6); this.geoStatus = 'ok'; },
                () => { this.geoStatus = 'denied'; },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        },
        async startCamera() {
            if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) { this.status = 'nocamera'; return; }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.status = 'camera';
                this.$nextTick(() => { if (this.$refs.video) this.$refs.video.srcObject = this.stream; });
            } catch (e) { this.status = 'nocamera'; }
        },
        capture() {
            const v = this.$refs.video, c = this.$refs.canvas;
            if (! v) return;
            const w = 360, h = Math.round(w * (v.videoHeight || 480) / (v.videoWidth || 640));
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(v, 0, 0, w, h);
            this.photo = c.toDataURL('image/jpeg', 0.7);
            this.stopCamera(); this.status = 'preview';
        },
        retake() { this.photo = null; this.startCamera(); },
        stopCamera() { if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; } },
        cleanup() { this.stopCamera(); this.status = 'idle'; this.busy = false; },
        async submit() {
            if (this.busy) return;
            this.busy = true;
            try {
                if (this.action === 'in') { await this.$wire.checkIn(this.lat, this.lng, this.photo); }
                else { await this.$wire.checkOut(this.lat, this.lng, this.photo); }
            } finally {
                this.cleanup();
                this.$flux.modal('punch-capture').close();
            }
        }
    }"
    x-on:open-punch.window="openCapture($event.detail.action)"
    x-on:close="cleanup()">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" x-text="action === 'in' ? 'Clock In' : 'End Work Day'">Clock In</flux:heading>
            <flux:subheading>Confirm with a quick selfie &amp; your location.</flux:subheading>
        </div>

        <div class="relative aspect-[4/3] w-full overflow-hidden rounded-2xl bg-zinc-900">
            <video x-ref="video" autoplay playsinline muted x-show="status === 'camera'" class="h-full w-full object-cover"></video>
            <img :src="photo" x-show="status === 'preview' && photo" class="h-full w-full object-cover" alt="Selfie preview">
            <div x-show="status === 'idle'" class="absolute inset-0 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.camera class="size-9 animate-pulse" />
            </div>
            <div x-show="status === 'nocamera'" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center text-zinc-400">
                <flux:icon.video-camera-slash class="mb-2 size-9" />
                <p class="text-xs">Camera unavailable — you can still clock in without a photo.</p>
            </div>
            <canvas x-ref="canvas" class="hidden"></canvas>
        </div>

        <div class="flex items-center gap-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-xs dark:bg-zinc-800/50">
            <flux:icon.map-pin class="size-4 shrink-0"
                ::class="geoStatus === 'ok' ? 'text-emerald-500' : (geoStatus === 'pending' ? 'text-zinc-400 animate-pulse' : 'text-amber-500')" />
            <span x-show="geoStatus === 'pending'" class="text-zinc-400">Getting your location…</span>
            <span x-show="geoStatus === 'ok'" class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="'Location captured · ' + lat + ', ' + lng"></span>
            <span x-show="geoStatus === 'denied'" class="text-amber-600 dark:text-amber-400">Location off — clocking in without it.</span>
            <span x-show="geoStatus === 'unavailable'" class="text-amber-600 dark:text-amber-400">Location unavailable on this device.</span>
        </div>

        <div class="flex items-center justify-between gap-2 pt-1">
            <button type="button" @click="cleanup(); $flux.modal('punch-capture').close()"
                class="rounded-xl px-4 py-2 text-sm font-bold text-zinc-500 dark:text-zinc-400 transition hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-800">Cancel</button>
            <div class="flex items-center gap-2">
                <button type="button" x-show="status === 'preview'" @click="retake()"
                    class="rounded-xl bg-zinc-100 dark:bg-zinc-800 px-4 py-2 text-sm font-bold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200">Retake</button>
                <button type="button" x-show="status === 'camera'" @click="capture()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-zinc-800 px-4 py-2 text-sm font-bold text-white transition hover:bg-zinc-900 dark:bg-zinc-700">
                    <flux:icon.camera class="size-4" /> Capture
                </button>
                <button type="button" @click="submit()" x-bind:disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-orange-300/40 transition hover:bg-orange-600 disabled:opacity-50"
                    x-text="busy ? 'Saving…' : (action === 'in' ? 'Clock In' : 'Clock Out')">Clock In</button>
            </div>
        </div>
        <p class="text-center text-[10px] text-zinc-400">Photo &amp; location are optional — you can clock in without them.</p>
    </div>
</flux:modal>

</flux:main>
