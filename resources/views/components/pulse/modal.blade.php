@props([
    'title' => null,
    'subtitle' => null,
    // Alpine expression that closes the modal, e.g. "$wire.set('showModal', false)".
    'close' => null,
    'width' => 'max-w-lg',
    'id' => null,
])

@php
    $modalId = $id ?? 'modal-'.\Illuminate\Support\Str::random(6);
    // Width presets follow Settings → Brand & Layout (defaults = the original sizes).
    $width = [
        'small' => 'sm:max-w-[var(--ui-modal-sm,28rem)]', 'sm:max-w-md' => 'sm:max-w-[var(--ui-modal-sm,28rem)]',
        'medium' => 'sm:max-w-[var(--ui-modal-md,32rem)]', 'sm:max-w-lg' => 'sm:max-w-[var(--ui-modal-md,32rem)]', 'max-w-lg' => 'sm:max-w-[var(--ui-modal-md,32rem)]',
        'large' => 'sm:max-w-[var(--ui-modal-lg,42rem)]', 'sm:max-w-2xl' => 'sm:max-w-[var(--ui-modal-lg,42rem)]',
    ][$width] ?? $width;
@endphp

{{--
    The shared modal. Rendered by Livewire state (wrap it in @if), so it
    survives validation re-renders. While it exists:
      - the page behind cannot scroll ([data-app-modal] → html overflow hidden, app.css);
      - the panel never exceeds the viewport: header and footer stay put, the body scrolls;
      - focus is trapped inside, Escape and the backdrop close it, and focus
        returns to whatever opened it.
    Phones get a near full-screen sheet; larger screens a centred dialog.
--}}
<div data-app-modal role="dialog" aria-modal="true" aria-labelledby="{{ $modalId }}-title"
    class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
    x-data="{
        opener: document.activeElement,
        close() { {{ $close ?? '' }} },
        destroy() { if (this.opener && document.contains(this.opener)) this.opener.focus() },
    }"
    x-trap="true"
    x-on:keydown.escape.window.prevent="close()">

    <div class="absolute inset-0 bg-zinc-950/50 backdrop-blur-[2px]" x-on:click="close()" aria-hidden="true"></div>

    <div {{ $attributes->merge(['class' => "relative flex max-h-[calc(100dvh-1rem)] w-full {$width} flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl ring-1 ring-black/5 sm:max-h-[calc(100dvh-2rem)] sm:rounded-2xl dark:bg-zinc-900 dark:ring-white/10"]) }}>
        <header class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
            <div class="min-w-0">
                <h2 id="{{ $modalId }}-title" class="text-base font-bold text-zinc-900 [overflow-wrap:anywhere] dark:text-white">{{ $title }}</h2>
                @if($subtitle)
                    <p class="mt-0.5 text-sm text-zinc-500 [overflow-wrap:anywhere]">{{ $subtitle }}</p>
                @endif
                {{ $header ?? '' }}
            </div>
            <button type="button" x-on:click="close()" aria-label="{{ __('Close') }}"
                class="-mr-1 shrink-0 rounded-lg p-1.5 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500/40 dark:hover:bg-zinc-800 dark:hover:text-zinc-200">
                <flux:icon.x-mark class="size-5" />
            </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5" data-modal-body>
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex shrink-0 flex-col-reverse gap-2 border-t border-zinc-100 bg-zinc-50/80 px-5 py-3 sm:flex-row sm:items-center sm:justify-end dark:border-zinc-800 dark:bg-zinc-900/80">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>
