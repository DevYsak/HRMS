<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title') - {{ config('app.name', 'Pulse') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col items-center gap-6 text-center">
                <a href="{{ url('/') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-9 w-9 mb-1 items-center justify-center rounded-md">
                        <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                    </span>
                    <span class="sr-only">{{ config('app.name', 'Pulse') }}</span>
                </a>

                <div class="flex w-full flex-col items-center gap-4 rounded-2xl border border-zinc-100 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="rounded-full bg-violet-50 p-3 dark:bg-violet-900/20">
                        <flux:icon :name="trim($__env->yieldContent('icon', 'face-frown'))" class="size-8 text-violet-600 dark:text-violet-400" />
                    </div>

                    <div class="flex flex-col gap-1">
                        <h1 class="text-sm font-black uppercase tracking-[0.2em] text-violet-600 dark:text-violet-400">
                            @yield('code') @yield('title')
                        </h1>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            @yield('message')
                        </p>
                    </div>

                    <div class="mt-2 flex w-full flex-col gap-2 sm:flex-row sm:justify-center">
                        @auth
                            <flux:button href="{{ route('dashboard') }}" variant="primary" wire:navigate class="w-full sm:w-auto">
                                Back to dashboard
                            </flux:button>
                        @else
                            <flux:button href="{{ route('login') }}" variant="primary" wire:navigate class="w-full sm:w-auto">
                                Go to login
                            </flux:button>
                        @endauth
                    </div>
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
