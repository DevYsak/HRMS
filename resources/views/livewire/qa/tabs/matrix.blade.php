@php
    $chip = [
        'allowed' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'scoped' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'explicit' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'denied' => 'bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500',
    ];
    $word = ['allowed' => 'Allowed', 'scoped' => 'Scoped', 'explicit' => 'Explicit only', 'denied' => 'Denied'];
@endphp

<div class="space-y-4" data-tab-panel="matrix">
    <div class="ui-surface flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-zinc-600 dark:text-zinc-300">Live from Roles &amp; Permissions and the scope defaults — the same inputs every page checks. Role = type of user, permission = action, scope = whose data.</p>
        <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
            @foreach($word as $key => $label)
                <span class="rounded-md px-2 py-1 {{ $chip[$key] }}">{{ $label }}</span>
            @endforeach
        </div>
    </div>

    @foreach($matrix['groups'] as $group => $rows)
        <div class="ui-surface overflow-hidden">
            <h3 class="border-b border-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-900 dark:border-white/5 dark:text-white">{{ $group }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-xs">
                    <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                        <tr>
                            <th class="sticky left-0 bg-zinc-50 px-4 py-2 dark:bg-zinc-900">Capability</th>
                            @foreach($matrix['roles'] as $r)
                                <th class="px-2 py-2 text-center">{{ $r->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $label => $row)
                            <tr>
                                <td class="sticky left-0 bg-white px-4 py-2 dark:bg-zinc-900">
                                    <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $label }}</p>
                                    <p class="font-mono text-[10px] text-zinc-400">{{ $row['key'] }}</p>
                                </td>
                                @foreach($matrix['roles'] as $r)
                                    @php $cell = $row['cells'][$r->slug]; @endphp
                                    <td class="px-2 py-2 text-center" data-cell="{{ $r->slug }}:{{ $row['key'] }}" data-access="{{ $cell['access'] }}">
                                        <span class="inline-flex flex-col rounded-md px-2 py-1 font-semibold {{ $chip[$cell['access']] }}">
                                            {{ $word[$cell['access']] }}
                                            @if($cell['scope'] && $cell['access'] !== 'denied')<span class="text-[10px] font-medium opacity-80">{{ $cell['scope'] }}</span>@endif
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
