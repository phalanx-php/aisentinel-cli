<?php

declare(strict_types=1);

namespace Phalanx\Sentinel\Agent;

use Phalanx\Ai\AgentDefinition;
use Phalanx\Ai\AgentLoop;
use Phalanx\Ai\Turn;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\ExecutionScope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;

final class ReviewAgent implements AgentDefinition, Retryable, HasTimeout
{
    public function __construct(
        private readonly Dossier $dossier,
    ) {}

    public string $instructions {
        get => $this->dossier->instructions . "\n\n" .
            "You are one of several expert agents reviewing code changes in real time. " .
            "Other experts may also comment. Avoid repeating what another agent has already said " .
            "if you can see their feedback in the conversation. " .
            "Keep responses concise -- 1-4 sentences per issue found. " .
            "If another agent's observation intersects your expertise, you may build on it briefly.";
    }

    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(2);
    }

    public float $timeout {
        get => 20.0;
    }

    public function tools(): array
    {
        return [
            ReadFile::class,
            ListDirectory::class,
        ];
    }

    public function provider(): ?string
    {
        return null;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }

    public function name(): string
    {
        return $this->dossier->name;
    }

    public function color(): string
    {
        return $this->dossier->color;
    }
}
