<?php

namespace App\Livewire\Settings;

use App\Models\ProfileFieldSetting;
use App\Services\Audit\AuditService;
use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → Profile Fields: HR decides, per self-service profile field and
 * per KYC document type, whether it is Required (counts toward profile
 * completion), Optional (shown, never lowers the score) or HR-only (hidden
 * from and refused to the employee). Fields HR manages anyway (department,
 * manager, salary …) are not listed — they are always HR-only.
 */
class ProfileFieldSettings extends Component
{
    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
    }

    public function setRequirement(string $key, string $requirement): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $isKyc = str_starts_with($key, 'kyc:') && array_key_exists(substr($key, 4), ProfileFieldRegistry::KYC_TYPES);
        abort_unless($isKyc || in_array($key, ProfileFieldRegistry::selfServiceKeys(), true), 404);
        abort_unless(in_array($requirement, ProfileFieldSetting::REQUIREMENTS, true), 422);

        $before = ProfileFieldRegistry::requirement($key);

        if ($before === $requirement) {
            return;
        }

        ProfileFieldSetting::updateOrCreate(['field_key' => $key], ['requirement' => $requirement, 'updated_by' => Auth::id()]);

        app(AuditService::class)->event('PROFILE_FIELD_REQUIREMENT_CHANGED', AuditService::SETTINGS, Auth::user(),
            old: ['field' => $key, 'requirement' => $before],
            new: ['field' => $key, 'requirement' => $requirement],
            module: 'profile');

        \Flux::toast('Saved.', variant: 'success');
    }

    public function render()
    {
        $fields = collect(ProfileFieldRegistry::selfServiceKeys())->map(fn (string $key) => [
            'key' => $key,
            'label' => ProfileFieldRegistry::label($key),
            'group' => ProfileFieldRegistry::get($key)['group'] ?? 'other',
            'tier' => ProfileFieldRegistry::tier($key),
            'requirement' => ProfileFieldRegistry::requirement($key),
        ])->groupBy('group');

        $kyc = collect(ProfileFieldRegistry::KYC_TYPES)->map(fn (string $label, string $type) => [
            'key' => "kyc:{$type}",
            'label' => $label,
            'requirement' => ProfileFieldRegistry::requirement("kyc:{$type}"),
        ])->values();

        return view('livewire.settings.profile-field-settings', ['fields' => $fields, 'kyc' => $kyc])
            ->layout('layouts.app', ['title' => 'Profile Fields']);
    }
}
