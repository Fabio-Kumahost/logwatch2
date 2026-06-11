<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Repository\SettingsRepository;
use App\Support\Config;
use GuzzleHttp\Client;

/**
 * Builds the configured provider. Resolution order: environment variables
 * (12-factor, immutable deployments) override panel settings (UI-managed).
 */
final class ProviderFactory
{
    private ?array $resolved = null;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->config()['enabled'] && $this->config()['provider'] !== '';
    }

    public function providerName(): string
    {
        return $this->config()['provider'];
    }

    public function modelName(): string
    {
        return $this->config()['model'];
    }

    /** @throws ProviderException when called while disabled/misconfigured */
    public function make(): ProviderInterface
    {
        $c = $this->config();
        if (!$this->enabled()) {
            throw new ProviderException('AI analysis is disabled');
        }
        $http = new Client();
        return match ($c['provider']) {
            'openai' => new OpenAIProvider($http, $c['api_key'], $c['model'], maxTokens: $c['max_tokens']),
            'anthropic' => new AnthropicProvider($http, $c['api_key'], $c['model'], $c['max_tokens']),
            'openai_compatible' => new OpenAICompatibleProvider(
                $http, $c['api_key'], $c['model'],
                $c['base_url'] ?: 'http://localhost:11434/v1', $c['max_tokens']),
            default => throw new ProviderException("unknown provider '{$c['provider']}'"),
        };
    }

    /** @return array{enabled:bool,provider:string,model:string,api_key:string,base_url:string,max_tokens:int} */
    private function config(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $db = $this->settings->aiConfig(); // decrypted, may be empty defaults
        return $this->resolved = [
            'enabled' => Config::env('AI_ENABLED') !== null
                ? Config::envBool('AI_ENABLED') : $db['enabled'],
            'provider' => Config::env('AI_PROVIDER', $db['provider']) ?? '',
            'model' => Config::env('AI_MODEL', $db['model']) ?? '',
            'api_key' => Config::env('AI_API_KEY', $db['api_key']) ?? '',
            'base_url' => Config::env('AI_BASE_URL', $db['base_url']) ?? '',
            'max_tokens' => Config::envInt('AI_MAX_TOKENS', $db['max_tokens']),
        ];
    }
}
