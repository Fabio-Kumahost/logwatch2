<?php

declare(strict_types=1);

namespace App\Service\Notify;

final readonly class Notification
{
    public function __construct(
        public string $title,        // severity emoji + AI summary (or first log line)
        public string $body,         // server, service, level, counts, first/last seen
        public int $severity,        // 1..5 → colors / priorities
        public string $url,          // deep link to the error/server detail page
    ) {
    }

    public static function capReached(): self
    {
        return new self('🔇 Notifications muted',
            'Hourly cap reached for this channel — see the panel for details.', 2, '');
    }

    public function payloadHash(): string
    {
        return hash('sha256', $this->title . "\0" . $this->body);
    }
}
