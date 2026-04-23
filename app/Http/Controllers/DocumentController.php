<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function experienceLetter(Employee $employee)
    {
        // Only HR/Admin or the employee themselves should be able to download this
        if (auth()->user()->id !== $employee->user_id && !auth()->user()->isHrAdmin() && !auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        if (!$employee->exitRecord || !$employee->exitRecord->last_working_day) {
            abort(404, 'Experience letter not available. Exit record incomplete.');
        }

        $pdf = Pdf::loadView('pdf.experience-letter', compact('employee'));
        
        $filename = 'Experience_Letter_' . str_replace(' ', '_', $employee->user->name) . '.pdf';
        
        // Return inline view or download based on preference. Let's force download for now.
        return $pdf->download($filename);
    }

    public function download(\App\Models\Document $document)
    {
        // Path check
        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'File not found on server.');
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($document->file_path, $document->file_name);
    }
}
