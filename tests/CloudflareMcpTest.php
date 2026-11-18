<?php

declare(strict_types=1);

namespace Tests\Services\Cloudflare;

use Osmium\Core\Library\McpToolException;
use Osmium\Services\Cloudflare\Models\CloudflareConfig;
use Osmium\Services\Cloudflare\Models\CloudflareMcp;
use PHPUnit\Framework\TestCase;

foreach (['Config', 'Client', 'Purger', 'Mcp'] as $model) {
    require_once __DIR__ . "/../models/Cloudflare{$model}.php";
}
require_once __DIR__ . '/../../../core/library/McpToolException.php';

/**
 * The cloudflare_purge tool's refusals. Every case here is refused before any call to Cloudflare, so no network.
 */
final class CloudflareMcpTest extends TestCase
{
    private string $originalDirectory;
    private string $root;

    protected function setUp(): void
    {
        $this->originalDirectory = (string) \getcwd();
        $this->root = \sys_get_temp_dir() . '/cf-mcp-' . \uniqid();
        \mkdir($this->root);
        \chdir($this->root);
    }

    protected function tearDown(): void
    {
        \chdir($this->originalDirectory);
        @\unlink($this->root . '/' . CloudflareConfig::PATH);
        @\rmdir($this->root . '/app/config/services');
        @\rmdir($this->root . '/app/config');
        @\rmdir($this->root . '/app');
        @\rmdir($this->root);
    }

    private function configure(): void
    {
        (new CloudflareConfig(true, 'tok', 'zone1', ['staging.example.com']))->save();
    }

    public function testRefusedWhenNotConfigured(): void
    {
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('not set up');
        CloudflareMcp::purge(['arguments' => ['scope' => 'site']]);
    }

    public function testUrlsOnOtherHostnamesAreRefusedNotReportedAsCleared(): void
    {
        $this->configure();
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('None of those URLs are on this site');
        CloudflareMcp::purge(['arguments' => ['scope' => 'urls', 'urls' => ['https://www.example.com/a.css']]]);
    }

    public function testEverythingNeedsConfirmation(): void
    {
        $this->configure();
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('confirm_everything');
        CloudflareMcp::purge(['arguments' => ['scope' => 'everything']]);
    }

    public function testUnknownScopeIsRefused(): void
    {
        $this->configure();
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('scope must be');
        CloudflareMcp::purge(['arguments' => ['scope' => 'dns']]);
    }

    public function testToolIsAWriteTool(): void
    {
        $tools = CloudflareMcp::tools([]);
        $this->assertSame('cloudflare_purge', $tools[0]['name']);
        $this->assertTrue($tools[0]['write']);
    }
}
