<?php

use App\Http\Middleware\EnsureTeamMembership;
use App\Livewire\Holidays\HolidayPaySettings;
use App\Livewire\Holidays\ManageHolidays;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

// See routes/web.php — 'verified' is inert here for the same reason.
Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');
    Route::livewire('settings/preferences', 'pages::settings.preferences')->name('settings.preferences');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('security.edit');

    Route::livewire('settings/teams', 'pages::teams.index')->name('teams.index');
    // Holiday Management (extends the former thin date+name page at the same URL/name).
    // The components abort without manage_settings too; the route guard lets
    // RouteAccess (settings nav, help links) see that before rendering a link.
    Route::get('settings/holidays', ManageHolidays::class)->middleware('role:manage-settings')->name('settings.holidays');
    Route::get('settings/holiday-pay', HolidayPaySettings::class)->middleware('role:manage-settings')->name('settings.holiday-pay');
    Route::livewire('settings/ai', 'pages::settings.ai')->name('settings.ai');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::livewire('settings/teams/{team}', 'pages::teams.edit')->name('teams.edit');
    });
});
