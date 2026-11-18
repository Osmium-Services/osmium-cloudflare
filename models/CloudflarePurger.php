<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * The one place that purges. purge() and purgeSite() are for other code and the content.changed hook: they do
 * nothing when the service is not configured, and they never wait. Cloudflare's free plan allows about 5 purge
 * requests a minute, so at most MAX_PER_MINUTE go out; anything over that is held and sent, merged into one purge,
 * by the next call or when the Cloudflare admin page loads (flushPending).
 * The send*() methods are for buttons and scripts: immediate, with Cloudflare's answer.
 */
class CloudflarePurger
{
    public const MAX_PER_MINUTE = 4;
    private const WINDOW_SECONDS = 60;
    private const STATE_PATH = 'app/cache/osmium-cloudflare-state.json';

    /** @var callable */
    private $clock;

    public function __construct(
        private readonly CloudflareConfig $config,
        private readonly CloudflareClient $client,
        private readonly string $statePath = self::STATE_PATH,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? 'time';
    }

    public static function fromSiteConfig(): self
    {
        $config = CloudflareConfig::load();
        $client = new CloudflareClient(apiToken: $config->apiToken, zoneId: $config->zoneId);

        return new self(config: $config, client: $client);
    }

    /** Purge these file URLs (full URLs, as visitors request them). No-op when not configured. */
    public static function purge(array $urls): bool
    {
        return self::fromSiteConfig()->queue(urls: \array_values($urls), wholeSite: false);
    }

    /** Purge everything cached for the site's hostnames. No-op when not configured. */
    public static function purgeSite(): bool
    {
        return self::fromSiteConfig()->queue(urls: [], wholeSite: true);
    }

    /** @param string[] $urls @return bool true when sent or held for the next call, false when nothing was done */
    public function queue(array $urls, bool $wholeSite): bool
    {
        $nothingToDo = !$this->config->isConfigured() || (!$wholeSite && $urls === []);
        if ($nothingToDo) return false;

        $state = $this->readState();
        $state['site'] = $state['site'] || $wholeSite;
        $state['urls'] = \array_values(\array_unique(\array_merge($state['urls'], $urls)));
        $state['pending'] = true;

        $this->sendIfAllowed($state);

        return true;
    }

    /** Sends what was held back, if the rate limit allows. Cheap when nothing is held. */
    public function flushPending(): void
    {
        $state = $this->readState();
        $nothingHeld = !$state['pending'];
        if ($nothingHeld) return;

        $this->sendIfAllowed($state);
    }

    /** @return bool true when anything is being held back */
    public function hasPending(): bool
    {
        return $this->readState()['pending'];
    }

    /** @return array{ok: bool, error: string} */
    public function sendSite(): array
    {
        return $this->recorded($this->client->purgeHostnames($this->config->hostnames));
    }

    /** @return array{ok: bool, error: string} */
    public function sendEverything(): array
    {
        return $this->recorded($this->client->purgeEverything());
    }

    /** @param string[] $urls @return array{ok: bool, error: string} */
    public function sendUrls(array $urls): array
    {
        return $this->recorded($this->client->purgeFiles($urls));
    }

    /** @param array{sent: int[], pending: bool, site: bool, urls: string[]} $state */
    private function sendIfAllowed(array $state): void
    {
        $now = (int) ($this->clock)();
        $state['sent'] = \array_values(\array_filter($state['sent'], fn(int $at): bool => $now - $at < self::WINDOW_SECONDS));
        $overBudget = \count($state['sent']) >= self::MAX_PER_MINUTE;
        if ($overBudget) {
            $this->writeState($state);
            return;
        }

        $result = $state['site'] ? $this->client->purgeHostnames($this->config->hostnames) : $this->client->purgeFiles($state['urls']);
        if (!$result['ok']) \error_log('Cloudflare purge failed: ' . $result['error']);

        $state['sent'][] = $now;
        $this->writeState(['sent' => $state['sent'], 'pending' => false, 'site' => false, 'urls' => []]);
    }

    /** @param array{ok: bool, error: string} $result @return array{ok: bool, error: string} */
    private function recorded(array $result): array
    {
        $state = $this->readState();
        $state['sent'][] = (int) ($this->clock)();
        $this->writeState($state);

        return $result;
    }

    /** @return array{sent: int[], pending: bool, site: bool, urls: string[]} */
    private function readState(): array
    {
        $empty = ['sent' => [], 'pending' => false, 'site' => false, 'urls' => []];
        $exists = \is_file($this->statePath);
        if (!$exists) return $empty;

        $decoded = \json_decode((string) \file_get_contents($this->statePath), associative: true);
        $valid = \is_array($decoded);

        return $valid ? \array_merge($empty, $decoded) : $empty;
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $directory = \dirname($this->statePath);
        $directoryExists = \is_dir($directory);
        if (!$directoryExists) \mkdir(directory: $directory, permissions: 0755, recursive: true);

        \file_put_contents($this->statePath, \json_encode($state), LOCK_EX);
    }
}
