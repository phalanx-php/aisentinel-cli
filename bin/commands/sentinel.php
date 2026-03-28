<?php

declare(strict_types=1);

use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Sentinel\SentinelCommand;

return CommandGroup::of([
    "sentinel" => new Command(
        fn: new SentinelCommand(),
        config: static fn($c) => $c
            ->withDescription("Watch a project directory and review changes with expert AI agents")
            ->withArgument('project', 'Path to the project directory to watch', required: false)
            ->withOption('preset', 'p', 'Persona preset (php, react-native, tv, core, full)', requiresValue: true)
            ->withOption('persona', '', 'Comma-separated persona names to load', requiresValue: true)
            ->withOption('list-presets', 'l', 'List available presets')
            ->withOption('list-personas', '', 'List all available personas'),
    ),
]);
