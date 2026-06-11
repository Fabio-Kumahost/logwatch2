<?php

declare(strict_types=1);

use App\Controller\IngestController;
use App\Service\AI\AiAnalyzer;
use App\Service\Notify\DiscordChannel;
use App\Service\Notify\GotifyChannel;
use App\Service\Notify\Notifier;
use App\Service\Privacy\Masker;
use App\Support\Config;
use App\Support\Crypto;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

return [
    PDO::class => static function (): PDO {
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s',
            Config::env('DB_HOST', 'db'),
            Config::env('DB_PORT', '5432'),
            Config::env('DB_NAME', 'logwatch2'));
        return new PDO($dsn, Config::env('DB_USER', 'logwatch2'), Config::env('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
    },

    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('logwatch2');
        $level = Config::env('APP_ENV') === 'development' ? Level::Debug : Level::Info;
        $logger->pushHandler(new StreamHandler('php://stderr', $level)); // docker logs
        return $logger;
    },

    Crypto::class => static fn (): Crypto => new Crypto(Config::env('APP_KEY') ?? ''),

    ClientInterface::class => static fn (): ClientInterface => new Client(),

    Environment::class => static function (): Environment {
        $twig = new Environment(
            new FilesystemLoader(__DIR__ . '/../templates'),
            [
                'autoescape' => 'html', // XSS: log content is hostile input
                'cache' => Config::env('APP_ENV') === 'development'
                    ? false : sys_get_temp_dir() . '/twig-cache',
                'strict_variables' => false,
            ],
        );
        return $twig;
    },

    Masker::class => static function (ContainerInterface $c): Masker {
        $settings = $c->get(App\Repository\SettingsRepository::class);
        $custom = $settings->get('privacy.custom_patterns', []);
        return new Masker(
            partialIps: (bool) $settings->get('privacy.partial_ips', false),
            customPatterns: is_array($custom) ? $custom : [],
        );
    },

    Notifier::class => static fn (ContainerInterface $c): Notifier => new Notifier(
        $c->get(App\Repository\NotificationRepository::class),
        [
            'discord' => $c->get(DiscordChannel::class),
            'gotify' => $c->get(GotifyChannel::class),
        ],
        $c->get(LoggerInterface::class),
    ),

    AiAnalyzer::class => static fn (ContainerInterface $c): AiAnalyzer => new AiAnalyzer(
        $c->get(App\Repository\AnalysisRepository::class),
        $c->get(App\Repository\ErrorGroupRepository::class),
        $c->get(App\Service\AI\ProviderFactory::class),
        $c->get(Masker::class),
        $c->get(LoggerInterface::class),
        dailyBudget: Config::envInt('AI_DAILY_BUDGET_REQUESTS', 500),
        reanalyzeAfterDays: Config::envInt('AI_REANALYZE_AFTER_DAYS', 30),
    ),

    IngestController::class => static fn (ContainerInterface $c): IngestController => new IngestController(
        $c->get(App\Repository\LogRepository::class),
        $c->get(App\Repository\ServerRepository::class),
        $c->get(App\Service\Ingest\LevelClassifier::class),
        $c->get(App\Service\Ingest\Fingerprinter::class),
        $c->get(App\Service\Queue\Queue::class),
        maxBatch: Config::envInt('INGEST_MAX_BATCH', 500),
    ),
];
