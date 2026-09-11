<?php

namespace App\Wealthsimple;

use App\Models\Meta;
use Illuminate\Support\Facades\Http;

final class ClientIdScraper
{
    public const LOGIN_URL = 'https://my.wealthsimple.com/app/login';

    /**
     * Production clientId from Wealthsimple login JS — same scrape as Bagholder master.
     * Never invent a second id. Cache the scraped value.
     */
    public static function resolve(?string $sessionClientId = null): string
    {
        if ($sessionClientId) {
            self::save($sessionClientId);

            return $sessionClientId;
        }
        $cached = self::cached();
        if ($cached) {
            return $cached;
        }

        return self::scrape();
    }

    public static function cached(): string
    {
        return trim((string) Meta::getValue('ws_client_id', ''));
    }

    public static function save(string $cid): void
    {
        $cid = trim($cid);
        if ($cid === '') {
            return;
        }
        Meta::putValue('ws_client_id', $cid);
    }

    public static function scrape(): string
    {
        $cached = self::cached();
        if ($cached !== '') {
            return $cached;
        }
        try {
            $html = Http::timeout(20)
                ->withHeaders(self::headers())
                ->get(self::LOGIN_URL)
                ->body();
            if (! preg_match('/<script[^>]+src="([^"]*app-[a-f0-9]+\.js[^"]*)"/i', $html, $m)) {
                return '';
            }
            $jsUrl = $m[1];
            if (str_starts_with($jsUrl, '//')) {
                $jsUrl = 'https:'.$jsUrl;
            } elseif (str_starts_with($jsUrl, '/')) {
                $jsUrl = 'https://my.wealthsimple.com'.$jsUrl;
            }
            $js = Http::timeout(20)->withHeaders(self::headers())->get($jsUrl)->body();
            if (! preg_match('/production:.*?clientId:"([a-f0-9]+)"/s', $js, $m2)) {
                return '';
            }
            self::save($m2[1]);

            return $m2[1];
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Value scraped from Wealthsimple production JS using the Bagholder master method.
     * Used only as a last-resort fallback when live scrape is blocked.
     */
    public static function scrapedProductionFallback(): string
    {
        return '476a51893b1c1c9c633f85b976706baa653bea41c1b1c62913128cc588b47e26';
    }

    private static function headers(): array
    {
        $ua = trim((string) Meta::getValue('ws_user_agent', ''));

        return $ua !== '' ? ['User-Agent' => $ua] : ['User-Agent' => 'Mozilla/5.0'];
    }
}
