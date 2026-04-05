<?php

declare(strict_types=1);

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\Opt;
use Phalanx\Sentinel\SentinelCommand;

return CommandGroup::of([
    "sentinel" => new Command(
        fn: new SentinelCommand(),
        desc: "Watch a project directory and review changes with expert AI agents",
        args: [
            Arg::optional('project', 'Path to the project directory to watch (default: current directory)'),
        ],
        opts: [
            Opt::value('preset', 'p', 'Persona preset: php, react-native, tv, core, full'),
            Opt::value('persona', '', 'Comma-separated persona names (e.g. architect,security)'),
            Opt::flag('list-presets', 'l', 'List available presets and their personas'),
            Opt::flag('list-personas', '', 'List all available persona files'),
            Opt::flag('help', 'h', 'Show usage examples and interactive commands'),
        ],
    ),
]);
