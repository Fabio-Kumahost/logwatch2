<?php

declare(strict_types=1);

namespace App\Service\AI;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class AnthropicProvider implements ProviderInterface
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model = 'claude-haiku-4-5',
        private readonly int $maxTokens = 1024,
    ) {
    }

    public function analyze(MaskedContext $ctx): AnalysisResult
    {
        try {
            $resp = $this->http->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'temperature' => 0.2,
                    'system' => file_get_contents(__DIR__ . '/prompt.txt'),
                    'messages' => [
                        ['role' => 'user', 'content' => OpenAIProvider::buildUserMessage($ctx)],
                        // Prefill forces the reply to start as a JSON object.
                        ['role' => 'assistant', 'content' => '{'],
                    ],
                ],
                'timeout' => 60,
            ]);
        } catch (GuzzleException $e) {
            throw new ProviderException('anthropic request failed: ' . $e->getMessage(), previous: $e);
        }

        $body = json_decode((string) $resp->getBody(), true);
        $text = $body['content'][0]['text'] ?? null;
        if (!is_string($text)) {
            throw new ProviderException('unexpected response shape from anthropic');
        }

        $tokens = (int) (($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0));
        return AnalysisResult::fromJson('{' . $text, $tokens); // re-attach the prefilled brace
    }
}
