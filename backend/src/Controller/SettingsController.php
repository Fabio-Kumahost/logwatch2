<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditRepository;
use App\Repository\SettingsRepository;
use App\Service\Privacy\Masker;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SettingsController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditRepository $audit,
    ) {
    }

    /** GET returns config with key redacted; PUT saves (empty key = keep stored). */
    public function ai(Request $request, Response $response): Response
    {
        if ($request->getMethod() === 'GET') {
            $c = $this->settings->aiConfig();
            return Json::data($response, [
                'enabled' => $c['enabled'],
                'provider' => $c['provider'],
                'model' => $c['model'],
                'base_url' => $c['base_url'],
                'max_tokens' => $c['max_tokens'],
                'key_set' => $c['api_key'] !== '',   // the key itself is write-only
            ]);
        }

        $b = (array) $request->getParsedBody();
        $provider = Validator::enum($b['provider'] ?? '',
            ['openai', 'anthropic', 'openai_compatible'], '');
        if ($provider === '') {
            return Json::error($response, 422, 'validation_failed',
                'provider must be openai|anthropic|openai_compatible');
        }
        $this->settings->saveAiConfig([
            'enabled' => (bool) ($b['enabled'] ?? false),
            'provider' => $provider,
            'model' => Validator::str($b['model'] ?? '', 128),
            'base_url' => Validator::str($b['base_url'] ?? '', 512),
            'max_tokens' => Validator::int($b['max_tokens'] ?? 1024, 128, 8192, 1024),
            'api_key' => is_string($b['api_key'] ?? null) ? $b['api_key'] : '',
        ]);
        $this->audit->log($request, 'settings.ai_update', ['provider' => $provider]);
        return Json::data($response, ['saved' => true]);
    }

    /** Masking preview: paste a sample line, see exactly what the AI would receive. */
    public function maskPreview(Request $request, Response $response): Response
    {
        $sample = Validator::str(((array) $request->getParsedBody())['sample'] ?? '', 16384);
        $custom = $this->settings->get('privacy.custom_patterns', []);
        $masker = new Masker(
            partialIps: (bool) $this->settings->get('privacy.partial_ips', false),
            customPatterns: is_array($custom) ? $custom : []);
        return Json::data($response, ['masked' => $masker->mask($sample)]);
    }
}
