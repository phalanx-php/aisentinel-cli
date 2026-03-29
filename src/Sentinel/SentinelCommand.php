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

        if ($options->flag('list-presets')) {
            PersonaPreset::printAll($renderer);
            return 0;
        }

        if ($options->flag('list-personas')) {
            PersonaSelector::printAvailable($config->dossierDir, $renderer);
            return 0;
        }

        $renderer->banner();

        $projectRoot = $args->get('project') ?? $config->projectRoot;
        $personaNames = $this->resolvePersonas($options->get('preset'), $options->get('persona'), $config->dossierDir, $renderer);

        $agents = self::loadAgents($config->dossierDir, $renderer, $personaNames);

        if ($agents === []) {
            $renderer->error("No personas matched in {$config->dossierDir}");
            return 1;
        }

        $bridge = DaemonAiBridge::tryConnect($projectRoot);
        if ($bridge !== null) {
            $renderer->info("DaemonAI connected (session {$bridge->sessionId})");
        }

        $renderer->info("Custom personas: {$config->dossierDir}/");
        $renderer->watchingDirectory($projectRoot);

        $coordinator = new Coordinator($agents, $renderer, $projectRoot, $bridge);

        $renderer->ready();

        $fileChanges = ProjectWatcher::watch($projectRoot, $config->debounce);
        $humanInput = StdinReader::lines();

        $tasks = [
            'watcher' => Task::of(
                static function (ExecutionScope $s) use ($fileChanges, $coordinator, $renderer): void {
                    foreach ($fileChanges($s) as $batch) {
                        /** @var list<FileChange> $batch */
                        try {
                            $coordinator->reviewChanges($batch, $s);
                        } catch (\Throwable $e) {
                            $renderer->error($e->getMessage());
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
                }
            ),
        ];

        if ($bridge !== null) {
            $tasks['daemon'] = Task::of(
                static function (ExecutionScope $s) use ($bridge, $coordinator, $renderer): void {
                    while (!$s->isCancelled) {
                        $s->delay(2.0);

                        try {
                            $external = $bridge->readExternal();
                            foreach ($external as $msg) {
                                $from = $msg['from'] ?? $msg['agent'] ?? 'external';
                                $text = $msg['message'] ?? '';
                                if ($text === '') {
                                    continue;
                                }

                                $renderer->info("Incoming from {$from}: " . substr($text, 0, 80) . (strlen($text) > 80 ? '...' : ''));
                                $coordinator->humanMessage("[EXTERNAL from {$from}]: {$text}", $s);
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

            $renderer->agentRegistered($agent->name(), $color);

            $agents[] = $agent;
            $colorIndex++;
        }

        return $agents;
    }
}
