<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * When development mode is due to switch itself off (Cloudflare turns it off after 3 hours). Kept in its own small
 * file so the navbar icon can show it on every admin page without asking Cloudflare.
 */
class CloudflareDevMode
{
    public const HOURS = 3;
    private const PATH = 'app/cache/osmium-cloudflare-dev.json';

    /** @var callable */
    private $clock;

    public function __construct(private readonly string $path = self::PATH, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    public function isOn(): bool
    {
        return $this->until() > (int) ($this->clock)();
    }

    /** Called when development mode is switched on, or seen to be on at Cloudflare */
    public function markOn(): void
    {
        $alreadyOn = $this->isOn();
        if ($alreadyOn) return;

        $this->write((int) ($this->clock)() + self::HOURS * 3600);
    }

    public function markOff(): void
    {
        $this->write(0);
    }

    private function until(): int
    {
        $exists = \is_file($this->path);
        if (!$exists) return 0;

        $decoded = \json_decode((string) \file_get_contents($this->path), associative: true);

        return \is_array($decoded) ? (int) ($decoded['until'] ?? 0) : 0;
    }

    private function write(int $until): void
    {
        $directory = \dirname($this->path);
        $directoryExists = \is_dir($directory);
        if (!$directoryExists) \mkdir(directory: $directory, permissions: 0755, recursive: true);

        \file_put_contents($this->path, \json_encode(['until' => $until]), LOCK_EX);
    }
}
