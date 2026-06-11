<?php

declare(strict_types=1);

namespace App\Service\Notify;

use App\Support\Crypto;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Gotify adapter. channel->config (sealed) holds {server_url, app_token}. */
final class GotifyChannel implements ChannelInterface
{
    /** severity 1..5 → gotify priority */
    private const PRIORITY = [1 => 2, 2 => 4, 3 => 6, 4 => 8, 5 => 10];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly Crypto $crypto,
    ) {
    }

    public function send(object $channel, Notification $n): DeliveryResult
    {
        $cfg = json_decode($this->crypto->unseal($channel->config), true);
        $body = $n->body . ($n->url !== '' ? "\n\n[Open in panel]({$n->url})" : '');
        try {
            $this->http->request('POST', rtrim($cfg['server_url'], '/') . '/message', [
                'headers' => ['X-Gotify-Key' => $cfg['app_token']],
                'json' => [
                    'title' => $n->title,
                    'message' => $body,
                    'priority' => self::PRIORITY[$n->severity] ?? 6,
                    'extras' => ['client::display' => ['contentType' => 'text/markdown']],
                ],
                'timeout' => 15,
            ]);
            return new DeliveryResult(true);
        } catch (GuzzleException $e) {
            return new DeliveryResult(false, 'transport: ' . $e->getMessage());
        }
    }
}
