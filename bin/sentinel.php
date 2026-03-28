#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

use Phalanx\Application;
use Phalanx\Console\ConsoleRunner;
use Phalanx\Sentinel\SentinelServiceBundle;

return static function (array $context): ConsoleRunner {
    $app = Application::starting($context)
        ->providers(new SentinelServiceBundle())
        ->compile();

    return ConsoleRunner::withCommands($app, __DIR__ . '/commands');
};
