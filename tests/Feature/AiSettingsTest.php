<?php

use App\Enums\UserRole;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\AiAssistant;
use Illuminate\Support\Facades\DB;

test('super admin can open the AI settings page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->get('/settings/ai')->assertOk()->assertSee('AI Assistant');
});

test('non super admin cannot open the AI settings page', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    $this->get('/settings/ai')->assertForbidden();
});

test('the api key is stored encrypted at rest', function () {
    $setting = AiSetting::current();
    $setting->update(['api_key' => 'sk-secret-123']);

    // Decrypts transparently through the model.
    expect(AiSetting::current()->api_key)->toBe('sk-secret-123');

    // Raw column value is not the plaintext.
    $raw = DB::table('ai_settings')->where('id', $setting->id)->value('api_key');
    expect($raw)->not->toBe('sk-secret-123');
});

test('enabled requires both the switch on and a key present', function () {
    $ai = app(AiAssistant::class);
    config(['openai.api_key' => null]);

    AiSetting::current()->update(['enabled' => false, 'api_key' => null]);
    expect($ai->enabled())->toBeFalse();

    AiSetting::current()->update(['enabled' => true, 'api_key' => null]);
    expect($ai->enabled())->toBeFalse();

    AiSetting::current()->update(['enabled' => true, 'api_key' => 'sk-test']);
    expect($ai->enabled())->toBeTrue();
});

test('enabledForUser respects the allowed roles list', function () {
    $ai = app(AiAssistant::class);
    AiSetting::current()->update([
        'enabled' => true,
        'api_key' => 'sk-test',
        'allowed_roles' => ['super_admin', 'hr_admin'],
    ]);

    $admin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    expect($ai->enabledForUser($admin))->toBeTrue();
    expect($ai->enabledForUser($employee))->toBeFalse();
});

test('provider presets resolve model and base url', function () {
    $setting = AiSetting::current();

    $setting->update(['provider' => 'gemini', 'model' => null, 'base_url' => null]);
    expect($setting->resolvedModel())->toBe('gemini-flash-lite-latest');
    expect($setting->resolvedBaseUrl())->toContain('generativelanguage.googleapis.com');

    $setting->update(['provider' => 'openai', 'model' => null, 'base_url' => null]);
    expect($setting->resolvedModel())->toBe('gpt-4o-mini');
    expect($setting->resolvedBaseUrl())->toBeNull();

    // An explicit model overrides the preset.
    $setting->update(['model' => 'gpt-4o']);
    expect($setting->resolvedModel())->toBe('gpt-4o');
});
