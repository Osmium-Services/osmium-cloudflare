<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * content.changed: an admin or MCP write succeeded, so clear the site's cache. Never throws: a failing purge must
 * not affect the write that already succeeded.
 */
class CloudflareHooks
{
    /** @param array<string, mixed> $payload */
    public static function contentChanged(array $payload): void
    {
        try {
            CloudflarePurger::purgeSite();
        } catch (\Throwable $e) {
            \error_log('Cloudflare purge failed: ' . $e->getMessage());
        }
    }
}
