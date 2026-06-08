<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Notifications\DocumentUploadedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentUploadController extends Controller
{
    /** HR / Admin — upload company or employee document */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()->canManageDocuments(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', 'in:policy,contract,personal,payslip,form,notice,other'],
            'visibility' => ['required', 'in:all,restricted,department,individual'],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'],
            'expires_at' => ['nullable', 'date'],
            'requires_acknowledgement' => ['boolean'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'parent_id' => ['nullable', 'exists:documents,id'],
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $path = $file->storeAs('documents', time().'_'.$originalName, 'local');

        $version = 1;
        if (! empty($data['parent_id'])) {
            $parent = Document::findOrFail($data['parent_id']);
            $version = ((int) $parent->versions()->max('version') ?? 0) + 1;
        }

        $document = Document::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $path,
            'file_name' => $originalName,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'version' => $version,
            'parent_id' => $data['parent_id'] ?? null,
            'category' => $data['category'],
            'visibility' => ! empty($data['employee_id']) ? 'restricted' : $data['visibility'],
            'employee_id' => $data['employee_id'] ?? null,
            'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'expires_at' => $data['expires_at'] ?? null,
            'uploaded_by' => Auth::id(),
        ]);

        if (! empty($data['employee_id'])) {
            $document->loadMissing('employee.user')->employee?->user?->notify(new DocumentUploadedNotification($document));
        }

        return redirect()->route('documents.index')
            ->with('success', 'Document uploaded successfully.');
    }

    /** Employee — upload personal document */
    public function storePersonal(Request $request): RedirectResponse
    {
        $employee = Auth::user()->employee;
        abort_unless($employee !== null, 403);

        $data = $request->validate([
            'personal_title' => ['required', 'string', 'max:255'],
            'personal_description' => ['nullable', 'string', 'max:1000'],
            'personal_file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg'],
        ]);

        $file = $request->file('personal_file');
        $originalName = $file->getClientOriginalName();
        $path = $file->storeAs('documents/personal', time().'_'.$originalName, 'local');

        Document::create([
            'title' => $data['personal_title'],
            'description' => $data['personal_description'] ?? null,
            'file_path' => $path,
            'file_name' => $originalName,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'version' => 1,
            'category' => 'personal',
            'visibility' => 'restricted',
            'employee_id' => $employee->id,
            'requires_acknowledgement' => false,
            'uploaded_by' => Auth::id(),
        ]);

        return redirect()->route('documents.index')
            ->with('success', 'Personal document uploaded successfully.');
    }
}
