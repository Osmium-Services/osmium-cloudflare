<?php

declare(strict_types=1);

namespace Osmium\Services\Cloudflare\Models;

/**
 * The few Cloudflare API calls this service makes. $transport is (method, url, token, ?body) => ?array decoded answer
 * (null when Cloudflare could not be reached); tests pass a fake, so nothing touches the network.
 */
class CloudflareClient
{
    private const API = 'https://api.cloudflare.com/client/v4';
    private const FILES_PER_REQUEST = 30; // Cloudflare's limit on files in one purge

    /** @var callable */
    private $transport;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $zoneId,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /** @param string[] $hostnames @return array{ok: bool, error: string} */
    public function purgeHostnames(array $hostnames): array
    {
        return $this->purge(['hosts' => \array_values($hostnames)]);
    }

    /** @param string[] $urls @return array{ok: bool, error: string} */
    public function purgeFiles(array $urls): array
    {
        $result = ['ok' => true, 'error' => ''];
        foreach (\array_chunk(\array_values($urls), self::FILES_PER_REQUEST) as $chunk) {
            $result = $this->purge(['files' => $chunk]);
            if (!$result['ok']) return $result;
        }

        return $result;
    }

    /** @return array{ok: bool, error: string} */
    public function purgeEverything(): array
    {
        return $this->purge(['purge_everything' => true]);
    }

    /** @return array{ok: bool, error: string, name: string, status: string} */
    public function zone(): array
    {
        $answer = $this->call('GET', '');
        $result = $this->outcome($answer);

        return $result + [
            'name' => (string) ($answer['result']['name'] ?? ''),
            'status' => (string) ($answer['result']['status'] ?? ''),
        ];
    }

    /** @return array{ok: bool, error: string, on: bool} */
    public function developmentMode(): array
    {
        $answer = $this->call('GET', '/settings/development_mode');
        $on = ($answer['result']['value'] ?? 'off') === 'on';

        return $this->outcome($answer) + ['on' => $on];
    }

    /** @return array{ok: bool, error: string} */
    public function setDevelopmentMode(bool $on): array
    {
        return $this->outcome($this->call('PATCH', '/settings/development_mode', ['value' => $on ? 'on' : 'off']));
    }

    /** @param array<string, mixed> $body @return array{ok: bool, error: string} */
    private function purge(array $body): array
    {
        return $this->outcome($this->call('POST', '/purge_cache', $body));
    }

    /** @param ?array<string, mixed> $body @return ?array<string, mixed> */
    private function call(string $method, string $path, ?array $body = null): ?array
    {
        $url = self::API . '/zones/' . \rawurlencode($this->zoneId) . $path;

        return ($this->transport)($method, $url, $this->apiToken, $body);
    }

    /** @param ?array<string, mixed> $answer @return array{ok: bool, error: string} */
    private function outcome(?array $answer): array
    {
        $unreachable = $answer === null;
        if ($unreachable) return ['ok' => false, 'error' => 'Cloudflare could not be reached'];

        $ok = ($answer['success'] ?? false) === true;
        if ($ok) return ['ok' => true, 'error' => ''];

        $message = (string) ($answer['errors'][0]['message'] ?? 'Cloudflare refused the request');

        return ['ok' => false, 'error' => $message];
    }

    /** @param ?array<string, mixed> $body @return ?array<string, mixed> */
    public static function curl(string $method, string $url, string $token, ?array $body): ?array
    {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body === null ? null : \json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = \curl_exec($ch);
        $failed = !\is_string($response);
        if ($failed) return null;

        $decoded = \json_decode($response, associative: true);

        return \is_array($decoded) ? $decoded : null;
    }
}
