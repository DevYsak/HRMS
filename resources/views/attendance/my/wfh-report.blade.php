{{-- WFH Daily Report — only on a WFH or hybrid day. --}}
@if($heroMode && in_array($heroMode->value, ['wfh', 'hybrid'], true))
    <section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="wfh-report-title">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="wfh-report-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">WFH Daily Report</h2>
            @if($wfhReport)<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-700"><flux:icon.check class="size-3" /> Submitted</span>@endif
        </div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs font-medium text-zinc-500">What did you work on today? <span class="text-rose-500">*</span></label>
                <textarea wire:model="wfhForm.work_summary" rows="2" placeholder="Tasks, tickets, meetings…" class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900"></textarea>
                @error('wfhForm.work_summary')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div><label class="mb-1 block text-xs font-medium text-zinc-500">Achievements</label><textarea wire:model="wfhForm.achievements" rows="2" class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900"></textarea></div>
            <div><label class="mb-1 block text-xs font-medium text-zinc-500">Blockers</label><textarea wire:model="wfhForm.blockers" rows="2" class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900"></textarea></div>
        </div>
        <div class="mt-3 flex justify-end">
            <button type="button" wire:click="saveWfhReport" class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600"><flux:icon.paper-airplane class="size-4" /> {{ $wfhReport ? 'Update Report' : 'Submit Report' }}</button>
        </div>
    </section>
@endif
