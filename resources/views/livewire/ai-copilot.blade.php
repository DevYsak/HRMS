<div>
@if($enabled)
    <div x-data="{ open: false }" class="fixed bottom-5 right-5 z-50">
        {{-- Toggle button --}}
        <button type="button" @click="open = !open" x-show="!open"
            class="flex size-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg shadow-brand-900/30 transition hover:bg-brand-700">
            <flux:icon.sparkles class="size-6" />
        </button>

        {{-- Panel --}}
        <div x-show="open" x-transition x-cloak
            class="flex h-[32rem] w-[22rem] max-w-[90vw] flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between bg-brand-600 px-4 py-3">
                <div class="flex items-center gap-2 text-white">
                    <flux:icon.sparkles class="size-4" />
                    <span class="text-sm font-bold">HR Copilot</span>
                </div>
                <button type="button" @click="open = false" class="text-white/80 hover:text-white">
                    <flux:icon.x-mark class="size-5" />
                </button>
            </div>

            <div class="flex-1 space-y-3 overflow-y-auto p-4">
                @forelse($messages as $m)
                    <div class="flex {{ $m['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] whitespace-pre-line rounded-2xl px-3 py-2 text-sm {{ $m['role'] === 'user' ? 'bg-brand-600 text-white' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}">
                            {{ $m['content'] }}
                        </div>
                    </div>
                @empty
                    <div class="py-8 text-center text-xs text-zinc-400">
                        Ask about your leave balance, pending approvals, headcount, probation or expiring documents…
                    </div>
                @endforelse
                <div wire:loading wire:target="send" class="flex justify-start">
                    <div class="rounded-2xl bg-zinc-100 px-3 py-2 text-sm text-zinc-400 dark:bg-zinc-800">Thinking…</div>
                </div>
            </div>

            <form wire:submit="send" class="border-t border-zinc-100 p-3 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <input wire:model="input" type="text" placeholder="Ask the HR Copilot…" autocomplete="off"
                        class="h-9 flex-1 rounded-lg border border-zinc-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
                    <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
                        <flux:icon.paper-airplane class="size-4" />
                    </button>
                </div>
            </form>
        </div>
    </div>
@endif
</div>
