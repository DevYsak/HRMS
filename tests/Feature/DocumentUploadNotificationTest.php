<?php

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentUploadedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('uploading a document assigned to an employee notifies that employee', function () {
    Storage::fake('local');
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employeeUser = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $employeeUser->id]);

    $this->actingAs($hrAdmin)
        ->post(route('documents.upload'), [
            'title' => 'Offer Letter',
            'category' => 'form',
            'visibility' => 'restricted',
            'employee_id' => $employee->id,
            'file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect(route('documents.index'));

    $document = Document::where('title', 'Offer Letter')->firstOrFail();

    expect($document->employee_id)->toBe($employee->id)
        ->and($document->visibility)->toBe('restricted');

    Notification::assertSentTo($employeeUser, DocumentUploadedNotification::class);
});

test('uploading a company-wide document does not notify any specific employee', function () {
    Storage::fake('local');
    Notification::fake();

    $hrAdmin = User::factory()->create(['role' => UserRole::HrAdmin]);

    $this->actingAs($hrAdmin)
        ->post(route('documents.upload'), [
            'title' => 'Company Handbook',
            'category' => 'policy',
            'visibility' => 'all',
            'file' => UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect(route('documents.index'));

    Notification::assertNothingSent();
});
