<x-layouts::auth :title="__('Set your password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Welcome to Pulse HRMS')"
            :description="__('For your security, please create your personal password before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.first-change.update') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('New password')"
                type="password"
                required
                autofocus
                autocomplete="new-password"
                :placeholder="__('New password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm new password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm new password')"
                viewable
            />

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                <p class="mb-2 font-medium text-zinc-800 dark:text-zinc-100">{{ __('Your password must:') }}</p>
                <ul class="list-disc space-y-1 ps-5">
                    <li>{{ __('be at least 12 characters long') }}</li>
                    <li>{{ __('include upper and lower case letters') }}</li>
                    <li>{{ __('include at least one number and one symbol') }}</li>
                    <li>{{ __('not appear in a known data breach') }}</li>
                    <li>{{ __('differ from the temporary password and your recent passwords') }}</li>
                </ul>
            </div>

            <flux:button type="submit" variant="primary" class="w-full" data-test="first-password-button">
                {{ __('Save & Continue') }}
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button type="submit" variant="ghost" size="sm">{{ __('Log out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
