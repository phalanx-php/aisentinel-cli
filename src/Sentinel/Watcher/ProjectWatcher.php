<?php

declare(strict_types=1);

namespace Phalanx\Sentinel\Watcher;

use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;

final class ProjectWatcher
{
    private const IGNORE_PATTERNS = [
        'vendor', 'node_modules', '.git', '.idea', '.vscode',
        'storage', 'cache', '.php-cs-fixer.cache',
    ];

    public static function watch(string $projectRoot, float $debounceSeconds = 0.5): Emitter
    {
        return Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($projectRoot, $debounceSeconds): void {
            $excludes = array_map(
                static fn(string $p) => sprintf('--exclude=%s', escapeshellarg($p)),
                self::IGNORE_PATTERNS,
            );

            $cmd = sprintf(
                'fswatch -r -x --event Created --event Updated --event Removed --event Renamed %s %s',
                implode(' ', $excludes),
                escapeshellarg($projectRoot),
            );

            $process = new Process($cmd);
            $process->start(Loop::get());

            $ctx->onDispose(static function () use ($process): void {
                if ($process->isRunning()) {
                    $process->terminate(\SIGTERM);
                }
            });

            $pending = [];
            $timer = null;
            $lineBuffer = '';

            $flush = static function () use (&$pending, &$timer, $ch, $projectRoot): void {
                if ($timer !== null) {
                    Loop::cancelTimer($timer);
                    $timer = null;
                }

                if ($pending === []) {
                    return;
                }

                $batch = $pending;
                $pending = [];

                $changes = [];
                foreach ($batch as $entry) {
                    $relative = str_replace(rtrim($projectRoot, '/') . '/', '', $entry['path']);
                    $change = new FileChange(
                        path: $relative,
                        kind: $entry['kind'],
                        timestamp: microtime(true),
                        diff: self::computeDiff($entry['path'], $entry['kind']),
                    );

                    if ($change->isCode()) {
                        $changes[] = $change;
                    }
                }

                if ($changes !== []) {
                    $ch->emit($changes);
                }
            };

            $process->stdout->on('data', static function (string $data) use (&$pending, &$timer, &$lineBuffer, $flush, $debounceSeconds): void {
                $lineBuffer .= $data;
                $lines = explode("\n", $lineBuffer);
                $lineBuffer = array_pop($lines);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    // fswatch -x output: "/path/to/file Created Updated ..."
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) < 2) {
                        continue;
                    }

                    $path = $parts[0];
                    $flags = array_slice($parts, 1);
                    $kind = self::parseKind($flags);

                    if ($kind === null) {
                        continue;
                    }

                    $pending[] = ['path' => $path, 'kind' => $kind];
                }

                if ($timer !== null) {
                    Loop::cancelTimer($timer);
                }

                $timer = Loop::addTimer($debounceSeconds, $flush);
            });

            $done = new Deferred();

            $process->on('exit', static function () use ($done, $flush): void {
                $flush();
                $done->resolve(null);
            });

            await($done->promise());
        });
    }

    /**
     * @param list<string> $flags
     */
    private static function parseKind(array $flags): ?ChangeKind
    {
        if (in_array('Created', $flags, true)) {
            return ChangeKind::Created;
        }

        if (in_array('Updated', $flags, true)) {
            return ChangeKind::Modified;
        }

        if (in_array('Removed', $flags, true)) {
            return ChangeKind::Deleted;
        }

        if (in_array('Renamed', $flags, true)) {
            return ChangeKind::Renamed;
        }

        return null;
    }

    private static function computeDiff(string $path, ChangeKind $kind): ?string
    {
        if ($kind === ChangeKind::Deleted || !file_exists($path)) {
            return null;
        }

        $size = @filesize($path);
        if ($size === false || $size > 50_000) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        exec(sprintf('git diff --no-color -- %s 2>/dev/null', escapeshellarg($path)), $output, $exitCode);

        $diff = implode("\n", $output);

        return $diff !== '' ? $diff : null;
    }
}
