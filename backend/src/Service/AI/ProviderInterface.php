<?php

declare(strict_types=1);

namespace App\Service\AI;

interface ProviderInterface
{
    /**
     * Analyze a masked log context. Implementations must send ONLY the
     * fields present in MaskedContext — never raw log data.
     *
     * @throws ProviderException on transport errors, auth failures, or
     *         responses that fail schema validation after one repair retry.
     */
    public function analyze(MaskedContext $ctx): AnalysisResult;
}
