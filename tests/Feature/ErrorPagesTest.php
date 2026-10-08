<?php

use Illuminate\Support\Facades\Route;

/**
 * Error pages are friendly, branded and never leak internals. A server error
 * shows a reference that matches the X-Request-Id header and the log context.
 */
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/__test/boom', fn () => throw new RuntimeException('SQLSTATE secret-db-password at /var/www/app/Secret.php'));
        Route::get('/__test/forbidden', fn () => abort(403));
        Route::get('/__test/forbidden-reason', fn () => abort(403, 'Your account is not linked to an employee record.'));
        Route::get('/__test/expired', fn () => abort(419));
        Route::get('/__test/unprocessable', fn () => abort(422));
    });
});

test('every response carries a request id', function () {
    $response = $this->get('/__test/forbidden');

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[A-Za-z0-9._-]{8,64}$/');
});

test('a sane incoming request id is kept, a malformed one replaced', function () {
    $this->get('/__test/forbidden', ['X-Request-Id' => 'proxy-abc-12345'])
        ->assertHeader('X-Request-Id', 'proxy-abc-12345');

    $replaced = $this->get('/__test/forbidden', ['X-Request-Id' => '<script>alert(1)</script>'])
        ->headers->get('X-Request-Id');

    expect($replaced)->not->toContain('<');
});

test('a server error shows a friendly page with its reference and no internals', function () {
    $response = $this->get('/__test/boom');
    $id = $response->headers->get('X-Request-Id');

    $response->assertStatus(500)
        ->assertSee('Something went wrong')
        ->assertSee($id)
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('secret-db-password')
        ->assertDontSee('Secret.php')
        ->assertDontSee('RuntimeException');
});

test('the 403 page explains in plain words and keeps the app\'s own reason', function () {
    $this->get('/__test/forbidden')
        ->assertForbidden()
        ->assertSee("You don't have access")
        ->assertSee('ask HR or your administrator')
        ->assertDontSee('This action is unauthorized');

    $this->get('/__test/forbidden-reason')
        ->assertForbidden()
        ->assertSee('Your account is not linked to an employee record.');
});

test('the 404 page is friendly', function () {
    $this->get('/this-page-does-not-exist-anywhere')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Go to sign in');
});

test('an expired session says so and offers to sign in again', function () {
    $this->get('/__test/expired')
        ->assertStatus(419)
        ->assertSee('Your session expired')
        ->assertSee('Sign in again');
});

test('a 422 has its own friendly page', function () {
    $this->get('/__test/unprocessable')
        ->assertStatus(422)
        ->assertSee("We couldn't process that");
});
