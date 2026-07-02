<?php

use App\Support\UserAgent;

test('it detects common desktop browsers and operating systems', function (string $ua, string $browser, string $os, string $device) {
    $parsed = UserAgent::parse($ua);

    expect($parsed['browser'])->toBe($browser);
    expect($parsed['os'])->toBe($os);
    expect($parsed['device'])->toBe($device);
})->with([
    'Windows Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36', 'Chrome', 'Windows', 'Desktop'],
    'macOS Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Safari', 'macOS', 'Desktop'],
    'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0', 'Edge', 'Windows', 'Desktop'],
    'Linux Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox', 'Linux', 'Desktop'],
]);

test('it detects mobile devices', function () {
    $iphone = UserAgent::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
    expect($iphone['os'])->toBe('iOS');
    expect($iphone['device'])->toBe('Mobile');
    expect($iphone['icon'])->toBe('device-phone-mobile');

    $android = UserAgent::parse('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36');
    expect($android['os'])->toBe('Android');
    expect($android['device'])->toBe('Mobile');
    expect($android['browser'])->toBe('Chrome');
});

test('it returns a safe fallback for empty or unknown agents', function () {
    $empty = UserAgent::parse(null);
    expect($empty['browser'])->toBe('Unknown');
    expect($empty['label'])->toBe('Unknown device');

    $garbage = UserAgent::parse('curl/8.0');
    expect($garbage['device'])->toBe('Desktop');
});
