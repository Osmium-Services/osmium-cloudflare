<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * The one place that purges. purge() and purgeSite() are for other code and the content.changed hook: they do
 * nothing when the service is not configured, and rapid calls are merged (Cloudflare's free plan allows about
 * 5 purge requests a minute). The first call goes straight out; calls inside the cooldown wait, and one purge
 * covering all of them is sent when the cooldown ends, after the visitor's response has finished.
 * The send*() methods are for buttons and scripts: immediate, with Cloudflare's answer.
 */
class CloudflarePurger
{
    public const COOLDOWN_SECONDS = 15;
    private const STATE_PATH = 'app/cache/osmium-cloudflare-state.json';

    /** @var callable */
    private $clock;
    /** @var callable */
    private $sleeper;
    /** @var callable */
    private $defer;

    public function __construct(
        private readonly CloudflareConfig $config,
        private readonly CloudflareClient $client,
        private readonly string $statePath = self::STATE_PATH,
        ?callable $clock = null,
        ?callable $sleeper = null,
        ?callable $defer = null,
    ) {
        $this->clock = $clock ?? 'time';
        $this->sleeper = $sleeper ?? 'sleep';
        $this->defer = $defer ?? [self::class, 'afterResponse'];
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

    /** @param string[] $urls @return bool true when sent or queued, false when nothing was done */
    public function queue(array $urls, bool $wholeSite): bool
    {
        $nothingToDo = !$this->config->isConfigured() || (!$wholeSite && $urls === []);
        if ($nothingToDo) return false;

        $state = $this->readState();
        $now = (int) ($this->clock)();
        $coolingDown = $now - $state['last'] < self::COOLDOWN_SECONDS;
        if (!$coolingDown && !$state['pending']) return $this->sendNow($urls, $wholeSite, $now);

        $alreadyWaiting = $state['pending'];
        $state['pending'] = true;
        $state['site'] = $state['site'] || $wholeSite;
        $state['urls'] = \array_values(\array_unique(\array_merge($state['urls'], $urls)));
        $this->writeState($state);
        if (!$alreadyWaiting) ($this->defer)(fn() => $this->flush());

        return true;
    }

    /** Waits out the cooldown, then sends everything that was queued as one purge */
    public function flush(): void
    {
        $state = $this->readState();
        $wait = $state['last'] + self::COOLDOWN_SECONDS - (int) ($this->clock)();
        if ($wait > 0) ($this->sleeper)($wait);

        $state = $this->readState();
        $nothingQueued = !$state['pending'];
        if ($nothingQueued) return;

        $this->sendNow($state['urls'], $state['site'], (int) ($this->clock)());
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

    /** @param string[] $urls */
    private function sendNow(array $urls, bool $wholeSite, int $now): bool
    {
        $result = $wholeSite ? $this->client->purgeHostnames($this->config->hostnames) : $this->client->purgeFiles($urls);
        $this->writeState(['last' => $now, 'pending' => false, 'site' => false, 'urls' => []]);
        if (!$result['ok']) \error_log('Cloudflare purge failed: ' . $result['error']);

        return $result['ok'];
    }

    /** @param array{ok: bool, error: string} $result @return array{ok: bool, error: string} */
    private function recorded(array $result): array
    {
        $this->writeState(['last' => (int) ($this->clock)(), 'pending' => false, 'site' => false, 'urls' => []]);

        return $result;
    }

    /** @return array{last: int, pending: bool, site: bool, urls: string[]} */
    private function readState(): array
    {
        $empty = ['last' => 0, 'pending' => false, 'site' => false, 'urls' => []];
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

    /** Runs $task once the response has gone to the visitor, so a save never waits for the cooldown */
    public static function afterResponse(callable $task): void
    {
        \register_shutdown_function(function () use ($task): void {
            $canFinish = \function_exists('fastcgi_finish_request');
            if ($canFinish) \fastcgi_finish_request();
            try {
                $task();
            } catch (\Throwable $e) {
                \error_log('Cloudflare purge failed: ' . $e->getMessage());
            }
        });
    }
}
