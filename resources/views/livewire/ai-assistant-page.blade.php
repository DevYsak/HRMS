<flux:main class="bg-zinc-50 p-0! dark:bg-zinc-950">

    <div class="flex h-[calc(100dvh-57px)] min-h-[32rem] flex-col" data-ai-assistant-page>

        {{-- ── Header ── --}}
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 bg-white px-4 py-3 sm:px-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex min-w-0 items-center gap-3">
                <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-orange-500 shadow-sm shadow-orange-200/50 dark:shadow-none">
                    <flux:icon.sparkles class="size-5 text-white" />
                </div>
                <div class="min-w-0">
                    <h1 class="pulse-page-title text-base">AI Assistant</h1>
                    <p class="pulse-page-subtitle text-xs">Answers are limited to your authorised HR data.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[11px] font-semibold text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300" data-ai-scope title="The widest data you can ask about">
                    <flux:icon.shield-check class="size-3.5" /> {{ $scopeLabel }}
                </span>
                @if($enabled)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                        <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $modelConnected, 'bg-zinc-400' => ! $modelConnected])></span>
                        {{ $modelConnected ? 'Pulse data + '.$providerLabel : 'Pulse data & help only' }}
                    </span>
                @endif
                @if(count($messages) > 0)
                    <button type="button" wire:click="clearChat" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        <flux:icon.arrow-path class="size-3.5" /> New chat
                    </button>
                @endif
            </div>
        </header>

        @if(! $enabled)
            {{-- ── Switched off ── --}}
            <div class="flex flex-1 items-center justify-center p-6">
                <div class="max-w-md rounded-2xl border border-zinc-200 bg-white p-6 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <flux:icon.sparkles class="mx-auto size-8 text-zinc-300" />
                    <h2 class="mt-3 text-base font-bold text-zinc-900 dark:text-white">The AI Assistant is not switched on for you</h2>
                    <p class="mt-1 text-sm text-zinc-500">An administrator turns it on and chooses which roles can use it.</p>
                    @if($canConfigure)
                        <a href="{{ route('settings.ai') }}" wire:navigate class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3.5 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                            <flux:icon.cog-6-tooth class="size-4" /> Open AI settings
                        </a>
                    @endif
                </div>
            </div>
        @else
            <div class="flex min-h-0 flex-1">

                {{-- ── Left: suggested questions (desktop) ── --}}
                <aside class="hidden w-72 shrink-0 flex-col border-r border-zinc-200 bg-white lg:flex dark:border-zinc-800 dark:bg-zinc-900" aria-label="Suggested questions">
                    <div class="flex-1 space-y-5 overflow-y-auto overscroll-contain px-3 py-4">
                        @foreach($suggestions as $group => $questions)
                            <div>
                                <p class="mb-1.5 px-2 text-[10px] font-bold uppercase tracking-wider text-zinc-400">{{ $group }}</p>
                                <div class="space-y-0.5">
                                    @foreach($questions as $q)
                                        <button type="button" wire:click="sendQuick({{ \Illuminate\Support\Js::from($q['prompt']) }})" data-ai-suggestion
                                            @class(['group flex w-full items-start gap-2.5 rounded-lg px-2.5 py-2 text-left transition hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-orange-50 dark:bg-orange-500/10' => $activeQuestion === $q['prompt']])>
                                            <flux:icon :name="$q['icon']" @class(['mt-0.5 size-4 shrink-0', 'text-orange-500' => $activeQuestion === $q['prompt'], 'text-zinc-400 group-hover:text-zinc-600' => $activeQuestion !== $q['prompt']]) />
                                            <span class="min-w-0">
                                                <span class="block text-xs font-semibold text-zinc-700 dark:text-zinc-200">{{ $q['label'] }}</span>
                                                <span class="mt-0.5 block text-[11px] text-zinc-400 [overflow-wrap:anywhere]">{{ $q['prompt'] }}</span>
                                            </span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </aside>

                {{-- ── Main: conversation + composer ── --}}
                <section class="flex min-w-0 flex-1 flex-col">
                    <div class="flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-5 sm:px-6"
                         x-data
                         x-init="$el.scrollTop = $el.scrollHeight"
                         x-on:livewire:navigated.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                         x-effect="$wire.messages; $nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                         aria-live="polite">

                        @if(count($messages) === 0)
                            {{-- Onboarding --}}
                            <div class="mx-auto max-w-2xl space-y-4" data-ai-onboarding>
                                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                    <h2 class="text-base font-bold text-zinc-900 dark:text-white">How to use the AI Assistant</h2>
                                    <ol class="mt-3 grid gap-3 sm:grid-cols-3">
                                        <li class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-orange-600">1 · Ask</p>
                                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Type a question in plain words, or pick a suggestion.</p>
                                        </li>
                                        <li class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-orange-600">2 · Read</p>
                                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Counts and balances come straight from Pulse — the same numbers your pages show.</p>
                                        </li>
                                        <li class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                            <p class="text-[11px] font-bold uppercase tracking-wide text-orange-600">3 · Go</p>
                                            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">Use the buttons under an answer to open the right page.</p>
                                        </li>
                                    </ol>
                                    @if(filled($introVideoUrl))
                                        <a href="{{ $introVideoUrl }}" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-1.5 text-xs font-semibold text-orange-600 hover:underline">
                                            <flux:icon.play-circle class="size-4" /> Watch the walkthrough
                                        </a>
                                    @endif
                                </div>

                                <details class="group rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                                    <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-semibold text-zinc-900 dark:text-white">
                                        What can I ask?
                                        <flux:icon.chevron-down class="size-4 text-zinc-400 transition group-open:rotate-180" />
                                    </summary>
                                    <ul class="mt-3 space-y-1.5 text-xs text-zinc-600 dark:text-zinc-300">
                                        <li>• Your own requests: “How many OT requests do I have?”, “Do I have any pending leave requests?”</li>
                                        <li>• Your balances: “What is my leave balance?”</li>
                                        @if(isset($suggestions['Approvals']))
                                            <li>• What waits for you: “How many OT requests are pending my approval?” — your own requests are never counted here.</li>
                                        @endif
                                        @if(isset($suggestions['Organisation']))
                                            <li>• Figures for the people you can see: headcount, probation, expiring documents.</li>
                                        @endif
                                        <li>• Where things are: “Where do I regularise a missed punch?”</li>
                                    </ul>
                                    <p class="mt-3 text-[11px] text-zinc-400">You only ever get answers about data you are already allowed to see. If Pulse can't answer, it shows you where to look.</p>
                                </details>

                                {{-- Suggestions on smaller screens --}}
                                <div class="flex flex-wrap gap-2 lg:hidden">
                                    @foreach(collect($suggestions)->flatten(1)->take(6) as $q)
                                        <button type="button" wire:click="sendQuick({{ \Illuminate\Support\Js::from($q['prompt']) }})" data-ai-suggestion
                                            class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-600 transition hover:border-orange-300 hover:text-orange-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ $q['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            @foreach($messages as $m)
                                <div @class(['flex gap-3', 'justify-end' => $m['role'] === 'user', 'justify-start' => $m['role'] !== 'user']) wire:key="msg-{{ $loop->index }}">
                                    @if($m['role'] === 'assistant')
                                        <div class="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full bg-orange-500">
                                            <flux:icon.sparkles class="size-3.5 text-white" />
                                        </div>
                                    @endif

                                    <div @class([
                                        'min-w-0 max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-relaxed sm:max-w-[75%]',
                                        'rounded-br-sm bg-orange-500 text-white' => $m['role'] === 'user',
                                        'rounded-bl-sm border border-zinc-100 bg-white text-zinc-700 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' => $m['role'] !== 'user',
                                    ]) data-ai-message="{{ $m['role'] }}">
                                        <p class="whitespace-pre-line [overflow-wrap:anywhere]">{{ $m['content'] }}</p>

                                        @if(! empty($m['links']))
                                            <div class="mt-3 flex flex-wrap gap-2">
                                                @foreach($m['links'] as $link)
                                                    <a href="{{ $link['url'] }}" wire:navigate data-ai-link
                                                        class="inline-flex items-center gap-1 rounded-lg border border-orange-200 bg-orange-50 px-2.5 py-1 text-xs font-semibold text-orange-700 transition hover:bg-orange-100 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                                                        {{ $link['label'] }} <flux:icon.arrow-right class="size-3" />
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if(($m['source'] ?? null) === 'pulse')
                                            <p class="mt-2 text-[10px] font-medium uppercase tracking-wide text-zinc-400">From your Pulse records</p>
                                        @elseif(($m['source'] ?? null) === 'ai')
                                            <p class="mt-2 text-[10px] font-medium uppercase tracking-wide text-zinc-400">Written by {{ $providerLabel }} from your authorised figures</p>
                                        @endif
                                    </div>

                                    @if($m['role'] === 'user')
                                        <div class="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 dark:bg-zinc-700">
                                            <flux:icon.user class="size-3.5 text-zinc-600 dark:text-zinc-300" />
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif

                        <div wire:loading wire:target="send,sendQuick" class="flex justify-start gap-3">
                            <div class="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full bg-orange-500">
                                <flux:icon.sparkles class="size-3.5 text-white" />
                            </div>
                            <div class="rounded-2xl rounded-bl-sm border border-zinc-100 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                                <div class="flex items-center gap-1.5" aria-label="Thinking">
                                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.3s]"></span>
                                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400 [animation-delay:-0.15s]"></span>
                                    <span class="size-1.5 animate-bounce rounded-full bg-zinc-400"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Composer --}}
                    <div class="border-t border-zinc-200 bg-white px-4 py-3 sm:px-6 dark:border-zinc-800 dark:bg-zinc-900">
                        <form wire:submit="send">
                            <label for="ai-question" class="sr-only">Ask the AI Assistant</label>
                            <div class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 transition focus-within:border-orange-400 focus-within:ring-2 focus-within:ring-orange-500/20 dark:border-zinc-700 dark:bg-zinc-800">
                                <input id="ai-question" wire:model="input" type="text" maxlength="500" autocomplete="off"
                                    placeholder="Ask about your requests, leave, approvals or where to find something…"
                                    class="min-w-0 flex-1 border-0 bg-transparent text-sm text-zinc-900 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-zinc-100" />
                                <button type="submit" wire:loading.attr="disabled" wire:target="send,sendQuick" aria-label="Send question"
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-orange-500 text-white transition hover:bg-orange-600 disabled:opacity-40">
                                    <flux:icon.paper-airplane class="size-4" />
                                </button>
                            </div>
                            @error('input') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-center text-[10px] text-zinc-400">Press Enter to send · Answers use only data you are allowed to see</p>
                        </form>
                    </div>
                </section>
            </div>
        @endif
    </div>

</flux:main>
