<?php

namespace App\Support;

/**
 * Lightweight User-Agent parser for attendance punch chips.
 *
 * Intentionally dependency-free — it recognises the common browsers, operating
 * systems and device classes well enough for display, not analytics.
 */
class UserAgent
{
    /**
     * Parse a raw User-Agent string into display fields.
     *
     * @return array{browser:string, os:string, device:string, icon:string, label:string}
     */
    public static function parse(?string $ua): array
    {
        $ua = trim((string) $ua);

        if ($ua === '') {
            return ['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'Unknown', 'icon' => 'question-mark-circle', 'label' => 'Unknown device'];
        }

        $os = self::detectOs($ua);
        $browser = self::detectBrowser($ua);
        $device = self::detectDevice($ua, $os);

        return [
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
            'icon' => match ($device) {
                'Mobile' => 'device-phone-mobile',
                'Tablet' => 'device-tablet',
                default => 'computer-desktop',
            },
            'label' => trim("{$browser} · {$os}"),
        ];
    }

    private static function detectOs(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/windows nt/i', $ua) => 'Windows',
            (bool) preg_match('/iphone|ipad|ipod/i', $ua) => 'iOS',
            (bool) preg_match('/mac os x/i', $ua) => 'macOS',
            (bool) preg_match('/android/i', $ua) => 'Android',
            (bool) preg_match('/linux/i', $ua) => 'Linux',
            (bool) preg_match('/cros/i', $ua) => 'ChromeOS',
            default => 'Unknown',
        };
    }

    private static function detectBrowser(string $ua): string
    {
        // Order matters — Edge/Opera/Brave spoof Chrome, Chrome spoofs Safari.
        return match (true) {
            (bool) preg_match('/edg(e|a|ios)?\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/samsungbrowser/i', $ua) => 'Samsung Internet',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/chrome|crios|chromium/i', $ua) => 'Chrome',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            default => 'Browser',
        };
    }

    private static function detectDevice(string $ua, string $os): string
    {
        if (preg_match('/ipad|tablet|playbook|silk/i', $ua) || (str_contains(strtolower($ua), 'android') && ! preg_match('/mobile/i', $ua))) {
            return 'Tablet';
        }

        if (preg_match('/mobi|iphone|ipod|android.*mobile|windows phone/i', $ua) || in_array($os, ['iOS', 'Android'], true)) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}
