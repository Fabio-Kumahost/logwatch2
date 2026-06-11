<?php

declare(strict_types=1);

namespace App\Service\Notify;

interface ChannelInterface
{
    public function send(object $channel, Notification $n): DeliveryResult;
}
