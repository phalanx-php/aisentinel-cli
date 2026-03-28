<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

final readonly class SentinelConfig
{
    public function __construct(
        public string $projectRoot,
        public string $dossierDir,
        public float $debounce = 0.5,
    ) {}
}
