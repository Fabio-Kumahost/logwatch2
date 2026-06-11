<?php

declare(strict_types=1);

namespace App\Service\Notify;

use App\Support\Crypto;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;

/** Discord webhook adapter. channel->config (sealed) holds {webhook_url}. */
final class DiscordChannel implements ChannelInterface
{
    private const COLORS = [1 => 0x95a5a6, 2 => 0x3498db, 3 => 0xf1c40f, 4 => 0xe67e22, 5 => 0xe74c3c];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly Crypto $crypto,
    ) {
    }

    public function send(object $channel, Notification $n): DeliveryResult
    {
        $cfg = json_decode($this->crypto->unseal($channel->config), true);
        $embed = [
            'title' => mb_substr($n->title, 0, 256),
            'description' => mb_substr($n->body, 0, 4000),
            'color' => self::COLORS[$n->severity] ?? self::COLORS[3],
        ];
        if ($n->url !== '') {
            $embed['url'] = $n->url;
        }
        try {
            $this->http->request('POST', $cfg['webhook_url'], [
                'json' => ['embeds' => [$embed]],
                'timeout' => 15,
            ]);
            return new DeliveryResult(true);
        } catch (BadResponseException $e) {
            // Discord rate limit: surface retry_after so the job reschedules.
            if ($e->getResponse()->getStatusCode() === 429) {
                $retry = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 5);
                return new DeliveryResult(false, "rate_limited:$retry");
            }
            return new DeliveryResult(false, 'http_' . $e->getResponse()->getStatusCode());
        } catch (GuzzleException $e) {
            return new DeliveryResult(false, 'transport: ' . $e->getMessage());
        }
    }
}
