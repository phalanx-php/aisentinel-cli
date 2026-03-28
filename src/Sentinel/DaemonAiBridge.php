<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use DaemonAI\Daemon;

final class DaemonAiBridge
{
    private int $checkpoint = 0;

    public readonly string $sessionId;

    public function __construct(
        private readonly string $projectPath,
    ) {
        $this->sessionId = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->checkpoint = Daemon::observe(limit: 1)['checkpoint'];
    }

    public function broadcast(string $agentName, string $feedback, string $trigger): void
    {
        Daemon::sendUdp(
            data: [
                'message' => $feedback,
                'agent' => $agentName,
                'trigger' => $trigger,
                'project' => $this->projectPath,
                'session' => $this->sessionId,
            ],
            severity: 'info',
            kind: 'custom',
            channel: 'sentinel-review',
            app: 'sentinel-' . $this->sessionId,
        );
    }

    /** @return list<array{agent: string, message: string, session: string, project: string}> */
    public function readExternal(): array
    {
        $result = Daemon::observe(
            kinds: ['custom'],
            since: $this->checkpoint,
        );

        $this->checkpoint = $result['checkpoint'];

        $ownApp = 'sentinel-' . $this->sessionId;
        $external = [];

        foreach ($result['observations'] as $obs) {
            if (($obs['origin']['name'] ?? '') === $ownApp) {
                continue;
            }
            if (($obs['kind']['channel'] ?? '') !== 'sentinel-review') {
                continue;
            }

            $external[] = $obs['data'];
        }

        return $external;
    }

    public static function tryConnect(string $projectPath): ?self
    {
        $health = @file_get_contents('http://127.0.0.1:9077/health');
        if ($health !== 'ok') {
            return null;
        }

        return new self($projectPath);
    }
}
