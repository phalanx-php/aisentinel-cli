<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Console\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Sentinel\Agent\Dossier;
use Phalanx\Sentinel\Agent\ReviewAgent;
use Phalanx\Sentinel\Render\ConsoleRenderer;
use Phalanx\Sentinel\Watcher\FileChange;
use Phalanx\Sentinel\Watcher\ProjectWatcher;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;

final readonly class SentinelCommand implements Executable
{
    private const AGENT_COLORS = ['blue', 'magenta', 'cyan', 'green', 'yellow', 'red'];

    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandScope);

        $config = $scope->service(SentinelConfig::class);
        $renderer = $scope->service(ConsoleRenderer::class);
        $options = $scope->options;
        $args = $scope->args;

        if ($options->flag('help')) {
            self::printHelp($renderer);
            return 0;
        }

        if ($options->flag('list-presets')) {
            PersonaPreset::printAll($renderer);
            return 0;
        }

        if ($options->flag('list-personas')) {
            PersonaSelector::printAvailable($config->dossierDir, $renderer);
            return 0;
        }

        $renderer->banner();

        $projectRoot = realpath($args->get('project') ?? $config->projectRoot) ?: ($args->get('project') ?? $config->projectRoot);
        $personaNames = $this->resolvePersonas($options->get('preset'), $options->get('persona'), $config->dossierDir, $renderer);

        $agents = self::loadAgents($config->dossierDir, $renderer, $personaNames);

        if ($agents === []) {
            $renderer->error("No personas matched in {$config->dossierDir}");
            return 1;
        }

        $bridge = Daemon8Bridge::tryConnect($scope, $projectRoot);
        if ($bridge !== null) {
            $renderer->info("daemon8 connected (session {$bridge->sessionId})");
        }

        $renderer->info("Custom personas: {$config->dossierDir}/");
        $renderer->watchingDirectory($projectRoot);

        $coordinator = new Coordinator($agents, $renderer, $projectRoot, $bridge);

        $renderer->ready();

        $fileChanges = ProjectWatcher::watch($projectRoot, $config->debounce);
        $humanInput = RawInputReader::lines();

        $tasks = [
            'watcher' => Task::of(
                static function (ExecutionScope $s) use ($fileChanges, $coordinator, $renderer): void {
                    foreach ($fileChanges($s) as $batch) {
                        /** @var list<FileChange> $batch */
                        try {
                            $coordinator->reviewChanges($batch, $s);
                        } catch (\Throwable $e) {
                            $renderer->error($e->getMessage());
                            @file_put_contents('/tmp/sentinel-trace.log', $e . "\n\n", FILE_APPEND);
                        }
                    }
                }
            ),

            'stdin' => Task::of(
                static function (ExecutionScope $s) use ($humanInput, $coordinator, $renderer): void {
                    foreach ($humanInput($s) as $line) {
                        if ($line === 'exit' || $line === 'quit') {
                            $s->dispose();
                            return;
                        }

                        if ($line === 'status') {
                            $renderer->status();
                            continue;
                        }

                        try {
                            $coordinator->humanMessage($line, $s);
                        } catch (\Throwable $e) {
                            $renderer->error($e->getMessage());
                        }
                    }

                    $s->dispose();
                }
            ),
        ];

        if ($bridge !== null) {
            $tasks['daemon'] = Task::of(
                static function (ExecutionScope $s) use ($bridge, $coordinator, $renderer): void {
                    while (!$s->isCancelled) {
                        $s->delay(3.0);

                        if ($coordinator->isBusy()) {
                            continue;
                        }

                        try {
                            $external = $bridge->readExternal();
                            foreach ($external as $msg) {
                                $from = $msg['from'] ?? $msg['agent'] ?? 'external';
                                $text = $msg['message'] ?? '';
                                if ($text === '') {
                                    continue;
                                }

                                $renderer->info("External ({$from}): " . substr($text, 0, 80) . (strlen($text) > 80 ? '...' : ''));
                            }
                        } catch (\Throwable $e) {
                            $renderer->error('Daemon poll: ' . $e->getMessage());
                        }
                    }
                }
            );
        }

        $scope->concurrent($tasks);

        $renderer->shutdown();

        return 0;
    }

    /**
     * @return list<string>|null persona filenames to load, null for all
     */
    private function resolvePersonas(?string $preset, ?string $personaCsv, string $dossierDir, ConsoleRenderer $renderer): ?array
    {
        if ($preset !== null) {
            $names = PersonaPreset::get($preset);
            if ($names === null) {
                $renderer->error("Unknown preset: {$preset}");
                PersonaPreset::printAll($renderer);
                return PersonaPreset::get('full');
            }
            return $names;
        }

        if ($personaCsv !== null) {
            return array_map('trim', explode(',', $personaCsv));
        }

        return PersonaSelector::interactive($dossierDir, $renderer);
    }

    /**
     * @param list<string>|null $filter persona filenames (without .md), null for all
     * @return list<ReviewAgent>
     */
    private static function loadAgents(string $dossierDir, ConsoleRenderer $renderer, ?array $filter): array
    {
        $agents = [];
        $colorIndex = 0;

        $files = glob(rtrim($dossierDir, '/') . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);

            if ($filter !== null && !in_array($filename, $filter, true)) {
                continue;
            }

            $color = self::AGENT_COLORS[$colorIndex % count(self::AGENT_COLORS)];
            $dossier = Dossier::fromFile($file, $color);
            $agent = new ReviewAgent($dossier);

            $renderer->agentRegistered($agent->glyph(), $color);

            $agents[] = $agent;
            $colorIndex++;
        }

        return $agents;
    }

    private static function printHelp(ConsoleRenderer $renderer): void
    {
        $renderer->banner();

        echo <<<'HELP'
Usage:
  php bin/sentinel.php [project] [options]

Examples:
  php bin/sentinel.php                              Watch cwd, pick personas interactively
  php bin/sentinel.php ~/Code/myapp --preset php    Watch myapp with PHP-focused reviewers
  php bin/sentinel.php . --preset core              Architect + security + performance agents
  php bin/sentinel.php . --persona architect,security
                                                    Cherry-pick specific personas
  php bin/sentinel.php --list-presets               Show preset groups and their personas
  php bin/sentinel.php --list-personas              Show all persona files in dossier dir

Options:
  -p, --preset=<name>    Persona preset: php, react-native, tv, core, full
  --persona=<names>      Comma-separated persona names (e.g. architect,security)
  -l, --list-presets     List available presets and their personas
  --list-personas        List all available persona files
  -h, --help             Show this help

Interactive commands (during a session):
  status                 Show active agents and review stats
  exit / quit            Stop watching and shut down
  <any text>             Send a message to all agents as supervisor input

Environment (.env):
  ANTHROPIC_API_KEY      Claude API key (required)
  ANTHROPIC_MODEL        Model name (default: claude-haiku-4-5-20251001)
  SENTINEL_DEBOUNCE      Change debounce in seconds (default: 0.5)
  SENTINEL_DOSSIER_DIR   Persona directory (default: personas/)

HELP;
    }
}
