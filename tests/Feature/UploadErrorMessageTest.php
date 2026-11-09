<?php

use App\Livewire\TimeOff\RequestLeave;
use App\Models\Employee;
use App\Models\User;

/*
 * A refused upload (the web server's size limit, before the app sees the
 * file) says why, instead of the bare "failed to upload".
 */
test('a refused upload explains the size limit, and every other validation message is unchanged', function () {
    expect(__('validation.uploaded', ['attribute' => 'attachment']))
        ->toContain('The attachment could not be uploaded')
        ->toContain('larger than the server allows')
        ->and(__('validation.required', ['attribute' => 'reason']))->toBe('The reason field is required.')
        ->and(__('validation.max.file', ['attribute' => 'attachment', 'max' => 5120]))->toBe('The attachment field must not be greater than 5120 kilobytes.');
});

test('the leave drawer gives that message when an upload is refused', function () {
    $user = User::factory()->create();
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    $drawer = Livewire\Livewire::actingAs($user)->test(RequestLeave::class)
        ->call('_uploadErrored', 'attachment', null, false)
        ->assertHasErrors('attachment');

    expect($drawer->errors()->first('attachment'))->toContain('larger than the server allows');
});
