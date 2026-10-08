{{-- One email log row ($log). --}}
<tr>
    <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $log->to_email }}</td>
    <td class="px-3 py-2.5 text-zinc-500">{{ \Illuminate\Support\Str::limit($log->subject, 48) }}</td>
    <td class="px-3 py-2.5 text-center">
        <span @class([
            'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
            'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $log->status === 'sent',
            'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' => $log->status === 'sending',
            'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' => $log->status === 'failed',
            'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $log->status === 'skipped',
        ])
            title="{{ $log->skip_reason }}"
        >{{ ucfirst($log->status) }}{{ $log->skip_reason ? ' — '.\Illuminate\Support\Str::headline($log->skip_reason) : '' }}</span>
    </td>
    <td class="px-5 py-2.5 text-right text-[11px] text-zinc-400">{{ $log->created_at?->diffForHumans() }}</td>
</tr>
