<?php

namespace App\Support\Activity;

/**
 * A rough reading of a browser's user-agent string: which browser, which
 * operating system, and whether it looks like a phone, a tablet or a desktop.
 *
 * Heuristic by nature. The string is whatever the browser chooses to send,
 * and modern browsers deliberately blur it, so the answer is a label for a
 * listing, not a fact to act on. No package and no external lookup: a few
 * patterns over the raw string, and null whenever nothing matches.
 */
final class UserAgentSummary
{
    /**
     * @return array{browser: ?string, platform: ?string, device_type: ?string}
     */
    public static function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return ['browser' => null, 'platform' => null, 'device_type' => null];
        }

        return [
            'browser' => self::browser($ua),
            'platform' => self::platform($ua),
            'device_type' => self::deviceType($ua),
        ];
    }

    private static function browser(string $ua): ?string
    {
        // Order matters: Edge and Opera both claim to be Chrome, and Chrome
        // claims to be Safari.
        $candidates = [
            ['Edge', '/\bEdg(?:e|A|iOS)?\/(\d+)/'],
            ['Opera', '/\b(?:OPR|Opera)\/(\d+)/'],
            ['Samsung Internet', '/\bSamsungBrowser\/(\d+)/'],
            ['Firefox', '/\b(?:Firefox|FxiOS)\/(\d+)/'],
            ['Chrome', '/\b(?:Chrome|CriOS)\/(\d+)/'],
            ['Safari', '/\bVersion\/(\d+)[\d.]*.*\bSafari\//'],
            ['Internet Explorer', '/\b(?:MSIE\s(\d+)|Trident\/.*rv:(\d+))/'],
        ];

        foreach ($candidates as [$name, $pattern]) {
            if (preg_match($pattern, $ua, $matches)) {
                $version = $matches[1] ?? ($matches[2] ?? '');

                return $version !== '' ? "{$name} {$version}" : $name;
            }
        }

        return null;
    }

    private static function platform(string $ua): ?string
    {
        // Android carries "Linux" as well, and iPadOS Safari carries "Mac OS
        // X"; the more specific token is checked first in both cases.
        return match (true) {
            (bool) preg_match('/\bWindows Phone\b/i', $ua) => 'Windows Phone',
            (bool) preg_match('/\bWindows NT\b/i', $ua) => 'Windows',
            (bool) preg_match('/\bAndroid\b/i', $ua) => 'Android',
            (bool) preg_match('/\b(?:iPhone|iPad|iPod)\b/i', $ua) => 'iOS',
            (bool) preg_match('/\bMac OS X\b/i', $ua) => 'macOS',
            (bool) preg_match('/\bCrOS\b/', $ua) => 'ChromeOS',
            (bool) preg_match('/\bLinux\b/i', $ua) => 'Linux',
            default => null,
        };
    }

    private static function deviceType(string $ua): string
    {
        if (preg_match('/\b(?:bot|crawl|spider|slurp|curl|wget|python-requests|Symfony|PostmanRuntime)\b/i', $ua)) {
            return 'bot';
        }

        if (preg_match('/\biPad\b|\bTablet\b|\bAndroid\b(?!.*\bMobile\b)/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/\bMobi|\biPhone\b|\biPod\b|\bAndroid\b|\bWindows Phone\b/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }
}
