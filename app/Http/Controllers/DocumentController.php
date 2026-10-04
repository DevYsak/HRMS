<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function experienceLetter(Employee $employee)
    {
        // Only HR/Admin or the employee themselves should be able to download this
        if (auth()->user()->id !== $employee->user_id && ! auth()->user()->isHrAdmin() && ! auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        if (! $employee->exitRecord || ! $employee->exitRecord->last_working_day) {
            abort(404, 'Experience letter not available. Exit record incomplete.');
        }

        $pdf = Pdf::loadView('pdf.experience-letter', compact('employee'));

        $filename = 'Experience_Letter_'.str_replace(' ', '_', $employee->user->name).'.pdf';

        // Return inline view or download based on preference. Let's force download for now.
        return $pdf->download($filename);
    }

    /** Types a browser may render in a tab without running anything. */
    private const INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function download(Document $document)
    {
        Gate::authorize('view', $document);

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File not found on server.');
        }

        return Storage::disk('local')->download(
            $document->file_path,
            $document->file_name,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Serve file inline — PDF/image opens in browser tab. Anything else
     * (HTML, SVG, office files) is sent as an attachment with a neutral type,
     * so an uploaded file can never run as a page on this origin.
     */
    public function view(Document $document)
    {
        Gate::authorize('view', $document);

        $storage = Storage::disk('local');

        if (! $storage->exists($document->file_path)) {
            abort(404, 'File not found on server.');
        }

        $mime = $document->mime_type ?? $storage->mimeType($document->file_path);
        $inline = in_array($mime, self::INLINE_TYPES, true);
        $filename = str_replace(['"', "\r", "\n"], '', (string) $document->file_name);

        return response($storage->get($document->file_path), 200, [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
