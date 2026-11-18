<?php

declare(strict_types=1);

/**
 * Deploy-script entry point: php app/services/osmium-cloudflare/cloudflare.php purge
 * Clears the Cloudflare cache for this site's hostnames (run it over SSH after the rsync). Exit 0 when sent or
 * when Cloudflare is not set up on this site, 1 when Cloudflare refused it.
 */

if (PHP_SAPI !== 'cli') exit(1);

\chdir(\dirname(__DIR__, 3)); // The web root: config paths are relative to it
foreach (['Config', 'Client', 'Purger'] as $model) {
    require_once __DIR__ . "/models/Cloudflare{$model}.php";
}

use Osmium\Services\Cloudflare\Models\CloudflareConfig;
use Osmium\Services\Cloudflare\Models\CloudflarePurger;

$command = $argv[1] ?? '';
$unknown = $command !== 'purge';
if ($unknown) {
    \fwrite(STDERR, "Usage: php cloudflare.php purge\n");
    exit(2);
}

$purger = CloudflarePurger::fromSiteConfig();
$config = CloudflareConfig::load();
$notConfigured = !$config->isConfigured();
if ($notConfigured) {
    echo "Cloudflare is not set up on this site: nothing to clear.\n";
    exit(0);
}

$result = $purger->sendSite();
echo $result['ok'] ? "Cloudflare cache cleared.\n" : 'Cloudflare purge failed: ' . $result['error'] . "\n";
exit($result['ok'] ? 0 : 1);
