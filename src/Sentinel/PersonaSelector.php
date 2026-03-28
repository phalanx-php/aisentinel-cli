<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Sentinel\Render\ConsoleRenderer;

final class PersonaSelector
{
    /**
     * @return list<string> Selected persona filenames (without .md)
     */
    public static function interactive(string $dossierDir, ConsoleRenderer $renderer): array
    {
        $available = self::scanPersonas($dossierDir);

        if ($available === []) {
            return [];
        }

        $renderer->info('Available personas:');

        $index = 1;
        $indexMap = [];
        foreach ($available as $filename => $displayName) {
            $renderer->info("  {$index}) {$displayName}");
            $indexMap[$index] = $filename;
            $index++;
        }

        $renderer->info('');

        $presetHints = [];
        foreach (PersonaPreset::all() as $presetName => $presetFiles) {
            $nums = [];
            foreach ($presetFiles as $file) {
                $num = array_search($file, array_values(array_keys($available)), true);
                if ($num !== false) {
                    $nums[] = $num + 1;
                }
            }
            if ($nums !== []) {
                $presetHints[] = "{$presetName} (" . implode(',', $nums) . ')';
            }
        }

        $renderer->info('Presets: ' . implode(' | ', $presetHints));
        $renderer->info('');

        fwrite(STDOUT, "  Select personas [numbers, preset name, or Enter for full]: ");
        $line = trim((string) fgets(STDIN));

        if ($line === '') {
            return array_keys($available);
        }

        $preset = PersonaPreset::get($line);
        if ($preset !== null) {
            return $preset;
        }

        if (preg_match('/^[\d,\s]+$/', $line)) {
            $nums = array_map(intval(...), preg_split('/[\s,]+/', $line));
            $selected = [];
            foreach ($nums as $n) {
                if (isset($indexMap[$n])) {
                    $selected[] = $indexMap[$n];
                }
            }
            return $selected;
        }

        $renderer->error("Unknown selection: {$line}. Using full preset.");
        return array_keys($available);
    }

    public static function printAvailable(string $dossierDir, ConsoleRenderer $renderer): void
    {
        $available = self::scanPersonas($dossierDir);

        $renderer->info('Available personas:');
        foreach ($available as $filename => $displayName) {
            $renderer->info("  {$filename}  --  {$displayName}");
        }

        $renderer->info('');
        $renderer->info("Add custom personas to: {$dossierDir}/");
    }

    /**
     * @return array<string, string> filename (without .md) => display name from # header
     */
    private static function scanPersonas(string $dossierDir): array
    {
        $files = glob(rtrim($dossierDir, '/') . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $personas = [];
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $content = file_get_contents($file);
            $name = preg_match('/^#\s+(.+)$/m', $content, $m) ? trim($m[1]) : ucfirst($filename);
            $personas[$filename] = $name;
        }

        return $personas;
    }
}
