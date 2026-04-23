<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/', 'GET');
// We need to login as user 4
auth()->loginUsingId(4);
$response = $kernel->handle($request);
echo "Response status: " . $response->status() . "\n";
echo "Response content length: " . strlen($response->getContent()) . "\n";
