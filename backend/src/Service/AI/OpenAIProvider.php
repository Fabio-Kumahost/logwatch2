<?php

declare(strict_types=1);

namespace App\Service\AI;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OpenAI Chat Completions. Also the base for OpenAICompatibleProvider —
 * Ollama, vLLM, LM Studio, Groq etc. speak the same wire format.
 */
class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        protected readonly ClientInterface $http,
        protected readonly string $apiKey,
        protected readonly string $model,
        protected readonly string $baseUrl = 'https://api.openai.com/v1',
        protected readonly int $maxTokens = 1024,
    ) {
    }

    public function analyze(MaskedContext $ctx): AnalysisResult
    {
        $userContent = self::buildUserMessage($ctx);

        // One repair retry: if the first response fails schema validation,
        // ask once to fix the JSON, then give up.
        $lastError = null;
        $messages = [
            ['role' => 'system', 'content' => file_get_contents(__DIR__ . '/prompt.txt')],
            ['role' => 'user', 'content' => $userContent],
        ];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            [$content, $tokens] = $this->complete($messages);
            try {
                return AnalysisResult::fromJson($content, $tokens);
            } catch (ProviderException $e) {
                $lastError = $e;
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' =>
                    'Your response violated the schema: ' . $e->getMessage() .
                    '. Reply again with ONLY the corrected JSON object.'];
            }
        }
        throw new ProviderException('schema validation failed twice: ' . $lastError->getMessage());
    }

    /** @return array{0:string,1:int} [content, total tokens] */
    protected function complete(array $messages): array
    {
        try {
            $resp = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => array_filter([
                    'Authorization' => $this->apiKey !== '' ? 'Bearer ' . $this->apiKey : null,
                    'Content-Type' => 'application/json',
                ]),
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => $this->maxTokens,
                    'response_format' => ['type' => 'json_object'],
                ],
                'timeout' => 60,
            ]);
        } catch (GuzzleException $e) {
            throw new ProviderException('openai request failed: ' . $e->getMessage(), previous: $e);
        }

        $body = json_decode((string) $resp->getBody(), true);
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new ProviderException('unexpected response shape from provider');
        }
        return [$content, (int) ($body['usage']['total_tokens'] ?? 0)];
    }

    public static function buildUserMessage(MaskedContext $ctx): string
    {
        $context = $ctx->maskedContextLines === []
            ? '(none)' : implode("\n", $ctx->maskedContextLines);
        return <<<TXT
            Service: {$ctx->service}
            Log file: {$ctx->sourceBasename}
            OS family: {$ctx->osFamily}
            Occurrences: {$ctx->occurrenceCount} (across {$ctx->affectedServers} server(s))

            Error line:
            {$ctx->maskedLine}

            Surrounding lines:
            {$context}
            TXT;
    }
}
