<?php

use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A Sick Leave request needs a supporting document. The file the employee
 * picks must be the one the server validates — the uploaded Livewire file,
 * never a filename shown by the browser.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(LeaveTypeSeeder::class);

    $user = User::factory()->create(['role' => 'employee']);
    $this->employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08']);
    $this->sick = LeaveType::where('code', 'SL')->firstOrFail();
});

/** A real (1×1) JPEG, so the type check reads genuine image bytes — no GD needed. */
function lauJpeg(string $name = 'IMG_20261015_131904.jpg', int $padKb = 0): UploadedFile
{
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

    return UploadedFile::fake()->createWithContent($name, $jpeg.str_repeat("\0", $padKb * 1024));
}

function lauPdf(string $name = 'fit-note.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}

/** The leave form filled in for a Sick Leave day next week, unpaid (no balance needed). */
function lauSickForm($test)
{
    return Livewire::actingAs($test->employee->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->set('leave_type_id', (string) $test->sick->id)
        ->set('start_date', '2026-10-20')
        ->set('end_date', '2026-10-20')
        ->set('requested_leave_status', $test->sick->allow_unpaid_request ? 'unpaid' : 'paid')
        ->set('reason', 'Fever and doctor visit');
}

test('Sick Leave with a valid JPG is submitted', function () {
    lauSickForm($this)
        ->set('attachment', lauJpeg())
        ->call('submitRequest')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $this->employee->id)->latest('id')->firstOrFail();
    expect($request->attachments)->toHaveCount(1)
        ->and($request->attachments->first()->original_name)->toBe('IMG_20261015_131904.jpg')
        ->and($request->attachments->first()->mime_type)->toBe('image/jpeg')
        ->and($request->attachments->first()->size)->toBeGreaterThan(0);
});

test('Sick Leave without an attachment is refused with a clear message', function () {
    lauSickForm($this)
        ->call('submitRequest')
        ->assertHasErrors(['attachment' => 'required'])
        ->assertSee('An attachment is required for Sick Leave.');

    expect(LeaveRequest::where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

test('Sick Leave with a valid PDF is submitted', function () {
    lauSickForm($this)
        ->set('attachment', lauPdf())
        ->call('submitRequest')
        ->assertHasNoErrors();

    expect(LeaveRequest::where('employee_id', $this->employee->id)->first()->attachments->first()->original_name)->toBe('fit-note.pdf');
});

test('an oversized file is refused as soon as it is chosen', function () {
    lauSickForm($this)
        ->set('attachment', lauJpeg('huge.jpg', padKb: 6000))
        ->assertHasErrors('attachment')
        ->assertSee('Attachment must be 5 MB or smaller.')
        ->call('submitRequest')
        ->assertHasErrors('attachment');

    expect(LeaveRequest::where('employee_id', $this->employee->id)->exists())->toBeFalse();
});

test('a file of the wrong type is refused', function () {
    lauSickForm($this)
        ->set('attachment', UploadedFile::fake()->createWithContent('notes.txt', 'plain text, not a document'))
        ->assertHasErrors('attachment')
        ->assertSee('Please upload a PDF, JPG, JPEG or PNG.');
});

test('the "attachment is required" error clears as soon as a valid file is chosen', function () {
    lauSickForm($this)
        ->call('submitRequest')
        ->assertHasErrors('attachment')
        ->set('attachment', lauJpeg())
        ->assertHasNoErrors('attachment')
        ->assertDontSee('An attachment is required')
        ->assertSee('Uploaded ✓');
});

test('the chosen file survives other changes to the form', function () {
    lauSickForm($this)
        ->set('attachment', lauJpeg())
        // Re-renders from unrelated fields and the leave assistant.
        ->set('is_half_day', true)
        ->set('is_half_day', false)
        ->set('employee_remarks', 'Doctor note attached')
        ->set('end_date', '2026-10-21')
        ->set('end_date', '2026-10-20')
        ->assertSet('attachment', fn ($file) => $file !== null && $file->getClientOriginalName() === 'IMG_20261015_131904.jpg')
        ->call('submitRequest')
        ->assertHasNoErrors();
});

test('the employee can remove the chosen file', function () {
    lauSickForm($this)
        ->set('attachment', lauJpeg())
        ->call('removeAttachment')
        ->assertSet('attachment', null)
        ->call('submitRequest')
        ->assertHasErrors(['attachment' => 'required']);
});

test('a leave type that needs no document is unchanged', function () {
    $type = LeaveType::where('attachment_required', false)->where('allow_unpaid_request', true)->firstOrFail();

    Livewire::actingAs($this->employee->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->set('leave_type_id', (string) $type->id)
        ->set('start_date', '2026-10-20')
        ->set('end_date', '2026-10-20')
        ->set('requested_leave_status', 'unpaid')
        ->set('reason', 'Family errand')
        ->call('submitRequest')
        ->assertHasNoErrors('attachment');
});

test('the document is stored privately and served only to people who may see it', function () {
    lauSickForm($this)->set('attachment', lauJpeg())->call('submitRequest')->assertHasNoErrors();
    $attachment = LeaveRequest::where('employee_id', $this->employee->id)->first()->attachments->first();

    // Private disk only — nothing under public/storage.
    expect(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($attachment->path))->toBeFalse()
        ->and($attachment->url())->toBe(route('leave-attachments.show', $attachment));

    // The owner.
    $this->actingAs($this->employee->user)->get($attachment->url())
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');

    // HR (company-wide leave reach).
    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id]);
    $this->actingAs($hr)->get($attachment->url())->assertOk();

    // Another employee.
    $stranger = Employee::factory()->create(['user_id' => User::factory()->create(['role' => 'employee'])->id, 'status' => 'active']);
    $this->actingAs($stranger->user)->get($attachment->url())->assertForbidden();

    // Signed out.
    auth()->logout();
    $this->get($attachment->url())->assertRedirect();
});

test('an older document on the public disk is still served through the route', function () {
    lauSickForm($this)->set('attachment', lauJpeg())->call('submitRequest')->assertHasNoErrors();
    $attachment = LeaveRequest::where('employee_id', $this->employee->id)->first()->attachments->first();
    Storage::disk('public')->put($attachment->path, Storage::disk('local')->get($attachment->path));
    Storage::disk('local')->delete($attachment->path);

    expect($attachment->diskName())->toBe('public');
    $this->actingAs($this->employee->user)->get($attachment->url())->assertOk();
});
