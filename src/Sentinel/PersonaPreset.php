<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Sentinel\Render\ConsoleRenderer;

final class PersonaPreset
{
    /** @var array<string, list<string>> preset name => persona filenames (without .md) */
    private const PRESETS = [
        'php' => ['arch', 'perf', 'sec', 'phx'],
        'react-native' => ['arch', 'state', 'perf', 'sec'],
        'tv' => ['nav', 'stream', 'state', 'perf'],
        'core' => ['arch', 'sec', 'perf'],
        'full' => ['arch', 'perf', 'phx', 'state', 'sec', 'nav', 'stream'],
    ];

    /** @return list<string>|null */
    public static function get(string $name): ?array
    {
        return self::PRESETS[$name] ?? null;
    }

    /** @return array<string, list<string>> */
    public static function all(): array
    {
        return self::PRESETS;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PRESETS);
    }

    public static function printAll(ConsoleRenderer $renderer): void
    {
        $renderer->info('Available presets:');

        foreach (self::PRESETS as $name => $personas) {
            $list = implode(', ', $personas);
            $renderer->info("  {$name}  --  {$list}");
        }
    }
}
