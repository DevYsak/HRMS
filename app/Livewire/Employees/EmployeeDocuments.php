<?php

namespace App\Livewire\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Services\OnboardingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class EmployeeDocuments extends Component
{
    use WithFileUploads;

    public Employee $employee;

    public bool $showUploadModal = false;

    public string $title = '';

    public string $description = '';

    public string $category = 'other';

    public ?string $expires_at = null;

    public $file = null;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
    }

    public function openUpload(): void
    {
        $this->reset(['title', 'description', 'category', 'expires_at', 'file']);
        $this->showUploadModal = true;
        $this->dispatch('modal-show', name: 'doc-upload-modal');
    }

    public function upload(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', 'in:policy,contract,form,notice,other'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $path = $this->file->store("documents/employee/{$this->employee->id}", 'local');

        Document::create([
            'title' => $this->title,
            'description' => $this->description ?: null,
            'file_path' => $path,
            'file_name' => $this->file->getClientOriginalName(),
            'mime_type' => $this->file->getMimeType(),
            'file_size' => $this->file->getSize(),
            'category' => $this->category,
            'visibility' => 'individual',
            'employee_id' => $this->employee->id,
            'expires_at' => $this->expires_at ?: null,
            'uploaded_by' => Auth::id(),
        ]);

        app(OnboardingService::class)->autoComplete($this->employee, 'kyc_upload', Auth::id());

        $this->showUploadModal = false;
        $this->reset(['title', 'description', 'category', 'expires_at', 'file']);
        $this->dispatch('modal-close', name: 'doc-upload-modal');

        \Flux::toast('Document uploaded successfully.', variant: 'success');
    }

    public function delete(int $id): void
    {
        $doc = Document::where('employee_id', $this->employee->id)->findOrFail($id);
        Storage::disk('local')->delete($doc->file_path);
        $doc->delete();

        \Flux::toast('Document deleted.', variant: 'success');
    }

    public function render()
    {
        $documents = Document::where('employee_id', $this->employee->id)
            ->with('uploader')
            ->latest()
            ->get();

        return view('livewire.employees.employee-documents', [
            'documents' => $documents,
        ]);
    }
}
