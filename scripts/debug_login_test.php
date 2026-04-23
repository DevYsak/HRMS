<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use GuzzleHttp\Client;

// Ensure debug user exists
$email = 'debug+1@example.test';
$password = 'secret123';

if (!App\Models\User::where('email', $email)->exists()) {
    $user = App\Models\User::create([
        'name' => 'Debug User',
        'email' => $email,
        'password' => Hash::make($password),
    ]);
    echo "Created debug user: $email\n";
} else {
    echo "Debug user already exists: $email\n";
}

$client = new Client([
    'base_uri' => 'http://127.0.0.1:8000',
    'cookies' => true,
    'http_errors' => false,
    'timeout' => 20,
]);

// Fetch the login page to get CSRF token and initial cookies
$res = $client->get('/login');
$body = (string) $res->getBody();

if (!preg_match('/name="_token"\s+value="([^"]+)"/', $body, $m)) {
    echo "CSRF token not found in login page.\n";
    exit(1);
}
$token = $m[1];

echo "CSRF token: $token\n";

// Submit login POST
$loginRes = $client->post('/login', [
    'form_params' => [
        '_token' => $token,
        'email' => $email,
        'password' => $password,
        'remember' => 'on',
    ],
    'allow_redirects' => false,
]);

echo "Login response status: " . $loginRes->getStatusCode() . "\n";

// Print Set-Cookie headers
$cookies = $loginRes->getHeader('Set-Cookie');
if (count($cookies) === 0) {
    echo "No Set-Cookie headers returned.\n";
} else {
    echo "Set-Cookie headers:\n";
    foreach ($cookies as $c) {
        echo "- $c\n";
    }
}

// Print Location header if redirect
$location = $loginRes->getHeaderLine('Location');
if ($location) {
    echo "Redirect Location: $location\n";
}

// Now follow redirect (if any) to inspect final response and cookies
if ($loginRes->getStatusCode() >= 300 && $loginRes->getStatusCode() < 400 && $location) {
    $follow = $client->get($location, ['allow_redirects' => false]);
    echo "Follow response status: " . $follow->getStatusCode() . "\n";
    $followCookies = $follow->getHeader('Set-Cookie');
    if (count($followCookies)) {
        echo "Follow Set-Cookie headers:\n";
        foreach ($followCookies as $c) echo "- $c\n";
    }
}

// Check sessions table for session entries
try {
    $count = \DB::table('sessions')->count();
    echo "Sessions table count: $count\n";
} catch (Exception $e) {
    echo "Could not read sessions table: " . $e->getMessage() . "\n";
}

// Check current session id in cookie jar
$jar = $client->getConfig('cookies');
if ($jar instanceof \GuzzleHttp\Cookie\CookieJarInterface) {
    $all = $jar->toArray();
    echo "Cookie jar contents:\n";
    foreach ($all as $ck) {
        echo "- {$ck['Name']}={$ck['Value']}; Domain={$ck['Domain']}; Path={$ck['Path']}; Secure=" . ($ck['Secure'] ? 'true' : 'false') . "; HttpOnly=" . ($ck['HttpOnly'] ? 'true' : 'false') . "; SameSite=" . ($ck['SameSite'] ?? 'none') . "\n";
    }
}

echo "Done.\n";
