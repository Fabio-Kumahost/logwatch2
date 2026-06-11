<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Crypto;
use PDO;

final class SettingsRepository extends Repository
{
    public function __construct(PDO $pdo, private readonly Crypto $crypto)
    {
        parent::__construct($pdo);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->row('SELECT value, is_encrypted FROM settings WHERE key = ?', [$key]);
        if ($row === null) {
            return $default;
        }
        $raw = $row->is_encrypted ? $this->crypto->unseal($row->value) : $row->value;
        return json_decode($raw, true);
    }

    public function set(string $key, mixed $value, bool $encrypted = false): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        $stored = $encrypted ? $this->crypto->seal($json) : $json;
        $this->exec(
            'INSERT INTO settings (key, value, is_encrypted) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value,
               is_encrypted = EXCLUDED.is_encrypted, updated_at = now()',
            [$key, $stored, $encrypted ? 'true' : 'false']);
    }

    /** @return array{enabled:bool,provider:string,model:string,api_key:string,base_url:string,max_tokens:int} */
    public function aiConfig(): array
    {
        $public = $this->get('ai.config', []);
        $key = $this->get('ai.api_key', '');
        return [
            'enabled' => (bool) ($public['enabled'] ?? false),
            'provider' => (string) ($public['provider'] ?? 'openai'),
            'model' => (string) ($public['model'] ?? 'gpt-4o-mini'),
            'base_url' => (string) ($public['base_url'] ?? ''),
            'max_tokens' => (int) ($public['max_tokens'] ?? 1024),
            'api_key' => is_string($key) ? $key : '',
        ];
    }

    /** api_key is write-only: empty string means "keep the stored one". */
    public function saveAiConfig(array $c): void
    {
        $this->set('ai.config', [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'provider' => (string) ($c['provider'] ?? 'openai'),
            'model' => (string) ($c['model'] ?? ''),
            'base_url' => (string) ($c['base_url'] ?? ''),
            'max_tokens' => (int) ($c['max_tokens'] ?? 1024),
        ]);
        if (($c['api_key'] ?? '') !== '') {
            $this->set('ai.api_key', (string) $c['api_key'], encrypted: true);
        }
    }
}
