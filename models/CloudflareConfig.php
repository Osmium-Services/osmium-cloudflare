<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * This site's Cloudflare settings, read from app/config/services/osmium-cloudflare.json.php (gitignored: it holds
 * the API token). Staging and live often share one zone, but each site keeps its own zone ID and hostnames.
 */
class CloudflareConfig
{
    public const PATH = 'app/config/services/osmium-cloudflare.json.php';

    /** @param string[] $hostnames */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $apiToken,
        public readonly string $zoneId,
        public readonly array $hostnames,
    ) {}

    public static function load(string $path = self::PATH): self
    {
        $fileExists = \file_exists($path);
        if (!$fileExists) return self::empty();

        $content = (string) \file_get_contents($path);
        $jsonStart = \strpos(haystack: $content, needle: '{');
        $noJson = $jsonStart === false;
        if ($noJson) return self::empty();

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $settings = $decoded->cloudflare ?? null;
        if ($settings === null) return self::empty();

        return new self(
            enabled: (bool) ($settings->enabled ?? false),
            apiToken: (string) ($settings->apiToken ?? ''),
            zoneId: (string) ($settings->zoneId ?? ''),
            hostnames: self::cleanHostnames((array) ($settings->hostnames ?? [])),
        );
    }

    public function save(string $path = self::PATH): void
    {
        $directory = \dirname($path);
        $directoryExists = \is_dir($directory);
        if (!$directoryExists) \mkdir(directory: $directory, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['cloudflare' => [
                'enabled' => $this->enabled,
                'apiToken' => $this->apiToken,
                'zoneId' => $this->zoneId,
                'hostnames' => $this->hostnames,
            ]],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        \file_put_contents($path, "<?php exit(); ?>\n" . $json . "\n");
    }

    /** True when a purge can actually be sent */
    public function isConfigured(): bool
    {
        $hasCredentials = $this->apiToken !== '' && $this->zoneId !== '';
        $hasHostnames = $this->hostnames !== [];

        return $this->enabled && $hasCredentials && $hasHostnames;
    }

    /**
     * Hostnames from a typed list (one per line or comma separated): lower case, no scheme, no path, no duplicates.
     *
     * @param string[] $raw
     * @return string[]
     */
    public static function cleanHostnames(array $raw): array
    {
        $clean = [];
        foreach ($raw as $entry) {
            $entry = \strtolower(\trim((string) $entry));
            $entry = (string) \preg_replace('#^https?://#', '', $entry);
            $entry = \explode('/', $entry)[0];
            $isHostname = (bool) \preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $entry);
            if ($isHostname) $clean[$entry] = $entry;
        }

        return \array_values($clean);
    }

    /** @return string[] */
    public static function splitHostnames(string $typed): array
    {
        return self::cleanHostnames(\preg_split('/[\s,]+/', $typed) ?: []);
    }

    private static function empty(): self
    {
        return new self(enabled: false, apiToken: '', zoneId: '', hostnames: []);
    }
}
