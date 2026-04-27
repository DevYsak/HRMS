<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class DocumentManager extends Component
{
    use WithFileUploads, WithPagination;

    public string $filterCategory = '';

    public string $filterSearch = '';

    // Upload modal
    public bool $showUploadModal = false;

    public string $title = '';

    public string $description = '';

    public string $category = 'policy';

    public string $visibility = 'all';

    public string $expiresAt = '';

    public bool $requiresAck = false;

    public ?int $parentId = null;

    public $file;

    public ?int $departmentId = null;

    protected function rules(): array
    {
        return [
            'title' => 'required|max:255',
            'category' => 'required|string',
            'visibility' => 'required|string',
            'file' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg',
            'expiresAt' => 'nullable|date|after:today',
        ];
    }

    public function upload(): void
    {
        abort_unless(Auth::user()->isHrAdmin() || Auth::user()->isSuperAdmin(), 403);
        $this->validate();

        $path = $this->file->store('documents', 'local');
        $version = 1;

        if ($this->parentId) {
            $parent = Document::findOrFail($this->parentId);
            $version = $parent->versions()->max('version') + 1;
        }

        Document::create([
            'title' => $this->title,
            'description' => $this->description,
            'file_path' => $path,
            'file_name' => $this->file->getClientOriginalName(),
            'mime_type' => $this->file->getMimeType(),
            'file_size' => $this->file->getSize(),
            'version' => $version,
            'parent_id' => $this->parentId,
            'category' => $this->category,
            'visibility' => $this->visibility,
            'department_id' => $this->departmentId,
            'requires_acknowledgement' => $this->requiresAck,
            'expires_at' => $this->expiresAt ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset(['title', 'description', 'file', 'expiresAt', 'requiresAck', 'parentId', 'departmentId']);
        $this->showUploadModal = false;
        \Flux::toast('Document uploaded successfully.');
        $this->resetPage();
    }

    public function acknowledge(int $documentId): void
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        DocumentAcknowledgement::firstOrCreate(
            ['document_id' => $documentId, 'employee_id' => $employee->id],
            ['acknowledged_at' => now(), 'ip_address' => request()->ip()]
        );

        \Flux::toast('Document acknowledged.');
    }

    public function delete(int $documentId): void
    {
        abort_unless(Auth::user()->canManageDocuments(), 403);
        $doc = Document::findOrFail($documentId);
        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();
        \Flux::toast('Document deleted.', variant: 'warning');
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();
        $employee = $user->employee;

        $documents = Document::with(['uploader', 'department', 'versions'])
            ->when($this->filterCategory, fn ($q) => $q->where('category', $this->filterCategory))
            ->when($this->filterSearch, fn ($q) => $q->where('title', 'like', "%{$this->filterSearch}%"))
            ->whereNull('parent_id'); // show only latest/root documents

        // Role-based filtering
        if (! $user->isHrAdmin() && ! $user->isSuperAdmin()) {
            $documents->where(function ($query) use ($user, $employee) {
                // Employees see own documents and company policies
                $query->where('visibility', 'all')
                    ->orWhere('category', 'policy');

                if ($employee) {
                    $query->orWhere('employee_id', $employee->id);
                }

                // Finance can see payslips (assuming payslips are uploaded here? Wait, payslips are separate, but if they are here)
                // We'll allow finance to see category 'payslip' just in case.
                if ($user->canApproveFinance()) {
                    $query->orWhere('category', 'payslip');
                }
            });
        }

        $documents = $documents->latest()->paginate(15);

        $acknowledgedIds = $employee
            ? DocumentAcknowledgement::where('employee_id', $employee->id)
                ->pluck('document_id')
                ->all()
            : [];

        return view('livewire.documents.document-manager', compact('documents', 'acknowledgedIds'))
            ->layout('layouts.app', ['title' => 'Documents']);
    }
}
