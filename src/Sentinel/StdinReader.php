<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Stream\ReadableResourceStream;

use function React\Async\await;

final class StdinReader
{
    public static function lines(): Emitter
    {
        return Emitter::produce(static function (Channel $ch, StreamContext $ctx): void {
            $stdin = new ReadableResourceStream(STDIN, Loop::get());
            $buffer = '';

            $stdin->on('data', static function (string $data) use ($ch, &$buffer): void {
                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line !== '') {
                        $ch->emit($line);
                    }
                }
            });

            $done = new Deferred();

            $stdin->on('end', static function () use ($done): void {
                $done->resolve(null);
            });

            $stdin->on('error', static function (\Throwable $e) use ($ch, $done): void {
                $ch->error($e);
                $done->resolve(null);
            });

            $ctx->onDispose(static function () use ($stdin): void {
                $stdin->close();
            });

            await($done->promise());
        });
    }
}