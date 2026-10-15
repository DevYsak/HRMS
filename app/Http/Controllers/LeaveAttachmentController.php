<?php

namespace App\Http\Controllers;

use App\Models\LeaveAttachment;
use App\Services\Security\PeopleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A leave request's supporting document (sick notes and other private files),
 * served only to the employee who owns the request or to someone whose
 * Approve Leave / Leave Management reach covers that employee. The files sit
 * on the private disk, so this route is the only way to them.
 */
class LeaveAttachmentController extends Controller
{
    private const READERS = ['approve_leave', 'view_leave_management'];

    public function __invoke(Request $request, LeaveAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        $employee = $attachment->leaveRequest?->employee;
        abort_if($employee === null, 404);

        $people = app(PeopleScope::class);
        $mayRead = (int) $employee->user_id === (int) $user->id
            || collect(self::READERS)->contains(fn (string $permission) => $user->hasPermission($permission) && $people->covers($user, $permission, $employee));
        abort_unless($mayRead, 403);

        $disk = $attachment->diskName();
        abort_if($disk === null, 404, 'This attachment is no longer stored.');

        // A clean download name: the original file name, without path or control characters.
        $name = Str::of((string) ($attachment->original_name ?: basename($attachment->path)))
            ->replaceMatches('/[\x00-\x1F\x7F\/\\\\]+/', '')
            ->limit(150, '')
            ->toString() ?: 'attachment';

        return Storage::disk($disk)->response($attachment->path, $name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
