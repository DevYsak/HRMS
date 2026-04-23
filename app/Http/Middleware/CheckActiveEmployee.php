<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveEmployee
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // Check if user is an employee and is inactive
            if ($user->employee) {
                $employee = $user->employee;
                
                // If explicitly marked inactive
                if ($employee->status?->value === 'inactive') {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    return redirect()->route('login')->with('status', 'Your account is currently inactive.');
                }
                
                // If past last working day
                $exitRecord = $employee->exitRecord;
                if ($exitRecord && $exitRecord->last_working_day) {
                    $lastDay = \Carbon\Carbon::parse($exitRecord->last_working_day)->endOfDay();
                    if (now()->greaterThan($lastDay)) {
                        Auth::logout();
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
                        return redirect()->route('login')->with('status', 'Your access has been revoked following your offboarding.');
                    }
                }
            }
        }
        
        return $next($request);
    }
}
