<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Same wire format as OpenAI, custom endpoint — covers Ollama, vLLM,
 * LM Studio, Groq, Mistral and friends.
 */
final class OpenAICompatibleProvider extends OpenAIProvider
{
}
