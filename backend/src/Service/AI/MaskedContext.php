<?php

declare(strict_types=1);

namespace App\Service\AI;

/** Value object: everything in here has already passed the Masker. */
final readonly class MaskedContext
{
    public function __construct(
        public string $maskedLine,
        /** @var list<string> */
        public array $maskedContextLines,
        public string $service,
        public string $sourceBasename,
        public string $osFamily,
        public int $occurrenceCount,
        public int $affectedServers,
    ) {
    }
}
