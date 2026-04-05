<?php

declare(strict_types=1);

use Phalanx\Console\Arg;
use Phalanx\Console\Command;
use Phalanx\Console\CommandGroup;
use Phalanx\Console\Opt;
use Phalanx\Sentinel\SentinelTuiCommand;

return CommandGroup::of([
    "sentinel-tui" => new Command(
        fn: new SentinelTuiCommand(),
        desc: "Watch a project with expert AI agents (terminal UI mode)",
        args: [
            Arg::optional('project', 'Path to the project directory to watch'),
        ],
        opts: [
            Opt::value('preset', 'p', 'Persona preset (php, react-native, tv, core, full)'),
            Opt::value('persona', '', 'Comma-separated persona names to load'),
            Opt::flag('list-presets', 'l', 'List available presets'),
            Opt::flag('list-personas', '', 'List all available personas'),
        ],
    ),
]);
