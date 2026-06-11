<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\AI\AnalysisResult;

final class AnalysisRepository extends Repository
{
    /** Row object with an extra ->ageDays int, or null on cache miss. */
    public function findByFingerprint(string $fp): ?object
    {
        $a = $this->row('SELECT * FROM ai_analyses WHERE fingerprint = ?', [$fp]);
        if ($a !== null) {
            $created = strtotime((string) $a->created_at) ?: time();
            $a->ageDays = intdiv(time() - $created, 86400);
        }
        return $a;
    }

    public function countToday(): int
    {
        return (int) $this->scalar(
            "SELECT count(*) FROM ai_analyses WHERE created_at >= date_trunc('day', now() AT TIME ZONE 'utc')");
    }

    public function tokensThisMonth(): int
    {
        return (int) $this->scalar(
            "SELECT COALESCE(sum(tokens_used), 0) FROM ai_analyses
             WHERE created_at >= date_trunc('month', now())");
    }

    public function store(string $fingerprint, AnalysisResult $r, string $maskedInputHash,
        string $provider = '', string $model = ''): void
    {
        $this->exec(
            'INSERT INTO ai_analyses
               (fingerprint, provider, model, summary, explanation, probable_causes, impact,
                severity, urgency, solution_steps, commands, related_checks, masked_input_hash, tokens_used)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (fingerprint) DO UPDATE SET
               provider = EXCLUDED.provider, model = EXCLUDED.model,
               summary = EXCLUDED.summary, explanation = EXCLUDED.explanation,
               probable_causes = EXCLUDED.probable_causes, impact = EXCLUDED.impact,
               severity = EXCLUDED.severity, urgency = EXCLUDED.urgency,
               solution_steps = EXCLUDED.solution_steps, commands = EXCLUDED.commands,
               related_checks = EXCLUDED.related_checks,
               masked_input_hash = EXCLUDED.masked_input_hash,
               tokens_used = EXCLUDED.tokens_used, created_at = now()',
            [
                $fingerprint, $provider, $model, $r->summary, $r->explanation,
                json_encode($r->probableCauses), $r->impact, $r->severity, $r->urgency,
                json_encode($r->solutionSteps), json_encode($r->commands),
                json_encode($r->relatedChecks), $maskedInputHash, $r->tokensUsed,
            ]);
    }
}
