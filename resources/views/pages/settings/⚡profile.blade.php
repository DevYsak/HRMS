<?php

use App\Models\ProfileChangeRequest;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Account settings → Profile. Follows the profile ownership policy
 * (ProfileFieldRegistry): the full name changes only through an HR-approved
 * request, the work email is HR-managed (it is also the sign-in), and nothing
 * here writes either field directly. Phone, photo and emergency contact are
 * edited on My Profile.
 */
new #[Title('Profile settings')] class extends Component {
    public string $requestedName = '';

    public string $nameReason = '';

    /**
     * Ask HR to change the full name (Full name is an approval-tier field).
     */
    public function requestNameChange(ProfileChangeService $service): void
    {
        $user = Auth::user();
        abort_unless($user->employee && $user->hasPermission('request_profile_change'), 403);

        $this->validate([
            'requestedName' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                if (trim((string) $value) === $user->name) {
                    $fail(__('Enter the new name you want HR to approve.'));
                }
            }],
            'nameReason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'requestedName' => __('new name'),
        ]);

        try {
            $service->requestChange(
                $user->employee,
                'name',
                trim($this->requestedName),
                $user,
                trim($this->nameReason) !== '' ? trim($this->nameReason) : null,
            );
        } catch (ValidationException $e) {
            $this->addError('requestedName', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            $this->addError('requestedName', $e->getMessage());

            return;
        }

        $this->reset('requestedName', 'nameReason');
        Flux::toast(variant: 'success', text: __('Name change sent to HR for approval.'));
    }

    #[Computed]
    public function pendingNameRequest(): ?ProfileChangeRequest
    {
        $employee = Auth::user()->employee;

        return $employee
            ? ProfileChangeRequest::where('employee_id', $employee->id)->where('field', 'name')->pending()->latest()->first()
            : null;
    }

    #[Computed]
    public function canRequestName(): bool
    {
        $user = Auth::user();

        return $user->employee !== null
            && $user->hasPermission('request_profile_change')
            && Registry::needsApproval('name')
            && ! Registry::isHrOnly('name');
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(text: __('A new verification link has been sent to your email address.'));
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<x-pages::settings.layout :heading="__('Profile')" :subheading="__('Your name and sign-in email')">
    <div class="my-6 w-full space-y-6">

        {{-- Full name: HR approval --}}
        <section class="space-y-3" data-account-name>
            <div>
                <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Full name') }}</p>
                <p class="mt-1 text-base font-semibold text-zinc-900 [overflow-wrap:anywhere] dark:text-white">{{ auth()->user()->name }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ __('Changes to your name are reviewed by HR before they apply.') }}</p>
            </div>

            @if($this->pendingNameRequest)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300" data-name-request-pending>
                    {{ __('Waiting for HR:') }} <span class="font-semibold [overflow-wrap:anywhere]">“{{ $this->pendingNameRequest->new_value }}”</span>
                </div>
            @elseif($this->canRequestName)
                <form wire:submit="requestNameChange" class="space-y-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" novalidate>
                    <div>
                        <x-pulse.label for="requested-name" required>{{ __('New full name') }}</x-pulse.label>
                        <flux:input id="requested-name" wire:model="requestedName" autocomplete="name" maxlength="255" aria-required="true" />
                        <flux:error name="requestedName" />
                    </div>
                    <div>
                        <x-pulse.label for="name-reason" optional>{{ __('Reason') }}</x-pulse.label>
                        <flux:textarea id="name-reason" wire:model="nameReason" rows="2" maxlength="500" :placeholder="__('e.g. Legal name change after marriage')" />
                        <flux:error name="nameReason" />
                    </div>
                    <flux:button variant="primary" type="submit" data-test="request-name-change-button">{{ __('Send to HR') }}</flux:button>
                </form>
            @else
                <p class="text-xs text-zinc-500">{{ __('Your name is managed by your administrator.') }}</p>
            @endif
        </section>

        <flux:separator variant="subtle" />

        {{-- Work email: HR managed --}}
        <section data-account-email>
            <p class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Work email') }}</p>
            <p class="mt-1 text-base font-semibold text-zinc-900 [overflow-wrap:anywhere] dark:text-white">{{ auth()->user()->email }}</p>
            <p class="mt-1 text-xs text-zinc-500">{{ __('Managed by HR — it is also how you sign in. Ask HR if it needs to change.') }}</p>

            @if ($this->hasUnverifiedEmail)
                <flux:text class="mt-4">
                    {{ __('Your email address is unverified.') }}
                    <flux:link class="cursor-pointer text-sm" wire:click.prevent="resendVerificationNotification">
                        {{ __('Click here to re-send the verification email.') }}
                    </flux:link>
                </flux:text>
            @endif
        </section>

        @if(auth()->user()->employee)
            <p class="rounded-xl bg-zinc-50 px-3 py-2.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                {{ __('Phone, emergency contact and your profile photo are updated on') }}
                <a href="{{ route('profile.me') }}" wire:navigate class="font-semibold text-orange-600 hover:underline">{{ __('My Profile') }}</a>.
            </p>
        @endif
    </div>

    @if ($this->showDeleteUser)
        <livewire:pages::settings.delete-user-form />
    @endif
</x-pages::settings.layout>
