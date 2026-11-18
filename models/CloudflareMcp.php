<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

use Osmium\Core\Library\McpToolException;

/** The cloudflare_purge MCP tool (write tool: needs mcp.writesEnabled) */
class CloudflareMcp
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>> tool descriptors
     */
    public static function tools(array $payload): array
    {
        return [
            [
                'name' => 'cloudflare_purge',
                'description' => 'Clear the Cloudflare cache for this site. scope "site" clears every cached file for the site\'s hostnames; scope "urls" clears only the full URLs given. scope "everything" clears the whole zone, including other sites sharing it, and works only when confirm_everything is true: use it only when explicitly asked. Cached pages are rebuilt on the next visit, so this is safe to repeat, but Cloudflare allows only about 5 purges a minute.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'scope' => ['type' => 'string', 'enum' => ['site', 'urls', 'everything'], 'description' => 'What to clear'],
                        'urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Full URLs, for scope "urls"'],
                        'confirm_everything' => ['type' => 'boolean', 'description' => 'Must be true for scope "everything"'],
                    ],
                    'required' => ['scope'],
                ],
                'write' => true,
                'handler' => self::class . '::purge',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>
     */
    public static function purge(array $call): array
    {
        $arguments = (array) ($call['arguments'] ?? []);
        $scope = (string) ($arguments['scope'] ?? '');

        $config = CloudflareConfig::load();
        $notConfigured = !$config->isConfigured();
        if ($notConfigured) throw new McpToolException('Cloudflare is not set up on this site. Add the token, zone ID and hostnames under Service Settings > Cloudflare and switch it on.');

        $purger = CloudflarePurger::fromSiteConfig();
        $result = match ($scope) {
            'site' => $purger->sendSite(),
            'urls' => self::sendUrls($purger, self::urls($arguments)),
            'everything' => ($arguments['confirm_everything'] ?? false) === true
                ? $purger->sendEverything()
                : ['ok' => false, 'error' => 'Purging everything needs confirm_everything set to true'],
            default => ['ok' => false, 'error' => 'scope must be site, urls or everything'],
        };

        if (!$result['ok']) throw new McpToolException($result['error']);

        return ['purged' => $scope, 'message' => 'Cloudflare cache cleared'];
    }

    /** @param string[] $urls @return array{ok: bool, error: string} */
    private static function sendUrls(CloudflarePurger $purger, array $urls): array
    {
        $noneOnThisSite = $urls === [];
        if ($noneOnThisSite) return ['ok' => false, 'error' => 'None of those URLs are on this site\'s hostnames, so nothing was cleared'];

        return $purger->sendUrls($urls);
    }

    /** @param array<string, mixed> $arguments @return string[] only URLs on this site's hostnames */
    private static function urls(array $arguments): array
    {
        $hostnames = CloudflareConfig::load()->hostnames;
        $urls = [];
        foreach ((array) ($arguments['urls'] ?? []) as $url) {
            $host = (string) \parse_url((string) $url, PHP_URL_HOST);
            $onThisSite = \in_array(\strtolower($host), $hostnames, true);
            if ($onThisSite) $urls[] = (string) $url;
        }

        return $urls;
    }
}
