<?php

declare(strict_types=1);

namespace Phalanx\Sentinel\Agent;

use InvalidArgumentException;

final readonly class Dossier
{
    public function __construct(
        public string $name,
        public string $instructions,
        public string $color,
    ) {}

    public static function fromFile(string $path, string $color): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException("Dossier not found: {$path}");
        }

        $content = file_get_contents($path);
        $name = self::extractName($path, $content);

        return new self($name, trim($content), $color);
    }

    private static function extractName(string $path, string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return ucfirst(pathinfo($path, PATHINFO_FILENAME));
    }
}
