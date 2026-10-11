<?php

use App\Livewire\Profile\MyProfile;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ProfileFieldSetting;
use App\Models\User;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** A real 1×1 PNG, so the image/mimes rules (fileinfo, not GD) see a genuine image. */
function tinyPng(string $name = 'me.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
    );
}

function photoEmployee(): Employee
{
    $user = User::factory()->create(['role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('the ownership matrix matches policy: direct, HR approval and HR managed', function () {
    foreach (['photo', 'phone', 'emergency_contact'] as $key) {
        expect(Registry::tier($key))->toBe(Registry::TIER_EDITABLE, $key);
    }
    foreach (['name', 'date_of_birth', 'gender', 'address', 'bank_name', 'account_number', 'ifsc_code', 'pan_number', 'aadhar_number'] as $key) {
        expect(Registry::tier($key))->toBe(Registry::TIER_APPROVAL, $key);
    }
    foreach (['employee_id', 'employee_code', 'email', 'department_id', 'job_title_id', 'manager_id', 'office_id', 'shift_id', 'employment_type_id', 'joining_date', 'status', 'ctc'] as $key) {
        expect(Registry::tier($key))->toBe(Registry::TIER_LOCKED, $key);
    }
});

test('an employee uploads their own photo directly, audited, and the old file is removed', function () {
    Storage::fake('public');
    $employee = photoEmployee();
    Storage::disk('public')->put('employee-photos/old.png', 'old');
    $employee->update(['photo' => 'employee-photos/old.png']);

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->set('photo', tinyPng())
        ->assertHasNoErrors();

    $employee->refresh();
    expect($employee->photo)->toStartWith('employee-photos/')
        ->not->toBe('employee-photos/old.png');
    Storage::disk('public')->assertExists($employee->photo);
    Storage::disk('public')->assertMissing('employee-photos/old.png');

    expect(AuditLog::where('action', 'profile_updated')->where('subject_employee_id', $employee->id)->exists())->toBeTrue();
});

test('a photo in another format is refused with a clear message and nothing is stored', function () {
    Storage::fake('public');
    $employee = photoEmployee();

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->set('photo', UploadedFile::fake()->create('me.gif', 10, 'image/gif'))
        ->assertHasErrors('photo');

    expect($employee->fresh()->photo)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('when HR manages the photo the camera is hidden and an upload is refused without storing a file', function () {
    Storage::fake('public');
    ProfileFieldSetting::updateOrCreate(['field_key' => 'photo'], ['requirement' => ProfileFieldSetting::HR_ONLY]);
    $employee = photoEmployee();

    $page = Livewire::actingAs($employee->user)->test(MyProfile::class);
    expect($page->instance()->canEditPhoto())->toBeFalse();

    $page->set('photo', tinyPng())
        ->assertHasErrors('photo')
        ->assertSee('Your profile photo is managed by HR.');

    expect($employee->fresh()->photo)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBe([]);
});

test('the overview shows identity, contact, employment and completeness — not bank or pay', function () {
    $employee = photoEmployee();

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->assertSee('Profile completeness')
        ->assertSee('Department')
        ->assertSee('Phone')
        ->assertDontSee('Account number')
        ->assertDontSee('Annual CTC');
});
