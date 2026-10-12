{{--
    Three-card row under Today Activity: Attendance Needs Attention (only when
    something needs action), Today's Summary and Quick Stats for the month.
--}}
@php
    $pendingRegs = $this->myRegularisations->where('status', 'pending');
    $conflicts = (int) ($pj['conflict_count'] ?? 0);
    $lateWarning = (bool) ($analytics['late_warning'] ?? false);
    $hasAttention = $todayMissing || $issues->isNotEmpty() || $lateWarning || $pendingRegs->isNotEmpty() || $conflicts > 0 || $this->shiftUnassigned;
@endphp

<div @class([
    'grid grid-cols-1 gap-5 md:grid-cols-2',
    'xl:grid-cols-[minmax(0,1fr)_minmax(0,0.95fr)_minmax(0,1.35fr)]' => $hasAttention,
    'xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]' => ! $hasAttention,
]) data-overview>
    @if($hasAttention)
        @include('attendance.my.attention')
    @endif
    @include('attendance.my.summary')
    @include('attendance.my.month')
</div>
