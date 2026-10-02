@props(['max' => 'max-w-lg'])

{{-- A server-rendered dialog: shown while the Livewire state says so, so it
     survives re-renders (validation errors, previews) without client state. --}}
<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-16" role="dialog" aria-modal="true">
    <div {{ $attributes->merge(['class' => "w-full {$max} rounded-2xl border border-[#EAECF0] bg-white p-6 shadow-xl dark:border-white/10 dark:bg-zinc-900"]) }}>
        {{ $slot }}
    </div>
</div>
