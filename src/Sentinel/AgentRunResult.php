<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

final readonly class AgentRunResult
{
    public function __construct(
        public string $name,
        public string $color,
        public string $text,
    ) {}
}
