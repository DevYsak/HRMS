<?php

use App\Livewire\ExecutiveDashboard;
use App\Livewire\Payroll\Overview;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Six-month trend charts step back from the first of the month: on the 31st,
 * "31 Oct − 1 month" overflows to 1 Oct and the axis repeats/skips months.
 */
function tmaUser(string $slug): User
{
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
}

test('six-month trend axes never repeat or skip a month on the 31st', function () {
    $this->travelTo(Carbon::parse('2026-10-31 10:00:00'));
    $months = ['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'];

    $payroll = Livewire::actingAs(tmaUser('hr_admin'))->test(Overview::class)->viewData('payoutChart');
    expect($payroll['xaxis']['categories'])->toBe($months);

    $html = Livewire::actingAs(tmaUser('super_admin'))->test(ExecutiveDashboard::class)->html();
    // Chart options are embedded with @js (Js::from), so match its exact encoding.
    expect($html)->toContain(Str::between((string) Js::from($months), "JSON.parse('", "')"));
});
