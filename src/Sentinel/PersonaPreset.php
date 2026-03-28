<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Sentinel\Render\ConsoleRenderer;

final class PersonaPreset
{
    /** @var array<string, list<string>> preset name => persona filenames (without .md) */
    private const PRESETS = [
        'php' => ['architect', 'performance', 'security', 'phalanx-expert'],
        'react-native' => ['architect', 'react-state-data-integrity', 'performance', 'security'],
        'tv' => ['tv-navigation-focus', 'vega-platform-streaming', 'react-state-data-integrity', 'performance'],
        'core' => ['architect', 'security', 'performance'],
        'full' => ['architect', 'performance', 'phalanx-expert', 'react-state-data-integrity', 'security', 'tv-navigation-focus', 'vega-platform-streaming'],
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
