@props(['revision'])
{{-- An HR-proposed change: the request as submitted beside the version HR proposes. Shared by HR and the employee. --}}
@php
    $original = $revision->original;
    $proposed = $revision->proposed;
    $typeNames = \App\Models\LeaveType::withTrashed()->whereIn('id', array_filter([$original['leave_type_id'] ?? null, $proposed['leave_type_id'] ?? null]))->pluck('name', 'id');
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->toImmutable()->userDate() : '—';
    $paid = fn (array $v) => $num(max(0, (float) ($v['days'] ?? 0) - (float) ($v['lwp_days'] ?? 0)));
    $session = fn ($v) => ['first_half' => 'First half', 'second_half' => 'Second half'][$v] ?? '—';
    $rows = [
        'Dates' => [$date($original['start_date']).' – '.$date($original['end_date']), $date($proposed['start_date']).' – '.$date($proposed['end_date'])],
        'Leave type / reason' => [$typeNames[$original['leave_type_id']] ?? '—', $typeNames[$proposed['leave_type_id']] ?? '—'],
        'Full or half day' => [($original['is_half_day'] ?? false) ? 'Half day · '.$session($original['half_day_period'] ?? null) : 'Full days', ($proposed['is_half_day'] ?? false) ? 'Half day · '.$session($proposed['half_day_period'] ?? null) : 'Full days'],
        'Days' => [$num($original['days']), $num($proposed['days'])],
        'Paid days' => [$paid($original), $paid($proposed)],
        'Unpaid (LWP) days' => [$num($original['lwp_days'] ?? 0), $num($proposed['lwp_days'] ?? 0)],
    ];
    $allocation = fn (array $v) => collect($v['rows'] ?? [])->filter(fn ($r) => $r['chargeable'])
        ->map(fn ($r) => $date($r['date']).': '.(\App\Models\LeaveRequestDay::LABELS[$r['allocation']] ?? $r['allocation']).($r['session'] ? ' ('.$session($r['session']).')' : ''));
@endphp
<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }} data-revision-compare="{{ $revision->id }}">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-zinc-500 dark:text-zinc-400">
                <th class="py-1.5 pr-3 font-medium"></th>
                <th class="py-1.5 pr-3 font-medium">As submitted</th>
                <th class="py-1.5 font-medium">Proposed by HR</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
            @foreach($rows as $label => [$before, $after])
                <tr @class(['font-semibold' => $before !== $after])>
                    <td class="py-1.5 pr-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</td>
                    <td class="py-1.5 pr-3 tabular-nums text-zinc-700 dark:text-zinc-200">{{ $before }}</td>
                    <td class="py-1.5 tabular-nums {{ $before !== $after ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-700 dark:text-zinc-200' }}" @if($before !== $after) data-changed @endif>{{ $after }}</td>
                </tr>
            @endforeach
            @if(! empty($proposed['rows']))
                <tr>
                    <td class="py-1.5 pr-3 align-top text-xs text-zinc-500 dark:text-zinc-400">Date allocation</td>
                    <td class="py-1.5 pr-3 align-top text-xs text-zinc-700 dark:text-zinc-200">@foreach($allocation($original) as $line)<span class="block">{{ $line }}</span>@endforeach</td>
                    <td class="py-1.5 align-top text-xs text-zinc-700 dark:text-zinc-200">@foreach($allocation($proposed) as $line)<span class="block">{{ $line }}</span>@endforeach</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
