<?php

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DocumentExpiryNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Spec v3.1 §3.9 — re-uploading creates the next version (prior versions
 * kept), and HR hears about an expiring document once, 30 days ahead.
 */
function docHr(): User
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => 'active', 'manager_id' => null]);

    return $hr->fresh();
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
});

test('re-uploading a document creates v2, then v3, and keeps the earlier versions', function () {
    $hr = docHr();
    $upload = fn (array $extra = []) => $this->actingAs($hr)->post(route('documents.upload'), array_merge([
        'title' => 'Employee handbook', 'category' => 'policy', 'visibility' => 'all',
        'file' => UploadedFile::fake()->create('handbook.pdf', 20, 'application/pdf'),
    ], $extra));

    $upload();
    $original = Document::firstOrFail();
    $upload(['parent_id' => $original->id]);
    $upload(['parent_id' => $original->id]);

    expect(Document::where('parent_id', $original->id)->orderBy('id')->pluck('version')->all())->toBe([2, 3])
        ->and(Document::count())->toBe(3);
});

test('an expiring document is announced to HR once, and again only if its expiry date changes', function () {
    $hr = docHr();
    Storage::disk('local')->put('documents/visa.pdf', '%PDF');
    $document = Document::create([
        'title' => 'Work visa', 'file_path' => 'documents/visa.pdf', 'file_name' => 'visa.pdf', 'mime_type' => 'application/pdf',
        'category' => 'contract', 'visibility' => 'restricted', 'expires_at' => now()->addDays(20)->toDateString(), 'uploaded_by' => $hr->id,
    ]);

    $this->artisan('hrms:check-document-expiry')->assertSuccessful();
    $this->artisan('hrms:check-document-expiry')->assertSuccessful();
    Notification::assertSentToTimes($hr, DocumentExpiryNotification::class, 1);

    $document->update(['expires_at' => now()->addDays(25)->toDateString()]);
    $this->artisan('hrms:check-document-expiry')->assertSuccessful();
    Notification::assertSentToTimes($hr, DocumentExpiryNotification::class, 2);
});
