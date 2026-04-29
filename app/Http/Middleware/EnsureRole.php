<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * Accepts one or more ability strings separated by commas.
     * The request passes when the authenticated user satisfies ANY of them.
     *
     * Supported abilities:
     *   manage-employees | approve-leave | approve-ot | run-payroll
     *   approve-finance  | manage-settings | manage-documents
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 403);

        foreach ($abilities as $ability) {
            if ($this->check($user, $ability)) {
                return $next($request);
            }
        }

        abort(403);
    }

    protected function check(User $user, string $ability): bool
    {
        return match ($ability) {
            'manage-employees' => $user->canManageEmployees(),
            'approve-leave' => $user->canApproveLeave(),
            'approve-ot' => $user->canApproveOt(),
            'run-payroll' => $user->canRunPayroll(),
            'approve-finance' => $user->canApproveFinance(),
            'manage-settings' => $user->canManageSettings(),
            'manage-documents' => $user->canManageDocuments(),
            default => false,
        };
    }
}
