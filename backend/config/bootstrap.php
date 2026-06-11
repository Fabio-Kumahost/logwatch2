<?php

declare(strict_types=1);

/** Shared CLI bootstrap (bin/console, bin/worker.php): env + container. */

use DI\ContainerBuilder;

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2));
$dotenv->safeLoad();

$builder = new ContainerBuilder();
$builder->addDefinitions(__DIR__ . '/container.php');

return $builder->build();
