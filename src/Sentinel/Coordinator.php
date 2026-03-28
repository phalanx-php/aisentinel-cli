<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\AgentResult;
use Phalanx\Ai\Event\AgentEvent;
use Phalanx\Ai\Event\AgentEventKind;
use Phalanx\Ai\Message\Conversation;
use Phalanx\Ai\Message\Message;
use Phalanx\Ai\Turn;
use Phalanx\ExecutionScope;
use Phalanx\Sentinel\Agent\ReviewAgent;
use Phalanx\Sentinel\Render\ConsoleRenderer;
use Phalanx\Sentinel\Watcher\FileChange;
use Phalanx\Task\Task;

final class Coordinator
{
    /** @var array<string, Conversation> */
    private array $conversations = [];

    /** @var array<string, string> */
    private array $lastRoundFeedback = [];

    private int $reviewCount = 0;

    /**
     * @param list<ReviewAgent> $agents
     */
    public function __construct(
        private readonly array $agents,
        private readonly ConsoleRenderer $renderer,
        private readonly string $projectRoot,
        private readonly ?DaemonAiBridge $bridge = null,
    ) {}

    /**
     * @param list<FileChange> $changes
     */
    public function reviewChanges(array $changes, ExecutionScope $scope): void
    {
        $this->reviewCount++;
        $this->renderer->fileChanges($changes);

        $changeSummary = self::formatChangeSummary($changes);
        $tasks = $this->buildReviewTasks($changeSummary);

        /** @var array<string, AgentRunResult> $results */
        $results = $scope->concurrent($tasks);

        $triggerSummary = count($changes) . ' file(s) changed';

        foreach ($results as $agentName => $run) {
            $text = trim($run->text);

            if ($text === '') {
                continue;
            }

            $this->lastRoundFeedback[$agentName] = $text;
            $this->appendToConversation($agentName, $changeSummary, $text);
            $this->bridge?->broadcast($agentName, $text, $triggerSummary);
        }

        $this->renderer->reviewComplete($this->reviewCount);
    }

    public function humanMessage(string $message, ExecutionScope $scope): void
    {
        $this->renderer->humanMessage($message);

        $prompt = "[HUMAN SUPERVISOR]: {$message}";
        $tasks = $this->buildResponseTasks($prompt, maxSteps: 2);

        $results = $scope->concurrent($tasks);

        foreach ($results as $agentName => $run) {
            $text = trim($run->text);

            if ($text === '') {
                continue;
            }

            $this->appendToConversation($agentName, $prompt, $text);
            $this->bridge?->broadcast($agentName, $text, 'human: ' . $message);
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

            $renderer = $this->renderer;
            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentColor, $renderer, $projectRoot): AgentRunResult {
                    $child = $child->withAttribute('sentinel.project_root', $projectRoot);
                    return self::executeAndStream($turn, $child, $agentName, $agentColor, $renderer);
                }
            );
        }

        return $tasks;
    }

    /**
     * @return array<string, Task>
     */
    private function buildResponseTasks(string $prompt, int $maxSteps = 3): array
    {
        $tasks = [];

        foreach ($this->agents as $agent) {
            $agentName = $agent->name();
            $agentColor = $agent->color();
            $conversation = $this->conversationFor($agent);

            $turn = Turn::begin($agent)
                ->conversation($conversation)
                ->message(Message::user($prompt))
                ->maxSteps($maxSteps);

            $renderer = $this->renderer;
            $projectRoot = $this->projectRoot;

            $tasks[$agentName] = Task::of(
                static function (ExecutionScope $child) use ($turn, $agentName, $agentColor, $renderer, $projectRoot): AgentRunResult {
                    $child = $child->withAttribute('sentinel.project_root', $projectRoot);
                    return self::executeAndStream($turn, $child, $agentName, $agentColor, $renderer);
                }
            );
        }

        return $tasks;
    }

    private static function executeAndStream(
        Turn $turn,
        ExecutionScope $scope,
        string $agentName,
        string $agentColor,
        ConsoleRenderer $renderer,
    ): AgentRunResult {
        $events = AgentLoop::run($turn, $scope);

        $renderer->agentStreamStart($agentName, $agentColor);

        $tokenBuffer = '';

        foreach ($events($scope) as $event) {
            if (!$event instanceof AgentEvent) {
                continue;
            }

            match ($event->kind) {
                AgentEventKind::TokenDelta => (static function () use ($event, &$tokenBuffer, $renderer, $agentColor, $agentName): void {
                    $text = $event->data->text;
                    $tokenBuffer .= $text;
                    $renderer->agentToken($agentName, $agentColor, $text);
                })(),

                AgentEventKind::ToolCallStart => $renderer->toolActivity(
                    $agentName,
                    $agentColor,
                    $event->data->toolName,
                    'running',
                ),

                AgentEventKind::ToolCallComplete => $renderer->toolActivity(
                    $agentName,
                    $agentColor,
                    $event->data->toolName,
                    'done',
                    $event->elapsed,
                ),

                default => null,
            };
        }

        $renderer->agentStreamEnd();

        return new AgentRunResult($agentName, $agentColor, $tokenBuffer);
    }

    private function conversationFor(ReviewAgent $agent): Conversation
    {
        return $this->conversations[$agent->name()]
            ?? Conversation::create()->system($agent->instructions);
    }

    private function appendToConversation(string $agentName, string $userMessage, string $assistantMessage): void
    {
        $agent = $this->findAgent($agentName);
        $existing = $this->conversationFor($agent);

        $this->conversations[$agentName] = $existing
            ->user($userMessage)
            ->assistant($assistantMessage);
    }

    /**
     * @param list<FileChange> $changes
     */
    private static function formatChangeSummary(array $changes): string
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

    private function findAgent(string $name): ReviewAgent
    {
        foreach ($this->agents as $agent) {
            if ($agent->name() === $name) {
                return $agent;
            }
        }

        return $this->agents[0];
    }
}