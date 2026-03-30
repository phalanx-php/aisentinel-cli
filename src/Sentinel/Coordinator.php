<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\AgentResult;
use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Message\Conversation;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Sentinel\Agent\ReviewAgent;
use Phalanx\Sentinel\Render\ReviewRenderer;
use Phalanx\Sentinel\Watcher\ChangeKind;
use Phalanx\Sentinel\Watcher\FileChange;
use Phalanx\Task\Task;

final class Coordinator
{
    /** @var array<string, string> */
    private array $lastRoundFeedback = [];

    private int $reviewCount = 0;

    private bool $busy = false;

    private readonly string $projectContext;

    /**
     * @param list<ReviewAgent> $agents
     */
    public function __construct(
        private readonly array $agents,
        private readonly ReviewRenderer $renderer,
        private readonly string $projectRoot,
        private readonly ?DaemonAiBridge $bridge = null,
    ) {
        $this->projectContext = self::buildProjectContext($projectRoot);
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function externalMessage(string $from, string $text, ExecutionScope $scope): void
    {
        $this->busy = true;
        try {
            $this->renderer->externalMessage($from, $text);

            $enriched = "[EXTERNAL from {$from}]: {$text}";
            $tasks = $this->buildResponseTasks($enriched);
            $results = $scope->concurrent($tasks);

            foreach ($results as $run) {
                $feedback = trim($run->text);
                if ($feedback !== '') {
                    $this->renderer->agentFeedback($run->glyph, $run->color, $feedback);
                }
            }
        } finally {
            $this->busy = false;
        }
    }

    public function reviewChanges(array $changes, ExecutionScope $scope): void
    {
        $this->busy = true;
        $startTime = hrtime(true);
        try {
            $this->reviewCount++;
            $this->renderer->fileChanges($changes);

            $changeSummary = self::formatChangeSummary($changes, $this->projectRoot);
            $tasks = $this->buildReviewTasks($changeSummary);

            /** @var array<string, AgentRunResult> $results */
            $results = $scope->concurrent($tasks);

            $triggerSummary = count($changes) . ' file(s) changed';
            $totalUsage = TokenUsage::zero();

            foreach ($results as $agentName => $run) {
                $text = trim($run->text);
                $totalUsage = $totalUsage->add($run->usage);

                if ($text === '') {
                    continue;
                }

                $this->renderer->agentFeedback($run->glyph, $run->color, $text);
                $this->lastRoundFeedback[$agentName] = $text;
                $this->bridge?->broadcast($agentName, $text, $triggerSummary);
            }

            $elapsed = (hrtime(true) - $startTime) / 1e9;
            $this->renderer->reviewComplete($this->reviewCount, $elapsed, $totalUsage->total);
        } finally {
            $this->busy = false;
        }
    }

    public function humanMessage(string $message, ExecutionScope $scope): void
    {
        $this->busy = true;
        $startTime = hrtime(true);
        try {
            $this->renderer->humanMessage($message);

            $enriched = $this->enrichWithFileContents($message);
            $tasks = $this->buildResponseTasks($enriched);
            $results = $scope->concurrent($tasks);

            $this->reviewCount++;
            $totalUsage = TokenUsage::zero();

            foreach ($results as $run) {
                $text = trim($run->text);
                $totalUsage = $totalUsage->add($run->usage);

                if ($text === '') {
                    continue;
                }

                $this->renderer->agentFeedback($run->glyph, $run->color, $text);
            }

            $elapsed = (hrtime(true) - $startTime) / 1e9;
            $this->renderer->reviewComplete($this->reviewCount, $elapsed, $totalUsage->total);
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @return array<string, Task>
     */
    private function buildReviewTasks(string $changeSummary): array
    {
        $tasks = [];

        foreach ($this->agents as $agent) {
            $agentName = $agent->name();
            $agentGlyph = $agent->glyph();
            $agentColor = $agent->color();
            $conversation = $this->conversationFor($agent);
            $peerContext = $this->buildPeerContext($agentName);

            $prompt = $peerContext !== ''
                ? $changeSummary . "\n\n--- Other agents' recent feedback (avoid repeating) ---\n" . $peerContext
                : $changeSummary;

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($prompt))
                ->maxSteps(3);

            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentGlyph, $agentColor, $projectRoot): AgentRunResult {
                    $child = $child->withAttribute('sentinel.project_root', $projectRoot);
                    return self::executeAndCollect($turn, $child, $agentName, $agentGlyph, $agentColor);
                }
            );
        }

        return $tasks;
    }

    /**
     * @return array<string, Task>
     */
    private function buildResponseTasks(string $prompt, int $maxSteps = 5): array
    {
        $tasks = [];
        $contextualPrompt = $this->projectContext . "\n\n" . $prompt;

        foreach ($this->agents as $agent) {
            $agentName = $agent->name();
            $agentGlyph = $agent->glyph();
            $agentColor = $agent->color();
            $conversation = $this->conversationFor($agent);

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($contextualPrompt))
                ->maxSteps($maxSteps);

            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentGlyph, $agentColor, $projectRoot): AgentRunResult {
                    $child = $child->withAttribute('sentinel.project_root', $projectRoot);
                    return self::executeAndCollect($turn, $child, $agentName, $agentGlyph, $agentColor);
                }
            );
        }

        return $tasks;
    }

    private static function executeAndCollect(
        Turn $turn,
        ExecutionScope $scope,
        string $agentName,
        string $agentGlyph,
        string $agentColor,
    ): AgentRunResult {
        $events = AgentLoop::run($turn, $scope);
        $tokenBuffer = '';
        $conversation = null;
        $usage = TokenUsage::zero();

        foreach ($events($scope) as $event) {
            if (!$event instanceof AgentEvent) {
                continue;
            }

            match ($event->kind) {
                AgentEventKind::TokenDelta => $tokenBuffer .= $event->data->text,
                AgentEventKind::StepComplete => $tokenBuffer .= ($tokenBuffer !== '' ? "\n\n" : ''),
                AgentEventKind::AgentComplete => (static function () use ($event, &$conversation, &$usage): void {
                    if ($event->data instanceof AgentResult) {
                        $conversation = $event->data->conversation;
                    }
                    $usage = $event->usageSoFar;
                })(),
                default => null,
            };
        }

        return new AgentRunResult($agentName, $agentGlyph, $agentColor, $tokenBuffer, $conversation, $usage);
    }

    private function conversationFor(ReviewAgent $agent): Conversation
    {
        return Conversation::create()->system($agent->instructions);
    }

    /**
     * @param list<FileChange> $changes
     */
    private static function formatChangeSummary(array $changes, string $projectRoot): string
    {
        $lines = ["File changes detected (" . count($changes) . " files):\n"];

        foreach ($changes as $change) {
            $lines[] = "  {$change->summary()}";

            if ($change->diff !== null) {
                $diffLines = explode("\n", $change->diff);
                $truncated = count($diffLines) > 80
                    ? [...array_slice($diffLines, 0, 80), '... (truncated)']
                    : $diffLines;

                $lines[] = "  ```diff";
                foreach ($truncated as $dl) {
                    $lines[] = "  {$dl}";
                }
                $lines[] = "  ```";
            }

            if ($change->kind !== ChangeKind::Deleted) {
                $fullPath = rtrim($projectRoot, '/') . '/' . ltrim($change->path, '/');
                if (is_readable($fullPath) && filesize($fullPath) <= 50_000) {
                    $ext = pathinfo($change->path, PATHINFO_EXTENSION);
                    $content = file_get_contents($fullPath);
                    $lines[] = "  Full file contents:";
                    $lines[] = "  ```{$ext}";
                    $lines[] = $content;
                    $lines[] = "  ```";
                }
            }
        }

        return implode("\n", $lines);
    }

    private function buildPeerContext(string $excludeAgent): string
    {
        $parts = [];
        foreach ($this->lastRoundFeedback as $name => $feedback) {
            if ($name === $excludeAgent) {
                continue;
            }
            $parts[] = "[{$name}]: {$feedback}";
        }

        if ($this->bridge !== null) {
            $external = $this->bridge->readExternal();
            foreach ($external as $msg) {
                $agent = $msg['agent'] ?? 'unknown';
                $session = $msg['session'] ?? '';
                $text = $msg['message'] ?? '';
                if ($text !== '') {
                    $parts[] = "[{$agent} (session {$session})]: {$text}";
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function enrichWithFileContents(string $message): string
    {
        $patterns = [];

        if (preg_match_all('/\b([\w\/.-]+\.(?:php|ts|tsx|js|json|yaml|yml|neon))\b/', $message, $matches)) {
            $patterns = array_unique($matches[1]);
        }

        if ($patterns === []) {
            return $message;
        }

        $found = [];
        foreach ($patterns as $pattern) {
            $filename = basename($pattern);
            $results = [];
            exec(
                sprintf('find %s -name %s -not -path "*/vendor/*" -not -path "*/.git/*" 2>/dev/null',
                    escapeshellarg($this->projectRoot),
                    escapeshellarg($filename),
                ),
                $results,
            );

            foreach ($results as $fullPath) {
                if (!is_readable($fullPath) || filesize($fullPath) > 50_000) {
                    continue;
                }

                $relative = str_replace(rtrim($this->projectRoot, '/') . '/', '', $fullPath);
                $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
                $content = file_get_contents($fullPath);
                $found[$relative] = "File: {$relative}\n```{$ext}\n{$content}\n```";
                break;
            }
        }

        if ($found === []) {
            return $message;
        }

        return $message . "\n\n--- Referenced files ---\n" . implode("\n\n", $found);
    }

    private static function buildProjectContext(string $projectRoot): string
    {
        $lines = ["Project: {$projectRoot}"];
        $lines[] = "Source directories (use with read_file / list_directory):";

        $srcDirs = glob($projectRoot . '/packages/*/src');
        if ($srcDirs !== false && $srcDirs !== []) {
            sort($srcDirs);
            foreach ($srcDirs as $dir) {
                $relative = str_replace(rtrim($projectRoot, '/') . '/', '', $dir);
                $lines[] = "  {$relative}/";
            }
        }

        $rootSrc = $projectRoot . '/src';
        if (is_dir($rootSrc)) {
            $lines[] = "  src/";
        }

        return implode("\n", $lines);
    }
}