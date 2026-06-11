<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Repository\AnalysisRepository;
use App\Repository\ErrorGroupRepository;
use App\Service\Privacy\Masker;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates one analysis: cache check → budget check → mask → provider →
 * validate → store. Invoked by the worker for 'ai.analyze' jobs.
 */
final class AiAnalyzer
{
    public function __construct(
        private readonly AnalysisRepository $analyses,
        private readonly ErrorGroupRepository $groups,
        private readonly ProviderFactory $providers,
        private readonly Masker $masker,
        private readonly LoggerInterface $log,
        private readonly int $dailyBudget,
        private readonly int $reanalyzeAfterDays = 30,
    ) {
    }

    /** @return string one of: cached | analyzed | budget_exhausted | skipped */
    public function analyzeFingerprint(string $fingerprint, bool $force = false): string
    {
        if (!$this->providers->enabled()) {
            return 'skipped';
        }
        $existing = $this->analyses->findByFingerprint($fingerprint);
        if ($existing !== null && !$force
            && $existing->ageDays < $this->reanalyzeAfterDays) {
            return 'cached'; // the whole point: no second API call for known errors
        }

        if ($this->analyses->countToday() >= $this->dailyBudget) {
            $this->log->warning('AI daily budget exhausted, parking analysis', [
                'fingerprint' => $fingerprint,
            ]);
            return 'budget_exhausted';
        }

        $group = $this->groups->findByFingerprint($fingerprint);
        if ($group === null) {
            return 'skipped';
        }
        $sample = $this->groups->representativeEntry($group->id); // raw line + context lines

        $ctx = new MaskedContext(
            maskedLine: $this->masker->mask($sample->raw),
            maskedContextLines: array_map($this->masker->mask(...), $sample->contextLines),
            service: $group->service,
            sourceBasename: basename($group->sourceClass), // full paths may leak usernames
            osFamily: $sample->osFamily,
            occurrenceCount: $group->occurrenceCount,
            affectedServers: count($group->serverIds),
        );

        $result = $this->providers->make()->analyze($ctx);

        $this->analyses->store($fingerprint, $result, Masker::auditHash($ctx->maskedLine),
            $this->providers->providerName(), $this->providers->modelName());

        // AI may raise severity above the regex baseline (never silently lower it).
        if ($result->severity >= 4 && $group->level !== 'critical') {
            $this->groups->flagSeverityRaised($group->id, $result->severity);
        }

        return 'analyzed';
    }
}
