<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * admin.status_icons: a cloud in the admin navbar while Cloudflare is set up and on. Green normally, amber and
 * crossed through while development mode bypasses the cache. Reads only local files (no Cloudflare call).
 */
class CloudflareStatusIcon
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public static function icons(array $payload): array
    {
        $config = CloudflareConfig::load();
        $notActive = !$config->isConfigured();
        if ($notActive) return [];

        return [self::icon(developmentMode: (new CloudflareDevMode())->isOn())];
    }

    /** @return array<string, mixed> */
    public static function icon(bool $developmentMode): array
    {
        return [
            'id' => 'cloudflare',
            'title' => $developmentMode ? 'Cloudflare development mode is on: the cache is bypassed' : 'Cloudflare cache purging is active',
            'icon' => 'bx bx-cloud',
            'path' => 'cloudflare/',
            'color' => $developmentMode ? 'warning' : 'success',
            'crossed' => $developmentMode,
        ];
    }
}
