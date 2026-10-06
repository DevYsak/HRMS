<?php

use App\Enums\UserRole;
use App\Livewire\Profile\MyProfile;
use App\Livewire\Settings\ProfileFieldSettings;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ProfileFieldSetting;
use App\Models\User;
use App\Services\EmployeeDashboardService;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileCompletionService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Profile completion HR configures (Required / Optional / HR-only per field
 * and per KYC document), and KYC documents in private, permission-protected
 * storage.
 */
beforeEach(fn () => Storage::fake('local'));

function pckEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

function pckSet(string $key, string $requirement): void
{
    ProfileFieldSetting::updateOrCreate(['field_key' => $key], ['requirement' => $requirement]);
}

test('India-only identifiers are optional by default, so a profile can reach 100%', function () {
    expect(Registry::requirement('pan_number'))->toBe(ProfileFieldSetting::OPTIONAL)
        ->and(Registry::requirement('aadhar_number'))->toBe(ProfileFieldSetting::OPTIONAL)
        ->and(Registry::requirement('ifsc_code'))->toBe(ProfileFieldSetting::OPTIONAL)
        ->and(Registry::requirement('phone'))->toBe(ProfileFieldSetting::REQUIRED)
        ->and(Registry::requirement('department_id'))->toBe(ProfileFieldSetting::HR_ONLY);

    $completion = app(ProfileCompletionService::class)->for(pckEmployee());
    expect(collect($completion['missing'])->pluck('field'))->not->toContain('pan_number')
        ->and(collect($completion['optional_missing'])->pluck('field'))->toContain('pan_number');
});

test('HR making a field required adds it to the score; HR-only removes it', function () {
    $employee = pckEmployee();
    $service = app(ProfileCompletionService::class);
    $before = $service->for($employee)['total'];

    pckSet('pan_number', ProfileFieldSetting::REQUIRED);
    expect($service->for($employee)['total'])->toBe($before + 1);

    pckSet('phone', ProfileFieldSetting::HR_ONLY);
    expect(collect($service->for($employee)['missing'])->pluck('field'))->not->toContain('phone')
        ->and($service->for($employee)['total'])->toBe($before);
});

test('an HR-only field cannot be changed or requested by the employee', function () {
    $employee = pckEmployee();
    pckSet('phone', ProfileFieldSetting::HR_ONLY);
    pckSet('address', ProfileFieldSetting::HR_ONLY);
    $service = app(ProfileChangeService::class);

    expect(fn () => $service->updateEditable($employee, 'phone', '+44 7700 900123', $employee->user))->toThrow(DomainException::class, 'managed by HR')
        ->and(fn () => $service->requestChange($employee, 'address', '1 New Street', $employee->user, 'Moved'))->toThrow(DomainException::class, 'managed by HR');
});

test('a field waiting for HR approval counts as submitted, not missing', function () {
    $employee = pckEmployee();
    $employee->update(['address' => null]);
    app(ProfileChangeService::class)->requestChange($employee->fresh(), 'address', '10 Downing Street', $employee->user, 'Moved house');

    $completion = app(ProfileCompletionService::class)->for($employee->fresh());

    expect(collect($completion['missing'])->pluck('field'))->not->toContain('address')
        ->and(collect($completion['submitted'])->pluck('field'))->toContain('address');
});

test('a required KYC document is missing until the employee uploads it', function () {
    $employee = pckEmployee();
    pckSet('kyc:identity_proof', ProfileFieldSetting::REQUIRED);

    expect(collect(app(ProfileCompletionService::class)->for($employee)['missing'])->pluck('field'))->toContain('kyc:identity_proof');

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->set('activeTab', 'personal')
        ->assertSee('KYC documents')
        ->set('kycType', 'identity_proof')
        ->set('kycFile', UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf'))
        ->call('uploadKyc')
        ->assertHasNoErrors();

    $document = Document::where('employee_id', $employee->id)->where('category', 'kyc')->firstOrFail();
    expect($document->kyc_type)->toBe('identity_proof')
        ->and($document->visibility)->toBe('restricted')
        ->and($document->file_path)->toStartWith("documents/kyc/{$employee->id}/")
        ->and($document->file_path)->not->toContain('passport')
        ->and(collect(app(ProfileCompletionService::class)->for($employee)['missing'])->pluck('field'))->not->toContain('kyc:identity_proof')
        ->and(AuditLog::where('event', 'PROFILE_KYC_UPLOADED')->exists())->toBeTrue();
    Storage::disk('local')->assertExists($document->file_path);
});

test('a KYC document opens for its owner and KYC viewers only', function () {
    $owner = pckEmployee();
    $document = Document::create([
        'title' => 'Identity proof', 'file_path' => 'documents/kyc/x.pdf', 'file_name' => 'id.pdf', 'version' => 1,
        'category' => 'kyc', 'kyc_type' => 'identity_proof', 'visibility' => 'restricted', 'employee_id' => $owner->id,
        'requires_acknowledgement' => false, 'uploaded_by' => $owner->user_id,
    ]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $documentsOnly = User::factory()->create(['role' => UserRole::Manager]);
    $colleague = pckEmployee()->user;

    expect($owner->user->can('view', $document))->toBeTrue()
        ->and($hr->can('view', $document))->toBeTrue()
        ->and($documentsOnly->can('view', $document))->toBeFalse()
        ->and($colleague->can('view', $document))->toBeFalse();
});

test('HR sets requirements on the Profile Fields page; employees cannot', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(ProfileFieldSettings::class)
        ->assertSee('Profile Fields')
        ->assertSee('KYC documents')
        ->call('setRequirement', 'pan_number', ProfileFieldSetting::REQUIRED)
        ->call('setRequirement', 'kyc:bank_proof', ProfileFieldSetting::REQUIRED);

    expect(Registry::requirement('pan_number'))->toBe(ProfileFieldSetting::REQUIRED)
        ->and(Registry::requirement('kyc:bank_proof'))->toBe(ProfileFieldSetting::REQUIRED)
        ->and(AuditLog::where('event', 'PROFILE_FIELD_REQUIREMENT_CHANGED')->count())->toBe(2);

    // A locked field is not configurable.
    Livewire::actingAs($hr)->test(ProfileFieldSettings::class)
        ->call('setRequirement', 'department_id', ProfileFieldSetting::REQUIRED)
        ->assertNotFound();

    Livewire::actingAs(pckEmployee()->user)->test(ProfileFieldSettings::class)->assertForbidden();
});

test('the dashboard prompt shows the percentage and opens the right tab', function () {
    $employee = pckEmployee();
    $employee->update(['phone' => null]);

    $alert = collect(app(EmployeeDashboardService::class)->build($employee->user->fresh())['alerts'])->firstWhere('title', 'Complete your profile');

    expect($alert)->not->toBeNull()
        ->and($alert['url'])->toBe(route('profile.me', ['tab' => 'personal']))
        ->and($alert['progress'])->toBeLessThan(100);
});
