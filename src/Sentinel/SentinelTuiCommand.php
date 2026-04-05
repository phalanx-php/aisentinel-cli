<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Console\CommandScope;
use Phalanx\ExecutionScope;
use Phalanx\Sentinel\Agent\Dossier;
use Phalanx\Sentinel\Agent\ReviewAgent;
use Phalanx\Sentinel\Render\ConsoleRenderer;
use Phalanx\Sentinel\Render\ReviewRenderer;
use Phalanx\Sentinel\Render\TuiRenderer;
use Phalanx\Sentinel\Watcher\FileChange;
use Phalanx\Sentinel\Watcher\ProjectWatcher;
use Phalanx\Task\Executable;
use Phalanx\Task\Task;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Input\Key;
use Phalanx\Terminal\Input\KeyEvent;
use Phalanx\Terminal\Layout\Constraint;
use Phalanx\Terminal\Layout\Layout;
use Phalanx\Terminal\Region\Region;
use Phalanx\Terminal\Region\RegionConfig;
use Phalanx\Terminal\Style\Palette;
use Phalanx\Terminal\Style\Style;
use Phalanx\Terminal\Surface\ScreenMode;
use Phalanx\Terminal\Surface\Surface;
use Phalanx\Terminal\Surface\SurfaceConfig;
use Phalanx\Terminal\Terminal\Terminal;
use Phalanx\Terminal\Terminal\TerminalConfig;
use Phalanx\Terminal\Widget\Box;
use Phalanx\Terminal\Widget\BoxStyle;
use Phalanx\Terminal\Widget\InputLine;
use Phalanx\Terminal\Widget\ScrollableText;
use Phalanx\Terminal\Widget\StatusBar;
use Phalanx\Terminal\Widget\Text\Span;

final class SentinelTuiCommand implements Executable
{
    private const AGENT_COLORS = ['blue', 'magenta', 'cyan', 'green', 'yellow', 'red'];

    public function __invoke(ExecutionScope $scope): int
    {
        assert($scope instanceof CommandScope);

        $config = $scope->service(SentinelConfig::class);
        $options = $scope->options;
        $args = $scope->args;

        // Persona selection happens in cooked mode BEFORE Surface takes over STDIN.
        // ANTI-DEADLOCK: interactive selection uses fgets(STDIN) which requires
        // cooked mode. Surface::start() switches to raw mode. Order is non-negotiable.
        $consoleRenderer = $scope->service(ConsoleRenderer::class);

        if ($options->flag('list-presets')) {
            PersonaPreset::printAll($consoleRenderer);
            return 0;
        }

        if ($options->flag('list-personas')) {
            PersonaSelector::printAvailable($config->dossierDir, $consoleRenderer);
            return 0;
        }

        $projectRoot = $args->get('project') ?? $config->projectRoot;
        $personaNames = self::resolvePersonas(
            $options->get('preset'),
            $options->get('persona'),
            $config->dossierDir,
            $consoleRenderer,
        );

        $agents = self::loadAgents($config->dossierDir, $personaNames);

        if ($agents === []) {
            $consoleRenderer->error("No personas matched in {$config->dossierDir}");
            return 1;
        }

        $bridge = Daemon8Bridge::tryConnect($scope, $projectRoot);

        // Boot Surface — all terminal detection from $context, never getenv()
        $termConfig = Terminal::detect([
            'COLUMNS' => $scope->attribute('COLUMNS'),
            'LINES' => $scope->attribute('LINES'),
            'COLORTERM' => $scope->attribute('COLORTERM'),
            'TERM' => $scope->attribute('TERM'),
            'NO_COLOR' => $scope->attribute('NO_COLOR'),
            'CI' => $scope->attribute('CI'),
        ]);

        $surfaceConfig = new SurfaceConfig($termConfig, mode: ScreenMode::Alternate);
        $surface = new Surface($surfaceConfig);

        // Create widgets
        $statusBar = new StatusBar(Style::new()->bg('blue')->fg('bright-white'));
        $inputLine = new InputLine(prompt: '> ', style: Palette::muted());

        // Create TuiRenderer and register agent panels
        $tuiRenderer = new TuiRenderer($surface, $statusBar);

        /** @var array<string, ScrollableText> $agentPanels */
        $agentPanels = [];
        foreach ($agents as $agent) {
            $panel = new ScrollableText();
            $agentPanels[$agent->name()] = $panel;
            $tuiRenderer->registerAgentPanel($agent->name(), $agent->color(), $panel);
        }

        // Create regions — status bar + agent grid + input
        self::createLayout($surface, $statusBar, $inputLine, $agents, $agentPanels, $termConfig);

        // Create coordinator with TUI renderer
        $coordinator = new Coordinator($agents, $tuiRenderer, $projectRoot, $bridge);

        // Display boot info
        $tuiRenderer->banner();
        foreach ($agents as $agent) {
            $tuiRenderer->agentRegistered($agent->glyph(), $agent->color());
        }
        if ($bridge !== null) {
            $tuiRenderer->info("daemon8 connected (session {$bridge->sessionId})");
        }
        $tuiRenderer->watchingDirectory($projectRoot);
        $tuiRenderer->ready();

        // Wire draw callback — renders widgets into regions each frame
        $surface->onDraw(static function (Surface $s) use ($statusBar, $inputLine, $agents, $agentPanels): void {
            $statusRegion = $s->getRegion('status');
            if ($statusRegion !== null && $statusRegion->isDirty) {
                $statusRegion->draw($statusBar);
            }

            foreach ($agents as $agent) {
                $regionName = "agent-{$agent->name()}";
                $region = $s->getRegion($regionName);
                $panel = $agentPanels[$agent->name()] ?? null;
                if ($region !== null && $panel !== null && $region->isDirty) {
                    $box = new Box($panel, BoxStyle::Rounded, $agent->glyph(), Style::new()->fg($agent->color()));
                    $region->draw($box);
                }
            }

            $inputRegion = $s->getRegion('input');
            if ($inputRegion !== null && $inputRegion->isDirty) {
                $inputBox = new Box($inputLine, BoxStyle::Single, null, Palette::muted());
                $inputRegion->draw($inputBox);
            }
        });

        // Wire input handling
        // ANTI-DEADLOCK: KeyEvent handlers run on the event loop callback, not in a fiber.
        // InputLine::handleKey() is pure state mutation — no suspension, no blocking.
        // If Enter submits text, we wrap coordinator->humanMessage() in async() because
        // it calls scope->concurrent() which requires a fiber context.
        $surface->onMessage(KeyEvent::class, static function (mixed $msg, Surface $s) use ($inputLine, $coordinator, $scope, $tuiRenderer): void {
            if (!$msg instanceof KeyEvent) {
                return;
            }

            // Ctrl+C exits
            if ($msg->ctrl && $msg->is('c')) {
                $s->stop();
                return;
            }

            $submitted = $inputLine->handleKey($msg);
            $s->getRegion('input')?->invalidate();

            if ($submitted === null || $submitted === '') {
                return;
            }

            if ($submitted === 'exit' || $submitted === 'quit') {
                $s->stop();
                return;
            }

            if ($coordinator->isBusy()) {
                $tuiRenderer->info('Agents are busy, please wait...');
                return;
            }

            // Don't call tuiRenderer->humanMessage() here — Coordinator.humanMessage()
            // calls renderer->humanMessage() internally. Calling both causes duplicates.

            // ANTI-DEADLOCK: humanMessage() calls scope->concurrent() which suspends
            // the fiber. But this callback runs on the event loop, not in a fiber.
            // async() creates a new fiber for the concurrent operation.
            \React\Async\async(static function () use ($coordinator, $submitted, $scope, $tuiRenderer): void {
                try {
                    $coordinator->humanMessage($submitted, $scope);
                } catch (\Throwable $e) {
                    $tuiRenderer->error('Agent error: ' . $e->getMessage());
                    @file_put_contents('/tmp/sentinel-tui-error.log', $e . "\n\n", FILE_APPEND);
                }
            })();
        });

        // Wire resize handler
        $surface->onResize(static function (int $w, int $h) use ($surface, $statusBar, $inputLine, $agents, $agentPanels): void {
            $newTermConfig = new TerminalConfig($w, $h);
            self::recreateLayout($surface, $agents, $newTermConfig);
        });

        // ANTI-DEADLOCK: Surface.start() registers the render timer and STDIN reader
        // on the event loop BEFORE scope->concurrent() blocks the current fiber.
        // If started after, the timer/reader callbacks would never be registered
        // because the fiber is suspended waiting for tasks that need the timer.
        $surface->start();

        $fileChanges = ProjectWatcher::watch($projectRoot, $config->debounce);

        $tasks = [
            'watcher' => Task::of(
                static function (ExecutionScope $s) use ($fileChanges, $coordinator, $tuiRenderer): void {
                    foreach ($fileChanges($s) as $batch) {
                        /** @var list<FileChange> $batch */
                        try {
                            $coordinator->reviewChanges($batch, $s);
                        } catch (\Throwable $e) {
                            $tuiRenderer->error($e->getMessage());
                        }
                    }
                }
            ),
        ];

        // Daemon bridge: display external messages as info, don't re-process.
        // Re-routing external messages through humanMessage() creates an echo
        // chamber when both sentinel and sentinel-tui watch the same project —
        // each instance's agent responses broadcast back, causing infinite loops.
        if ($bridge !== null) {
            $tasks['daemon'] = Task::of(
                static function (ExecutionScope $s) use ($bridge, $tuiRenderer): void {
                    while (!$s->isCancelled) {
                        $s->delay(3.0);

                        try {
                            $external = $bridge->readExternal();
                            foreach ($external as $msg) {
                                $from = $msg['from'] ?? $msg['agent'] ?? 'external';
                                $text = $msg['message'] ?? '';
                                if ($text === '') {
                                    continue;
                                }

                                $tuiRenderer->info("[{$from}]: " . substr($text, 0, 120));
                            }
                        } catch (\Throwable $e) {
                            $tuiRenderer->error('Daemon poll: ' . $e->getMessage());
                        }
                    }
                }
            );
        }

        // ANTI-DEADLOCK: Surface.stop() MUST run even if concurrent() throws.
        // Raw mode left enabled corrupts the terminal. try/finally guarantees cleanup.
        // Surface also registers its own shutdown_function and signal handlers internally
        // as a second safety layer.
        try {
            $scope->concurrent($tasks);
        } finally {
            $surface->stop();
        }

        return 0;
    }

    private static function createLayout(
        Surface $surface,
        StatusBar $statusBar,
        InputLine $inputLine,
        array $agents,
        array $agentPanels,
        TerminalConfig $termConfig,
    ): void {
        $w = $termConfig->width;
        $h = $termConfig->height;

        // Vertical: status(1) | pad(1) | agents(fill) | input(3)
        $vRects = Layout::vertical(
            Rect::sized($w, $h),
            Constraint::length(1),
            Constraint::length(1),
            Constraint::fill(),
            Constraint::length(3),
        );

        $surface->region('status', $vRects[0], new RegionConfig(tickRate: 10.0));
        // $vRects[1] is a padding row (no region, just empty space)
        $surface->region('input', $vRects[3]);

        // Agent grid within the middle rect (index 2 after padding)
        self::createAgentGrid($surface, $agents, $vRects[2]);
    }

    private static function recreateLayout(Surface $surface, array $agents, TerminalConfig $termConfig): void
    {
        $w = $termConfig->width;
        $h = $termConfig->height;

        $vRects = Layout::vertical(
            Rect::sized($w, $h),
            Constraint::length(1),
            Constraint::length(1),
            Constraint::fill(),
            Constraint::length(3),
        );

        $surface->getRegion('status')?->resize($vRects[0]);
        $surface->getRegion('input')?->resize($vRects[3]);

        self::resizeAgentGrid($surface, $agents, $vRects[2]);
    }

    private static function createAgentGrid(Surface $surface, array $agents, Rect $area): void
    {
        $rects = self::calculateAgentRects(count($agents), $area);

        foreach ($agents as $i => $agent) {
            if (isset($rects[$i])) {
                $surface->region("agent-{$agent->name()}", $rects[$i]);
            }
        }
    }

    private static function resizeAgentGrid(Surface $surface, array $agents, Rect $area): void
    {
        $rects = self::calculateAgentRects(count($agents), $area);

        foreach ($agents as $i => $agent) {
            if (isset($rects[$i])) {
                $surface->getRegion("agent-{$agent->name()}")?->resize($rects[$i]);
            }
        }
    }

    /** @return list<Rect> */
    private static function calculateAgentRects(int $count, Rect $area): array
    {
        if ($count === 0) {
            return [];
        }

        [$cols, $rows] = match (true) {
            $count <= 1 => [1, 1],
            $count <= 2 => [2, 1],
            $count <= 4 => [2, 2],
            $count <= 6 => [3, 2],
            default => [3, 3],
        };

        $rowConstraints = array_fill(0, $rows, Constraint::fill());
        $rowRects = Layout::vertical($area, ...$rowConstraints);

        $rects = [];
        $agentIndex = 0;

        foreach ($rowRects as $rowRect) {
            $agentsInRow = min($cols, $count - $agentIndex);
            $colConstraints = array_fill(0, $agentsInRow, Constraint::fill());
            $colRects = Layout::horizontal($rowRect, ...$colConstraints);

            foreach ($colRects as $colRect) {
                if ($agentIndex < $count) {
                    $rects[] = $colRect;
                    $agentIndex++;
                }
            }
        }

        return $rects;
    }

    /** @return list<string>|null */
    private static function resolvePersonas(?string $preset, ?string $personaCsv, string $dossierDir, ConsoleRenderer $renderer): ?array
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
     * @param list<string>|null $filter
     * @return list<ReviewAgent>
     */
    private static function loadAgents(string $dossierDir, ?array $filter): array
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
            $agents[] = new ReviewAgent($dossier);
            $colorIndex++;
        }

        return $agents;
    }
}
