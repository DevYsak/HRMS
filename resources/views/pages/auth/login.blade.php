<x-layouts::auth.split :title="__('Log in')">
    <div class="flex flex-col gap-7">

        {{-- Heading --}}
        <div class="text-center">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Log in to your account</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Enter your email and password below to log in</p>
        </div>

        {{-- Session Status --}}
        <x-auth-session-status class="text-center" :status="session('status')" />

        {{-- Login Form --}}
        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            {{-- Email --}}
            <flux:field>
                <flux:label>Email Address <span class="text-red-500">*</span></flux:label>
                <flux:input
                    name="email"
                    type="email"
                    :value="old('email')"
                    required
                    autofocus
                    autocomplete="email"
                    placeholder="Input your registered email"
                    class="border-zinc-300 focus:border-orange-400 focus:ring-orange-400"
                />
                <flux:error name="email" />
            </flux:field>

            {{-- Password --}}
            <flux:field>
                <flux:label>Password <span class="text-red-500">*</span></flux:label>
                <flux:input
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="Input your password account"
                    viewable
                    class="border-zinc-300 focus:border-orange-400 focus:ring-orange-400"
                />
                <flux:error name="password" />
            </flux:field>

            {{-- Remember Me + Forgot Password --}}
            <div class="flex items-center justify-between">
                <flux:checkbox name="remember" :label="__('Remember Me')" :checked="old('remember')" />
                @if (Route::has('password.request'))
                    <flux:link class="text-sm text-zinc-500 hover:text-orange-600" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot Password') }}
                    </flux:link>
                @endif
            </div>

            {{-- Submit --}}
            <flux:button
                variant="primary"
                type="submit"
                class="w-full !bg-brand-600 hover:!bg-brand-600 !text-white font-semibold !rounded-lg !border-0"
                data-test="login-button"
            >
                {{ __('Login') }}
            </flux:button>
        </form>


    </div>
</x-layouts::auth.split>
