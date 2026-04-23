<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased">
        <div class="grid min-h-screen lg:grid-cols-2">

            {{-- =====================================================
                 LEFT PANEL — Photo + Dark branding (matches Figma)
                 ===================================================== --}}
            <div class="relative hidden lg:flex flex-col bg-[#0f1923]">
                {{-- Background photo (library/office feel per Figma) --}}
                <div class="absolute inset-0">
                    <img
                        src="https://images.unsplash.com/photo-1524178232363-1fb2b075b655?w=1200&q=80&auto=format&fit=crop"
                        alt="Office collaboration"
                        class="h-full w-full object-cover opacity-60"
                    />
                    <div class="absolute inset-0 bg-gradient-to-b from-[#0f1923]/40 via-transparent to-[#0f1923]/80"></div>
                </div>

                {{-- Logo top-left --}}
                <div class="relative z-10 p-8">
                    <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                        <span class="flex size-9 items-center justify-center rounded-lg bg-brand-600">
                            <svg class="size-5 fill-white" viewBox="0 0 24 24"><path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/></svg>
                        </span>
                        <span class="text-lg font-bold text-white tracking-tight">HRDashboard</span>
                    </a>
                </div>

                {{-- Bottom tagline --}}
                <div class="relative z-10 mt-auto p-10">
                    <h2 class="text-4xl font-bold text-white leading-tight">
                        Let's empower your<br>employees today.
                    </h2>
                    <p class="mt-3 text-zinc-400 text-sm max-w-xs">
                        We help to complete all your HR management needs easily and efficiently.
                    </p>
                </div>
            </div>

            {{-- =====================================================
                 RIGHT PANEL — Auth form
                 ===================================================== --}}
            <div class="flex items-center justify-center px-8 py-12">
                <div class="w-full max-w-sm">
                    {{-- Mobile logo --}}
                    <a href="{{ url('/') }}" class="mb-8 flex flex-col items-start gap-2 font-medium" wire:navigate>
                        <span class="flex size-8 items-center justify-center rounded-lg bg-brand-600">
                            <svg class="size-4 fill-white" viewBox="0 0 24 24"><path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/></svg>
                        </span>
                        <span class="font-bold text-zinc-900">HRDashboard</span>
                    </a>

                    {{ $slot }}

                    <p class="mt-10 text-center text-xs text-zinc-400">
                        © {{ date('Y') }} HRDashboard. All rights reserved.
                        <a href="#" class="underline hover:text-zinc-700 ml-1">Terms &amp; Conditions</a>
                        <a href="#" class="underline hover:text-zinc-700 ml-1">Privacy Policy</a>
                    </p>
                </div>
            </div>

        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
