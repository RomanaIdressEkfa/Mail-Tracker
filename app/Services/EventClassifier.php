<?php

namespace App\Services;

/**
 * Works out which mail client fetched a pixel or link, and whether the hit was made by a machine
 * (corporate security scanners, link previewers) rather than a person.
 */
class EventClassifier
{
    /** User-agent fragments of scanners and previewers that fetch links/images without a human. */
    private const AUTOMATIC_AGENTS = [
        'barracuda', 'mimecast', 'proofpoint', 'symantec', 'messagelabs', 'forcepoint', 'trendmicro',
        'ironport', 'cisco', 'fortinet', 'sophos', 'bot', 'crawler', 'spider', 'python', 'curl', 'wget',
        'go-http', 'java/', 'okhttp', 'headless', 'preview', 'facebookexternalhit', 'whatsapp',
        'skypeuripreview', 'microsoft office existence discovery', 'scan',
    ];

    /** A machine usually follows a link within seconds of delivery; a person rarely does. */
    public const AUTOMATIC_WINDOW_SECONDS = 3;

    /**
     * @return array{client: string, device: string, automatic: bool}
     */
    public function classify(?string $userAgent): array
    {
        $ua = strtolower(trim((string) $userAgent));

        $client = match (true) {
            $ua === '' => 'Unknown',
            str_contains($ua, 'googleimageproxy') => 'Gmail',
            str_contains($ua, 'yahoomailproxy') => 'Yahoo Mail',
            str_contains($ua, 'outlook') || str_contains($ua, 'ms-office') || str_contains($ua, 'msoffice') => 'Outlook',
            str_contains($ua, 'thunderbird') => 'Thunderbird',
            // Apple Mail Privacy Protection fetches with a bare "Mozilla/5.0".
            $ua === 'mozilla/5.0' => 'Apple Mail',
            str_contains($ua, 'applewebkit') && ! str_contains($ua, 'safari') => 'Apple Mail',
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'chrome') => 'Chrome',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'safari') => 'Safari',
            default => 'Other',
        };

        $device = match (true) {
            in_array($client, ['Gmail', 'Yahoo Mail'], true) || $ua === 'mozilla/5.0' => 'proxy',
            (bool) preg_match('/mobile|android|iphone|ipad/', $ua) => 'mobile',
            default => 'desktop',
        };

        $automatic = false;
        if ($client !== 'Gmail' && $client !== 'Yahoo Mail') {
            foreach (self::AUTOMATIC_AGENTS as $needle) {
                if (str_contains($ua, $needle)) {
                    $automatic = true;
                    break;
                }
            }
        }

        return ['client' => $client, 'device' => $device, 'automatic' => $automatic];
    }

    public function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 32);
    }
}
