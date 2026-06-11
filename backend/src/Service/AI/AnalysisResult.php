<?php

declare(strict_types=1);

namespace App\Service\AI;

/** Validated, structured analysis — mirrors the table ai_analyses. */
final readonly class AnalysisResult
{
    public function __construct(
        public string $summary,
        public string $explanation,
        /** @var list<string> */
        public array $probableCauses,
        public string $impact,
        public int $severity,        // 1..5
        public string $urgency,      // low|medium|high|immediate
        /** @var list<string> */
        public array $solutionSteps,
        /** @var list<array{description:string,command:string}> */
        public array $commands,
        /** @var list<string> */
        public array $relatedChecks,
        public int $tokensUsed,
    ) {
    }

    /** @throws ProviderException when the decoded JSON violates the schema */
    public static function fromJson(string $json, int $tokensUsed): self
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            throw new ProviderException('response is not valid JSON');
        }
        foreach (['summary', 'explanation', 'impact', 'urgency'] as $k) {
            if (!is_string($d[$k] ?? null) || $d[$k] === '') {
                throw new ProviderException("missing or empty field: $k");
            }
        }
        $severity = $d['severity'] ?? null;
        if (!is_int($severity) || $severity < 1 || $severity > 5) {
            throw new ProviderException('severity must be an integer 1..5');
        }
        if (!in_array($d['urgency'], ['low', 'medium', 'high', 'immediate'], true)) {
            throw new ProviderException('invalid urgency value');
        }

        $strList = static fn (mixed $v): array =>
            array_values(array_filter(is_array($v) ? $v : [], 'is_string'));

        $commands = [];
        foreach (is_array($d['commands'] ?? null) ? $d['commands'] : [] as $c) {
            if (is_array($c) && is_string($c['command'] ?? null)) {
                if (CommandSafety::isDestructive($c['command'])) {
                    continue; // defense in depth: drop rm -rf / dd / mkfs etc.
                }
                $commands[] = [
                    'description' => is_string($c['description'] ?? null) ? $c['description'] : '',
                    'command' => $c['command'],
                ];
            }
        }

        return new self(
            summary: $d['summary'],
            explanation: $d['explanation'],
            probableCauses: $strList($d['probable_causes'] ?? []),
            impact: $d['impact'],
            severity: $severity,
            urgency: $d['urgency'],
            solutionSteps: $strList($d['solution_steps'] ?? []),
            commands: $commands,
            relatedChecks: $strList($d['related_checks'] ?? []),
            tokensUsed: $tokensUsed,
        );
    }
}
