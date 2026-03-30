<?php

declare(strict_types=1);

namespace Phalanx\Sentinel\Input;

final class PasteEvent implements InputEvent
{
    public function __construct(
        public private(set) string $content,
    ) {}
}
