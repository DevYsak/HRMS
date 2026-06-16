<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-0">

    <div class="flex h-[calc(100vh-57px)]">

        {{-- ── LEFT PANEL — Quick questions ── --}}
        <div class="hidden w-64 shrink-0 flex-col border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 md:flex">

            {{-- Header --}}
            <div class="border-b border-zinc-100 dark:border-zinc-800 px-4 py-4">
                <div class="flex items-center gap-2.5">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-brand-500 shadow-sm shadow-brand-200/50">
                        <flux:icon.sparkles class="size-4 text-white" />
                    </div>
                    <div>
                        <p class="text-sm font-bold text-zinc-900 dark:text-white">HR Copilot</p>
                        <p class="text-[10px] text-zinc-400">Your AI assistant</p>
                    </div>
                </div>
            </div>

            {{-- Quick questions --}}
            <div class="flex-1 overflow-y-auto px-3 py-3">
                <p class="mb-2 px-1 text-[10px] font-bold uppercase tracking-wider text-zinc-400">Quick Questions</p>
                <div class="space-y-0.5">
                    @foreach($quickQuestions as $q)
                        <button type="button" wire:click="sendQuick('{{ addslashes($q['prompt']) }}')"
                            class="group w-full rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800
                                {{ $activeQuestion === $q['prompt'] ? 'bg-brand-50 dark:bg-brand-900/20' : '' }}">
                            <div class="flex items-start gap-2.5">
                                <flux:icon :name="$q['icon']"
                                    class="mt-0.5 size-4 shrink-0 {{ $activeQuestion === $q['prompt'] ? 'text-brand-500' : 'text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-300' }}" />
                                <div>
                                    <p class="text-xs font-semibold {{ $activeQuestion === $q['prompt'] ? 'text-brand-700 dark:text-brand-300' : 'text-zinc-700 dark:text-zinc-300' }}">
                                        {{ $q['label'] }}
                                    </p>
                                    <p class="mt-0.5 text-[10px] text-zinc-400 line-clamp-2">{{ $q['prompt'] }}</p>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Clear chat --}}
            @if(count($messages) > 0)
                <div class="border-t border-zinc-100 dark:border-zinc-800 p-3">
                    <button type="button" wire:click="clearChat"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-zinc-500 transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:text-zinc-700">
                        <flux:icon.trash class="size-3.5" /> Clear conversation
                    </button>
                </div>
            @endif
        </div>

        {{-- ── RIGHT PANEL — Chat ── --}}
        <div class="flex flex-1 flex-col min-w-0">

            {{-- API status banner --}}
            @if(!$enabled)
                <div class="border-b border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-950/20 px-5 py-2.5">
                    <div class="flex items-center gap-3">
                        <flux:icon.exclamation-triangle class="size-4 shrink-0 text-amber-600" />
                        <p class="text-xs font-semibold text-amber-700 dark:text-amber-400">
                            API key not configured —
                            <a href="{{ route('settings.general') }}" wire:navigate class="underline hover:no-underline">open Settings to add one</a>
                        </p>
                        <div class="ml-auto flex items-center gap-4 text-[11px] text-amber-600">
                            <span class="font-medium">Provider</span>
                            <span class="rounded border border-amber-200 bg-white/60 px-2 py-0.5 font-mono text-amber-700">OpenAI</span>
                            <span class="font-medium">Model</span>
                            <span class="rounded border border-amber-200 bg-white/60 px-2 py-0.5 font-mono text-amber-700">gpt-4o-mini</span>
                        </div>
                    </div>
                </div>
            @else
                <div class="border-b border-emerald-200 bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-950/20 px-5 py-2">
                    <div class="flex items-center gap-2 text-[11px] text-emerald-700 dark:text-emerald-400">
                        <span class="size-1.5 rounded-full bg-emerald-500 inline-block"></span>
                        <span class="font-semibold">AI Connected</span>
                        <span class="text-emerald-500 mx-1">·</span>
                        <span>Scoped to your role — answers only from your HR data</span>
                    </div>
                </div>
            @endif

            {{-- Page title bar --}}
            <div class="flex items-center justify-between border-b border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-6 py-3">
                <div>
                    <h1 class="pulse-page-title text-base">AI Assistant</h1>
                    <p class="pulse-page-subtitle text-[11px]">Ask about leave, headcount, performance, documents &amp; more</p>
                </div>
                @if(count($messages) > 0)
                    <button type="button" wire:click="clearChat"
                        class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition md:hidden">
                        <flux:icon.arrow-path class="size-3.5" /> New chat
                    </button>
                @endif
            </div>

            {{-- Messages area --}}
            <div class="flex-1 overflow-y-auto px-6 py-6 space-y-4"
                 id="chat-scroll"
                 x-data
                 x-init="$el.scrollTop = $el.scrollHeight"
                 x-on:livewire:navigated.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })">

                @if(count($messages) === 0)
                    {{-- Empty state --}}
                    <div class="flex h-full flex-col items-center justify-center text-center py-16">
                        <div class="flex size-16 items-center justify-center rounded-2xl bg-brand-50 dark:bg-brand-900/20 mb-4">
                            <flux:icon.sparkles class="size-8 text-brand-500" />
                        </div>
                        <h2 class="text-lg font-bold text-zinc-800 dark:text-zinc-100">Your Work Assistant</h2>
                        <p class="mt-1 max-w-xs text-sm text-zinc-400">
                            @if($enabled)
                                Ask me about leave balances, pending approvals, headcount, expiring documents, or anything HR-related.
                            @else
                                Configure your API key in Settings above to get started.
                            @endif
                        </p>
                        @if($enabled)
                            <div class="mt-6 flex flex-wrap justify-center gap-2 max-w-md">
                                @foreach(array_slice($quickQuestions, 0, 4) as $q)
                                    <button type="button" wire:click="sendQuick('{{ addslashes($q['prompt']) }}')"
                                        class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-3 py-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300 hover:border-brand-300 hover:text-brand-700 transition">
                                        {{ $q['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @foreach($messages as $m)
                        <div class="flex {{ $m['role'] === 'user' ? 'justify-end' : 'justify-start' }} gap-3">

                            @if($m['role'] === 'assistant')
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 mt-1">
                                    <flux:icon.sparkles class="size-3.5 text-white" />
                                </div>
                            @endif

                            <div class="max-w-[75%] whitespace-pre-line rounded-2xl px-4 py-3 text-sm leading-relaxed
                                {{ $m['role'] === 'user'
                                    ? 'bg-brand-600 text-white rounded-br-sm'
                                    : 'bg-white dark:bg-zinc-800 border border-zinc-100 dark:border-zinc-700 text-zinc-700 dark:text-zinc-200 rounded-bl-sm shadow-sm' }}">
                                {{ $m['content'] }}
                            </div>

                            @if($m['role'] === 'user')
                                <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 dark:bg-zinc-700 mt-1">
                                    <flux:icon.user class="size-3.5 text-zinc-600 dark:text-zinc-300" />
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div wire:loading wire:target="send,sendQuick" class="flex justify-start gap-3">
                        <div class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-500 mt-1">
                            <flux:icon.sparkles class="size-3.5 text-white" />
                        </div>
                        <div class="rounded-2xl rounded-bl-sm border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-4 py-3 shadow-sm">
                            <div class="flex items-center gap-1.5">
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce [animation-delay:-0.3s]"></span>
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce [animation-delay:-0.15s]"></span>
                                <span class="size-1.5 rounded-full bg-zinc-400 animate-bounce"></span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Input bar --}}
            <div class="border-t border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-6 py-4">
                <form wire:submit="send">
                    <div class="flex items-center gap-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-4 py-2.5 focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100 dark:focus-within:ring-brand-900/30 transition">
                        <input wire:model="input" type="text"
                            placeholder="{{ $enabled ? 'Ask about projects, team, attendance, leave...' : 'API key not configured — see Settings' }}"
                            {{ !$enabled ? 'disabled' : '' }}
                            autocomplete="off"
                            class="flex-1 border-0 bg-transparent text-sm text-zinc-900 dark:text-zinc-100 placeholder:text-zinc-400 outline-none focus:ring-0 disabled:cursor-not-allowed disabled:opacity-50" />
                        <button type="submit"
                            wire:loading.attr="disabled" wire:target="send,sendQuick"
                            {{ !$enabled ? 'disabled' : '' }}
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white transition hover:bg-brand-700 disabled:opacity-40">
                            <svg class="size-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                        </button>
                    </div>
                    <p class="mt-2 text-center text-[10px] text-zinc-400">Press Enter to send · Answers are scoped to your HR role</p>
                </form>
            </div>

        </div>
    </div>

</flux:main>
