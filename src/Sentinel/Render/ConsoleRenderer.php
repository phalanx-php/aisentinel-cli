<?php

declare(strict_types=1);

namespace Phalanx\Sentinel\Render;

use Phalanx\Sentinel\Watcher\ChangeKind;
use Phalanx\Sentinel\Watcher\FileChange;

final class ConsoleRenderer
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";
    private const ITALIC = "\033[3m";

    private const FG_WHITE = "\033[97m";
    private const FG_GRAY = "\033[90m";
    private const FG_YELLOW = "\033[33m";
    private const FG_GREEN = "\033[32m";
    private const FG_RED = "\033[31m";
    private const FG_CYAN = "\033[36m";

    private const BG_NONE = '';

    private const COLORS = [
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'red' => "\033[31m",
        'white' => "\033[97m",
    ];

    private const SEVERITY_COLORS = [
        'CRITICAL' => "\033[41m\033[97m",
        'HIGH' => "\033[31m",
        'MEDIUM' => "\033[33m",
        'LOW' => "\033[36m",
        'INFO' => "\033[90m",
    ];

    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function banner(): void
    {
        $this->writeLine('');
        $this->writeLine(self::BOLD . self::FG_CYAN . '  SENTINEL' . self::RESET . self::DIM . ' -- Phalanx Agent Watcher' . self::RESET);
        $this->writeLine(self::DIM . '  ' . str_repeat('-', 50) . self::RESET);
        $this->writeLine('');
    }

    public function agentRegistered(string $name, string $color): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $this->writeLine(self::DIM . "  + " . self::RESET . $c . $name . self::RESET . self::DIM . " registered" . self::RESET);
    }

    public function watchingDirectory(string $path): void
    {
        $this->writeLine(self::DIM . "  @ watching " . self::RESET . self::FG_WHITE . $path . self::RESET);
        $this->writeLine('');
    }

    public function ready(): void
    {
        $this->writeLine(self::FG_GREEN . "  Ready." . self::RESET . self::DIM . " Watching for changes. Type a message and press Enter to talk to agents." . self::RESET);
        $this->writeLine(self::DIM . '  ' . str_repeat('-', 50) . self::RESET);
        $this->writeLine('');
    }

    /**
     * @param list<FileChange> $changes
     */
    public function fileChanges(array $changes): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine('');
        $this->writeLine(self::DIM . "  [{$elapsed}]" . self::RESET . self::BOLD . self::FG_YELLOW . " FILE CHANGE" . self::RESET . self::DIM . " (" . count($changes) . " files)" . self::RESET);

        foreach ($changes as $change) {
            $kindColor = match ($change->kind) {
                ChangeKind::Created => self::FG_GREEN,
                ChangeKind::Modified => self::FG_YELLOW,
                ChangeKind::Deleted => self::FG_RED,
                ChangeKind::Renamed => self::FG_CYAN,
            };

            $kindLabel = match ($change->kind) {
                ChangeKind::Created => '+',
                ChangeKind::Modified => '~',
                ChangeKind::Deleted => '-',
                ChangeKind::Renamed => '>',
            };

            $this->writeLine("    " . $kindColor . $kindLabel . self::RESET . " " . self::FG_WHITE . $change->path . self::RESET);
        }

        $this->writeLine('');
    }

    public function agentFeedback(string $agentName, string $color, string $text): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $elapsed = $this->elapsed();

        $this->writeLine(self::DIM . "  [{$elapsed}] " . self::RESET . $c . self::BOLD . $agentName . self::RESET);

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $formatted = $this->highlightSeverity($line);
            $this->writeLine("    " . $formatted);
        }

        $this->writeLine('');
    }

    public function agentToken(string $agentName, string $color, string $token): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $this->write($c . $token . self::RESET);
    }

    public function agentStreamStart(string $agentName, string $color): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $elapsed = $this->elapsed();
        $this->write(self::DIM . "  [{$elapsed}] " . self::RESET . $c . self::BOLD . $agentName . self::RESET . "  ");
    }

    public function agentStreamEnd(): void
    {
        $this->writeLine('');
        $this->writeLine('');
    }

    public function humanMessage(string $message): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine('');
        $this->writeLine(self::DIM . "  [{$elapsed}]" . self::RESET . self::BOLD . self::FG_WHITE . " YOU" . self::RESET);
        $this->writeLine("    " . $message);
        $this->writeLine('');
    }

    public function reviewComplete(int $reviewNumber): void
    {
        $this->writeLine(self::DIM . "  --- review #{$reviewNumber} complete ---" . self::RESET);
        $this->writeLine('');
    }

    public function toolActivity(string $agentName, string $color, string $toolName, string $status, ?float $elapsedMs = null): void
    {
        $c = self::COLORS[$color] ?? self::FG_WHITE;
        $label = str_replace('_', ' ', $toolName);

        if ($status === 'running') {
            $this->write(self::DIM . " [{$label}]" . self::RESET);
        } else {
            $ms = $elapsedMs !== null ? number_format($elapsedMs, 1) . 'ms' : '';
            $this->write(self::DIM . " [{$label} {$ms}]" . self::RESET);
        }
    }

    public function status(): void
    {
        $elapsed = $this->elapsed();
        $this->writeLine(self::DIM . "  [{$elapsed}] Sentinel running. Agents active. Type 'exit' to stop." . self::RESET);
    }

    public function info(string $message): void
    {
        $this->writeLine(self::DIM . "  " . self::RESET . $message);
    }

    public function error(string $message): void
    {
        $this->writeLine(self::FG_RED . "  [error] " . self::RESET . $message);
    }

    public function shutdown(): void
    {
        $this->writeLine('');
        $this->writeLine(self::DIM . "  Sentinel stopped." . self::RESET);
        $this->writeLine('');
    }

    private function highlightSeverity(string $line): string
    {
        foreach (self::SEVERITY_COLORS as $label => $color) {
            if (str_contains($line, "[{$label}]")) {
                return str_replace("[{$label}]", $color . "[{$label}]" . self::RESET, $line);
            }
        }

        return $line;
    }

    private function elapsed(): string
    {
        $seconds = microtime(true) - $this->startTime;

        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $minutes = (int) ($seconds / 60);
        $remaining = $seconds - ($minutes * 60);

        return sprintf('%dm%02ds', $minutes, $remaining);
    }

    private function writeLine(string $text): void
    {
        fwrite(STDOUT, $text . "\n");
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }
}