<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Ai\Event\TokenUsage;
use Phalanx\Ai\Message\Conversation;

final readonly class AgentRunResult
{
    public function __construct(
        public string $name,
        public string $glyph,
        public string $color,
        public string $text,
        public ?Conversation $conversation = null,
        public TokenUsage $usage = new TokenUsage(),
    ) {}
}
