<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white antialiased">
    <div class="grid min-h-screen lg:grid-cols-2">

        {{-- =====================================================
        LEFT PANEL — Conexus Orange Gradient + Pulse Branding
        Matches the Conexus portal design exactly.
        ===================================================== --}}
        <div class="relative hidden lg:flex flex-col" style="background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);">

            {{-- Subtle geometric overlay --}}
            <div class="absolute inset-0 overflow-hidden">
                <div class="absolute -top-24 -right-24 w-96 h-96 rounded-full opacity-10" style="background: rgba(255,255,255,0.3)"></div>
                <div class="absolute -bottom-16 -left-16 w-72 h-72 rounded-full opacity-10" style="background: rgba(255,255,255,0.2)"></div>
            </div>

            {{-- Logo top-left --}}
            <div class="relative z-10 p-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                    <span class="flex size-10 items-center justify-center rounded-xl bg-white/20 backdrop-blur-sm border border-white/30">
                        <svg class="size-5 fill-white" viewBox="0 0 24 24">
                            <path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z" />
                        </svg>
                    </span>
                    <div>
                        <span class="text-xl font-bold text-white tracking-tight">Pulse</span>
                        <span class="ml-1 text-sm text-orange-100 font-normal">by Conexus</span>
                    </div>
                </a>
            </div>

            {{-- Main tagline --}}
            <div class="relative z-10 flex flex-col justify-center flex-1 px-10">
                <h2 class="text-4xl font-bold text-white leading-tight">
                    Manage your workforce<br>
                    with clarity &amp;<br>
                    confidence.
                </h2>
                <p class="mt-4 text-orange-100 text-base max-w-sm leading-relaxed">
                    Track attendance, manage leave, run payroll and review performance — all in one place.
                </p>

                {{-- Feature pills --}}
                <div class="mt-8 flex flex-wrap gap-2">
                    @foreach(['Attendance Tracking', 'Leave Management', 'Payroll & Payslips', 'Performance Reviews'] as $pill)
                        <span class="inline-flex items-center rounded-full border border-white/40 bg-white/15 backdrop-blur-sm px-4 py-1.5 text-sm font-medium text-white">
                            {{ $pill }}
                        </span>
                    @endforeach
                </div>
            </div>

            {{-- Footer --}}
            <div class="relative z-10 p-8">
                <p class="text-xs text-orange-200">© {{ date('Y') }} Conexus. All rights reserved.</p>
            </div>
        </div>

        {{-- =====================================================
        RIGHT PANEL — Auth form (clean white)
        ===================================================== --}}
        <div class="flex items-center justify-center px-8 py-12 bg-gray-50">
            <div class="w-full max-w-sm">
                {{-- Mobile logo (shown only on small screens) --}}
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-orange-500">
                        <svg class="size-5 fill-white" viewBox="0 0 24 24">
                            <path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z" />
                        </svg>
                    </span>
                    <span class="text-lg font-bold text-zinc-900">Pulse <span class="text-zinc-400 font-normal text-sm">by Conexus</span></span>
                </div>

                {{ $slot }}

                <p class="mt-10 text-center text-xs text-zinc-400">
                    © {{ date('Y') }} Conexus. All rights reserved.
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
