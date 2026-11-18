<?php

declare(strict_types=1);

namespace Tests\Services\Cloudflare;

use Osmium\Services\Cloudflare\Models\CloudflareConfig;
use Osmium\Services\Cloudflare\Models\CloudflareDevMode;
use Osmium\Services\Cloudflare\Models\CloudflareStatusIcon;
use PHPUnit\Framework\TestCase;

foreach (['Config', 'DevMode', 'StatusIcon'] as $model) {
    require_once __DIR__ . "/../models/Cloudflare{$model}.php";
}

/**
 * The navbar cloud: shown only when set up, amber and crossed while development mode is on, and the dev-mode timer.
 */
final class CloudflareStatusIconTest extends TestCase
{
    private string $originalDirectory;
    private string $root;

    protected function setUp(): void
    {
        $this->originalDirectory = (string) \getcwd();
        $this->root = \sys_get_temp_dir() . '/cf-icon-' . \uniqid();
        \mkdir($this->root);
        \chdir($this->root);
    }

    protected function tearDown(): void
    {
        \chdir($this->originalDirectory);
        foreach (['app/cache/osmium-cloudflare-dev.json', CloudflareConfig::PATH] as $file) {
            @\unlink($this->root . '/' . $file);
        }
        foreach (['app/cache', 'app/config/services', 'app/config', 'app', ''] as $directory) {
            @\rmdir($this->root . '/' . $directory);
        }
    }

    public function testNoIconWhenNotSetUp(): void
    {
        $this->assertSame([], CloudflareStatusIcon::icons([]));
    }

    public function testNoIconWhenSwitchedOff(): void
    {
        (new CloudflareConfig(false, 'tok', 'z', ['a.com']))->save();
        $this->assertSame([], CloudflareStatusIcon::icons([]));
    }

    public function testGreenCloudWhenActive(): void
    {
        (new CloudflareConfig(true, 'tok', 'z', ['a.com']))->save();
        $icon = CloudflareStatusIcon::icons([])[0];

        $this->assertSame('bx bx-cloud', $icon['icon']);
        $this->assertSame('success', $icon['color']);
        $this->assertFalse($icon['crossed']);
        $this->assertSame('cloudflare/', $icon['path']);
    }

    public function testAmberCrossedCloudWhileDevelopmentModeIsOn(): void
    {
        (new CloudflareConfig(true, 'tok', 'z', ['a.com']))->save();
        (new CloudflareDevMode())->markOn();
        $icon = CloudflareStatusIcon::icons([])[0];

        $this->assertSame('warning', $icon['color']);
        $this->assertTrue($icon['crossed']);
        $this->assertStringContainsString('development mode', $icon['title']);
    }

    public function testDevelopmentModeEndsAfterThreeHoursOrWhenSwitchedOff(): void
    {
        $now = 1000;
        $path = $this->root . '/dev.json';
        $devMode = new CloudflareDevMode($path, function () use (&$now): int { return $now; });
        $this->assertFalse($devMode->isOn());

        $devMode->markOn();
        $this->assertTrue($devMode->isOn());

        $now += 3 * 3600 - 1;
        $this->assertTrue($devMode->isOn());
        $now += 2;
        $this->assertFalse($devMode->isOn(), 'Cloudflare switches it off itself after 3 hours');

        $now = 5000;
        $devMode->markOn();
        $devMode->markOff();
        $this->assertFalse($devMode->isOn());
        @\unlink($path);
    }

    public function testMarkingOnAgainKeepsTheOriginalEndTime(): void
    {
        $now = 1000;
        $path = $this->root . '/dev2.json';
        $devMode = new CloudflareDevMode($path, function () use (&$now): int { return $now; });
        $devMode->markOn();
        $now += 3600;
        $devMode->markOn();
        $now += 2 * 3600 + 1;

        $this->assertFalse($devMode->isOn(), 'seeing it on at Cloudflare must not extend the timer');
        @\unlink($path);
    }
}
