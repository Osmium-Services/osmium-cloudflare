<?php

declare(strict_types=1);

namespace Tests\Services\Cloudflare;

use Osmium\Services\Cloudflare\Models\CloudflareClient;
use Osmium\Services\Cloudflare\Models\CloudflareConfig;
use Osmium\Services\Cloudflare\Models\CloudflarePurger;
use PHPUnit\Framework\TestCase;

foreach (['Config', 'Client', 'Purger'] as $model) {
    require_once __DIR__ . "/../models/Cloudflare{$model}.php";
}

/**
 * Purging with a fake Cloudflare (no network): no-op when unconfigured, merging inside the cooldown, request shapes.
 */
final class CloudflarePurgerTest extends TestCase
{
    /** @var array<int, array{string, string, ?array<string, mixed>}> */
    private array $calls = [];
    private int $now = 1000;
    private string $state;

    protected function setUp(): void
    {
        $this->state = \sys_get_temp_dir() . '/cf-state-' . \uniqid() . '.json';
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->state);
    }

    private function purger(bool $configured = true, bool $succeeds = true): CloudflarePurger
    {
        $config = new CloudflareConfig(
            enabled: $configured,
            apiToken: $configured ? 'tok' : '',
            zoneId: 'zone1',
            hostnames: ['www.example.com', 'staging.example.com'],
        );
        $transport = function (string $method, string $url, string $token, ?array $body) use ($succeeds): array {
            $this->calls[] = [$method, $url, $body];
            return $succeeds ? ['success' => true, 'result' => []] : ['success' => false, 'errors' => [['message' => 'Bad token']]];
        };

        return new CloudflarePurger(
            config: $config,
            client: new CloudflareClient(apiToken: 'tok', zoneId: 'zone1', transport: $transport),
            statePath: $this->state,
            clock: fn(): int => $this->now,
        );
    }

    public function testUnconfiguredDoesNothing(): void
    {
        $this->assertFalse($this->purger(configured: false)->queue([], wholeSite: true));
        $this->assertSame([], $this->calls);
    }

    public function testEmptyUrlListDoesNothing(): void
    {
        $this->assertFalse($this->purger()->queue([], wholeSite: false));
        $this->assertSame([], $this->calls);
    }

    public function testFirstSitePurgeGoesOutAtOnceWithTheHostnames(): void
    {
        $this->assertTrue($this->purger()->queue([], wholeSite: true));
        $this->assertSame('POST', $this->calls[0][0]);
        $this->assertStringEndsWith('/zones/zone1/purge_cache', $this->calls[0][1]);
        $this->assertSame(['hosts' => ['www.example.com', 'staging.example.com']], $this->calls[0][2]);
    }

    public function testSavesNeverWaitAndStopAtTheMinuteBudget(): void
    {
        $purger = $this->purger();
        for ($i = 0; $i < 6; $i++) {
            $this->assertTrue($purger->queue([], wholeSite: true));
            $this->now += 1;
        }

        $this->assertCount(CloudflarePurger::MAX_PER_MINUTE, $this->calls);
        $this->assertTrue($purger->hasPending());
    }

    public function testHeldPurgeIsSentAsOneOnceTheWindowHasPassed(): void
    {
        $purger = $this->purger();
        for ($i = 0; $i < 6; $i++) $purger->queue([], wholeSite: true);
        $this->assertCount(4, $this->calls);

        $purger->flushPending();
        $this->assertCount(4, $this->calls);

        $this->now += 61;
        $purger->flushPending();
        $this->assertCount(5, $this->calls);
        $this->assertFalse($purger->hasPending());
    }

    public function testFlushWithNothingHeldSendsNothing(): void
    {
        $this->purger()->flushPending();
        $this->assertSame([], $this->calls);
    }

    public function testHeldUrlsAreMergedAndSentAsFiles(): void
    {
        $purger = $this->purger();
        for ($i = 0; $i < 4; $i++) $purger->queue([], wholeSite: true);
        $purger->queue(['https://www.example.com/a.png'], wholeSite: false);
        $purger->queue(['https://www.example.com/b.png', 'https://www.example.com/a.png'], wholeSite: false);

        $this->now += 61;
        $purger->flushPending();
        $this->assertSame(['files' => ['https://www.example.com/a.png', 'https://www.example.com/b.png']], $this->calls[4][2]);
    }

    public function testManyUrlsAreSplitIntoRequestsOf30(): void
    {
        $urls = [];
        for ($i = 0; $i < 65; $i++) $urls[] = "https://www.example.com/{$i}.png";

        $this->purger()->sendUrls($urls);
        $this->assertCount(3, $this->calls);
        $this->assertCount(5, $this->calls[2][2]['files']);
    }

    public function testEverythingAndDevelopmentModeShapes(): void
    {
        $purger = $this->purger();
        $purger->sendEverything();
        $this->assertSame(['purge_everything' => true], $this->calls[0][2]);

        $client = new CloudflareClient('tok', 'zone1', function (string $m, string $u, string $t, ?array $b): array {
            $this->calls[] = [$m, $u, $b];
            return ['success' => true, 'result' => ['value' => 'on', 'name' => 'example.com', 'status' => 'active']];
        });
        $client->setDevelopmentMode(true);
        $this->assertSame(['PATCH', 'https://api.cloudflare.com/client/v4/zones/zone1/settings/development_mode', ['value' => 'on']], $this->calls[1]);
        $this->assertTrue($client->developmentMode()['on']);
        $this->assertSame('example.com', $client->zone()['name']);
    }

    public function testCloudflareErrorIsReturnedAndUnreachableIsReported(): void
    {
        $this->assertSame(['ok' => false, 'error' => 'Bad token'], $this->purger(succeeds: false)->sendSite());

        $client = new CloudflareClient('tok', 'zone1', fn(): ?array => null);
        $this->assertSame('Cloudflare could not be reached', $client->purgeEverything()['error']);
    }

    public function testHostnamesAreCleaned(): void
    {
        $this->assertSame(
            ['www.example.com', 'example.com'],
            CloudflareConfig::splitHostnames("https://WWW.example.com/path\nexample.com, bad_host!"),
        );
    }

    public function testConfigRoundTripAndMissingFile(): void
    {
        $path = \sys_get_temp_dir() . '/cf-config-' . \uniqid() . '/cf.json.php';
        $this->assertFalse(CloudflareConfig::load($path)->isConfigured());

        (new CloudflareConfig(true, 'tok', 'z', ['a.com']))->save($path);
        $loaded = CloudflareConfig::load($path);
        $this->assertTrue($loaded->isConfigured());
        $this->assertSame(['a.com'], $loaded->hostnames);
        $this->assertStringStartsWith('<?php exit(); ?>', (string) \file_get_contents($path));

        \unlink($path);
        \rmdir(\dirname($path));
    }
}
