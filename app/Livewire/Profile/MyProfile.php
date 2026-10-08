<?php

namespace App\Livewire\Profile;

use App\Livewire\Profile\Concerns\ShowsProfileSummary;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\ProfileFieldSetting;
use App\Services\Audit\AuditService;
use App\Services\Profile\ProfileChangeService;
use App\Services\Profile\ProfileFieldRegistry as Registry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL as SignedUrl;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The employee's own profile.
 *
 * Deliberately thin: it decides *nothing* about which fields are editable —
 * ProfileFieldRegistry does — and performs no writes itself, delegating every
 * mutation to ProfileChangeService so the tier rules hold no matter which
 * surface calls them.
 */
class MyProfile extends Component
{
    use ShowsProfileSummary;
    use WithFileUploads;

    #[Url(as: 'tab')]
    public string $activeTab = 'overview';

    /** Field currently open in the edit or request modal. */
    public ?string $editingField = null;

    public mixed $editingValue = null;

    public string $requestReason = '';

    public $photo;

    /** KYC upload: the proof type and the file. */
    public string $kycType = '';

    /** @var TemporaryUploadedFile|null */
    public $kycFile = null;

    /**
     * Upload a KYC document to private storage. It never replaces the
     * previous one — each upload is kept, and the latest per type is shown.
     */
    public function uploadKyc(): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        $this->validate([
            'kycType' => ['required', 'in:'.implode(',', array_keys(Registry::KYC_TYPES))],
            'kycFile' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ], [], ['kycType' => 'document type', 'kycFile' => 'file']);

        if (Registry::requirement('kyc:'.$this->kycType) === ProfileFieldSetting::HR_ONLY) {
            $this->addError('kycType', 'HR collects this document. Contact HR.');

            return;
        }

        // A random stored name: the original name is kept only as a label.
        $path = $this->kycFile->store("documents/kyc/{$this->employee->id}", 'local');

        $document = Document::create([
            'title' => Registry::KYC_TYPES[$this->kycType],
            'file_path' => $path,
            'file_name' => $this->kycFile->getClientOriginalName(),
            'mime_type' => $this->kycFile->getMimeType(),
            'file_size' => $this->kycFile->getSize(),
            'version' => 1,
            'category' => 'kyc',
            'kyc_type' => $this->kycType,
            'visibility' => 'restricted',
            'employee_id' => $this->employee->id,
            'requires_acknowledgement' => false,
            'uploaded_by' => Auth::id(),
        ]);

        app(AuditService::class)->event('PROFILE_KYC_UPLOADED', AuditService::EMPLOYEE, $document,
            new: ['kyc_type' => $this->kycType, 'file_name' => $document->file_name],
            subjectEmployeeId: $this->employee->id, module: 'profile');

        $this->reset(['kycType', 'kycFile']);
        \Flux::toast('Document uploaded.', variant: 'success');
    }

    /**
     * Each KYC type with its requirement and the latest upload, if any.
     *
     * @return array<int, array{type: string, label: string, requirement: string, document: ?Document, url: ?string}>
     */
    protected function kycItems(): array
    {
        $latest = Document::where('employee_id', $this->employee->id)->where('category', 'kyc')
            ->latest('id')->get()->unique('kyc_type')->keyBy('kyc_type');

        return collect(Registry::KYC_TYPES)
            ->map(fn (string $label, string $type) => [
                'type' => $type,
                'label' => $label,
                'requirement' => Registry::requirement("kyc:{$type}"),
                'document' => $doc = $latest->get($type),
                'url' => $doc ? SignedUrl::temporarySignedRoute('documents.view', now()->addMinutes(30), ['document' => $doc->id]) : null,
            ])
            ->reject(fn (array $item) => $item['requirement'] === ProfileFieldSetting::HR_ONLY && $item['document'] === null)
            ->values()->all();
    }

    public const TABS = [
        'overview' => 'Overview',
        'personal' => 'Personal',
        'employment' => 'Employment',
        'requests' => 'Requests',
    ];

    public function mount(): void
    {
        abort_unless($this->employee !== null, 403, 'Your account is not linked to an employee record.');
    }

    /** The signed-in user's employee record. */
    public function getEmployeeProperty(): ?Employee
    {
        return Auth::user()?->employee?->loadMissing([
            'user', 'department', 'jobTitle', 'manager', 'shift', 'office', 'employmentType', 'payrollSettings',
        ]);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->activeTab = $tab;
        }
    }

    // ── Editing ─────────────────────────────────────────────────────────────

    public function editField(string $field): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        if (! Registry::isEditable($field)) {
            \Flux::toast(Registry::label($field).' cannot be edited here.', variant: 'danger');

            return;
        }

        // Media is uploaded through the avatar control, not the text modal —
        // routing it here would present an unusable text box.
        if ((Registry::get($field)['type'] ?? null) === 'image') {
            \Flux::toast('Use the camera button on your photo to upload a new one.');

            return;
        }

        // Every field edits through the one `editingValue` input, so an error
        // left from the previous field (e.g. Aadhaar) would show under this one.
        $this->resetErrorBag();
        $this->editingField = $field;
        $this->editingValue = Registry::valueFor($this->employee, $field);
        $this->modal('edit-field')->show();
    }

    public function saveField(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        try {
            $service->updateEditable($this->employee, $this->editingField, $this->editingValue, Auth::user());
        } catch (ValidationException $e) {
            // Surface the registry's own message against the modal input.
            $this->addError('editingValue', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->closeFieldModal();
        \Flux::toast(Registry::label($this->editingField ?? '').' updated.', variant: 'success');
        $this->editingField = null;
    }

    // ── Requesting ──────────────────────────────────────────────────────────

    public function requestField(string $field): void
    {
        abort_unless(Auth::user()->hasPermission('request_profile_change'), 403);

        if (! Registry::needsApproval($field)) {
            \Flux::toast(Registry::label($field).' does not use the request flow.', variant: 'danger');

            return;
        }

        $this->resetErrorBag();
        $this->editingField = $field;
        $this->editingValue = Registry::valueFor($this->employee, $field);
        $this->requestReason = '';
        $this->modal('request-field')->show();
    }

    public function submitRequest(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('request_profile_change'), 403);

        try {
            $service->requestChange(
                $this->employee,
                $this->editingField,
                $this->editingValue,
                Auth::user(),
                trim($this->requestReason) !== '' ? trim($this->requestReason) : null,
            );
        } catch (ValidationException $e) {
            $this->addError('editingValue', collect($e->errors())->flatten()->first());

            return;
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->closeRequestModal();
        \Flux::toast('Sent to HR for review.', variant: 'success');
    }

    /** Close the request modal however it was dismissed (Cancel, X, Esc, backdrop). */
    public function closeRequestModal(): void
    {
        $this->modal('request-field')->close();
        $this->editingField = null;
        $this->requestReason = '';
        $this->resetErrorBag();
    }

    public function withdrawRequest(int $requestId, ProfileChangeService $service): void
    {
        $request = ProfileChangeRequest::findOrFail($requestId);

        try {
            $service->cancel($request, Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Request withdrawn.');
    }

    public function viewRequest(int $requestId): void
    {
        $this->activeTab = 'requests';
    }

    public function closeFieldModal(): void
    {
        $this->modal('edit-field')->close();
        $this->resetErrorBag();
    }

    // ── Photo ───────────────────────────────────────────────────────────────

    public function updatedPhoto(ProfileChangeService $service): void
    {
        abort_unless(Auth::user()->hasPermission('edit_own_profile'), 403);

        $this->validate(['photo' => Registry::rulesFor('photo')]);

        $path = $this->photo->store('employee-photos', 'public');
        $service->updateEditable($this->employee, 'photo', $path, Auth::user());

        $this->reset('photo');
        \Flux::toast('Photo updated.', variant: 'success');
    }

    // ── Data ────────────────────────────────────────────────────────────────

    /** Pending requests keyed by field, so a field row can show its own state. */
    public function getPendingRequestsProperty()
    {
        return ProfileChangeRequest::where('employee_id', $this->employee->id)
            ->pending()->get()->keyBy('field');
    }

    public function render()
    {
        return view('livewire.profile.my-profile', [
            'employee' => $this->employee,
            'completion' => $this->summaryCompletion($this->employee),
            'pending' => $this->pendingRequests,
            'kpis' => $this->summaryKpis($this->employee),
            'kyc' => $this->kycItems(),
            'canUploadKyc' => Auth::user()->hasPermission('edit_own_profile'),
            'requests' => ProfileChangeRequest::with(['reviewer', 'requestedBy'])
                ->where('employee_id', $this->employee->id)
                ->latest()->limit(20)->get(),
        ])->layout('layouts.app', ['title' => 'My Profile']);
    }
}
