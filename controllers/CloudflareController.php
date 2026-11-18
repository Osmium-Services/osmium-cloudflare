<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\Cloudflare\Models\CloudflareClient;
use Osmium\Services\Cloudflare\Models\CloudflareConfig;
use Osmium\Services\Cloudflare\Models\CloudflarePurger;

/**
 * Cloudflare: settings, test connection, manual purges and development mode - a full-page form POST/redirect flow.
 *
 * Routes:
 *   - index() → /admin/cloudflare/  (GET shows the page; POST runs one action from the "action" field)
 */
class CloudflareController extends AdminController
{
    private const ACTIONS = ['save', 'test', 'purge-site', 'purge-urls', 'purge-everything', 'dev-on', 'dev-off'];

    public function index(): void
    {
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($isPost) $this->handlePost();

        $config = CloudflareConfig::load();
        if ($config->isConfigured()) CloudflarePurger::fromSiteConfig()->flushPending();
        $this->data['admin']['cloudflare'] = [
            'enabled' => $config->enabled,
            'hasToken' => $config->apiToken !== '',
            'zoneId' => $config->zoneId,
            'hostnames' => \implode("\n", $config->hostnames),
            'configured' => $config->isConfigured(),
            'zone' => $config->isConfigured() ? $this->zoneStatus($config) : null,
        ];
        $this->data['admin']['message'] = $_SESSION['cloudflare_message'] ?? null;
        unset($_SESSION['cloudflare_message']);

        $this->setView('cloudflare/index.phtml');
    }

    private function handlePost(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->finish(false, 'Invalid form submission. Please try again.');

        $action = (string) ($_POST['action'] ?? '');
        $known = \in_array($action, self::ACTIONS, true);
        if (!$known) $this->finish(false, 'Unknown action.');

        $config = CloudflareConfig::load();
        [$ok, $text] = $action === 'save' ? $this->save($config) : $this->run($action, $config);
        $this->finish($ok, $text);
    }

    /** @return array{0: bool, 1: string} */
    private function save(CloudflareConfig $current): array
    {
        $postedToken = \trim((string) ($_POST['api_token'] ?? ''));
        $apiToken = $postedToken === '' ? $current->apiToken : $postedToken;
        $zoneId = \trim((string) ($_POST['zone_id'] ?? ''));
        $hostnames = CloudflareConfig::splitHostnames((string) ($_POST['hostnames'] ?? ''));
        $enabled = isset($_POST['enabled']);

        $incomplete = $apiToken === '' || $zoneId === '' || $hostnames === [];
        if ($enabled && $incomplete) return [false, 'Enter the token, zone ID and at least one hostname before switching Cloudflare on.'];

        (new CloudflareConfig(enabled: $enabled, apiToken: $apiToken, zoneId: $zoneId, hostnames: $hostnames))->save();
        $this->admin->model->changelog->log(description: 'Updated Cloudflare settings', recordType: 'settings');

        return [true, 'Settings saved.'];
    }

    /** @return array{0: bool, 1: string} */
    private function run(string $action, CloudflareConfig $config): array
    {
        $notConfigured = !$config->isConfigured();
        if ($notConfigured) return [false, 'Save the settings and switch Cloudflare on first.'];

        $client = new CloudflareClient(apiToken: $config->apiToken, zoneId: $config->zoneId);
        $purger = new CloudflarePurger(config: $config, client: $client);

        $result = match ($action) {
            'test' => $client->zone(),
            'purge-site' => $purger->sendSite(),
            'purge-urls' => $purger->sendUrls($this->postedUrls($config)),
            'purge-everything' => $purger->sendEverything(),
            'dev-on' => $client->setDevelopmentMode(true),
            'dev-off' => $client->setDevelopmentMode(false),
        };

        $description = $this->describe($action);
        if ($result['ok'] && $action !== 'test') {
            $this->admin->model->changelog->log(description: 'Cloudflare: ' . $description, recordType: 'settings');
        }

        return [$result['ok'], $result['ok'] ? \ucfirst($description) . ' done.' : $result['error']];
    }

    /** @return string[] URLs from the box, only on this site's hostnames */
    private function postedUrls(CloudflareConfig $config): array
    {
        $urls = [];
        foreach (\preg_split('/\s+/', \trim((string) ($_POST['urls'] ?? ''))) ?: [] as $url) {
            $host = \strtolower((string) \parse_url($url, PHP_URL_HOST));
            $onThisSite = \in_array($host, $config->hostnames, true);
            if ($onThisSite) $urls[] = $url;
        }

        return $urls;
    }

    private function describe(string $action): string
    {
        return match ($action) {
            'test' => 'connection test',
            'purge-site' => 'cache cleared for the site',
            'purge-urls' => 'cache cleared for the listed URLs',
            'purge-everything' => 'cache cleared for the whole zone',
            'dev-on' => 'development mode switched on',
            'dev-off' => 'development mode switched off',
        };
    }

    /** @return ?array{ok: bool, error: string, name: string, status: string, devMode: bool} */
    private function zoneStatus(CloudflareConfig $config): ?array
    {
        $client = new CloudflareClient(apiToken: $config->apiToken, zoneId: $config->zoneId);
        $zone = $client->zone();
        $dev = $client->developmentMode();

        return $zone + ['devMode' => $dev['on']];
    }

    private function finish(bool $ok, string $text): void
    {
        $_SESSION['cloudflare_message'] = ['ok' => $ok, 'text' => $text];
        $this->redirect('cloudflare/');
    }
}
