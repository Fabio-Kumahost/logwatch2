<?php

declare(strict_types=1);

namespace App\Service\Notify;

final readonly class DeliveryResult
{
    public function __construct(public bool $ok, public string $reason = '')
    {
    }
}
