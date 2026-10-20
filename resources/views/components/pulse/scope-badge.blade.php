@props([
    // PeopleScope::summary() for the permission this page uses.
    'scope',
    'dark' => false,
])

@php
    $impersonating = session()->has(\App\Models\AuditLog::IMPERSONATOR_SESSION_KEY);
    $viewer = auth()->user();
    $icon = match ($scope['kind'] ?? 'team') {
        'company' => 'globe-alt',
        'department' => 'building-office-2',
        'own' => 'user',
        default => 'user-group',
    };
    $kindLabel = match ($scope['kind'] ?? 'team') {
        'company' => 'Company scope',
        'department' => 'Department scope',
        'own' => 'Own records',
        'none' => null,
        default => 'Team scope',
    };
@endphp

{{-- "Whose data is this?" on every management page — never leave the reader guessing. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }} data-scope-badge="{{ $scope['kind'] ?? 'team' }}">
    @if($impersonating)
        <span @class([
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold',
            'bg-amber-400/20 text-amber-100 ring-1 ring-amber-300/40' => $dark,
            'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => ! $dark,
        ]) data-viewing-as>
            <flux:icon.eye class="size-3.5" />
            Viewing as {{ $viewer?->name }}@if($viewer?->assignedRole) · {{ $viewer->assignedRole->name }}@endif
        </span>
    @endif
    <span @class([
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold',
        'bg-white/15 text-white ring-1 ring-white/25' => $dark,
        'bg-orange-50 text-orange-800 ring-1 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/30' => ! $dark,
    ]) title="The people this page covers, from your role's permission scope">
        <flux:icon :name="$icon" class="size-3.5" />
        Scope: {{ $scope['label'] }}
        <span @class(['font-normal', 'text-white/70' => $dark, 'text-orange-700/80 dark:text-orange-300/80' => ! $dark])>@if($kindLabel)· {{ $kindLabel }} @endif· {{ $scope['detail'] }}</span>
    </span>
</div>
