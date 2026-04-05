<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Daemon8\Daemon;
use Phalanx\Suspendable;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;

final class Daemon8Bridge
{
    private int $checkpoint = 0;

    public readonly string $sessionId;

    private readonly Browser $browser;

    private readonly string $baseUrl;

    public function __construct(
        private readonly Suspendable $scope,
        private readonly string $projectPath,
        string $baseUrl = 'http://127.0.0.1:9077',
    ) {
        $this->sessionId = substr(bin2hex(random_bytes(4)), 0, 8);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->browser = (new Browser())
            ->withTimeout(3.0)
            ->withRejectErrorResponse(false);

        /** @var ResponseInterface $response */
        $response = $this->scope->await($this->browser->get($this->baseUrl . '/api/observe?limit=0'));

        if ($response->getStatusCode() < 400) {
            $data = json_decode((string) $response->getBody(), true);
            $this->checkpoint = $data['checkpoint'] ?? 0;
        }
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
        $url = $this->baseUrl . '/api/observe?' . http_build_query([
            'kinds' => 'custom',
            'since' => $this->checkpoint,
        ]);

        /** @var ResponseInterface $response */
        $response = $this->scope->await($this->browser->get($url));

        if ($response->getStatusCode() >= 400) {
            return [];
        }

        $result = json_decode((string) $response->getBody(), true);

        $this->checkpoint = $result['checkpoint'] ?? $this->checkpoint;

        $ownApp = 'sentinel-' . $this->sessionId;
        $external = [];

        foreach ($result['observations'] as $obs) {
            if (($obs['origin']['name'] ?? '') === $ownApp) {
                continue;
            }
            if (($obs['kind']['channel'] ?? '') !== 'sentinel-review') {
                continue;
            }
            if (($obs['data']['session'] ?? '') === $this->sessionId) {
                continue;
            }

            $external[] = $obs['data'];
        }

        return $external;
    }

    public static function tryConnect(
        Suspendable $scope,
        string $projectPath,
        string $baseUrl = 'http://127.0.0.1:9077',
    ): ?self {
        try {
            $browser = (new Browser())->withTimeout(2.0);
            $response = $scope->await($browser->get($baseUrl . '/health'));

            if ((string) $response->getBody() !== 'ok') {
                return null;
            }

            return new self($scope, $projectPath, $baseUrl);
        } catch (\Throwable) {
            return null;
        }
    }
}
