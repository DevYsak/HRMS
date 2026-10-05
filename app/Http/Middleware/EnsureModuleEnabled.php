<?php

namespace App\Http\Middleware;

use App\Services\ModuleFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:payroll` / `module:payslips` — refuse a route whose module is
 * switched off, whatever the user's permissions. Registered as persistent
 * Livewire middleware too, so actions on a page loaded before the switch was
 * turned off are refused as well.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $features = app(ModuleFeatureService::class);

        match ($module) {
            'payroll' => $features->assertPayrollEnabled(),
            'payslips' => $features->assertPayslipsEnabled(),
            default => abort(500, "Unknown module '{$module}'."),
        };

        return $next($request);
    }
}
