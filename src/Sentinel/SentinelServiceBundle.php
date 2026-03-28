<?php

declare(strict_types=1);

namespace Phalanx\Sentinel;

use Phalanx\Ai\Provider\ProviderConfig;
use Phalanx\Sentinel\Render\ConsoleRenderer;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class SentinelServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(ProviderConfig::class)
            ->factory(static function () use ($context): ProviderConfig {
                $config = ProviderConfig::create();

                $anthropicKey = $context['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: null;
                if ($anthropicKey !== null) {
                    $model = $context['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
                    $config->anthropic(apiKey: $anthropicKey, model: $model);
                }

                $openaiKey = $context['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: null;
                if ($openaiKey !== null) {
                    $config->openai(apiKey: $openaiKey);
                }

                return $config;
            });

        $services->config(SentinelConfig::class, static function (array $ctx): SentinelConfig {
            $projectRoot = $ctx['SENTINEL_PROJECT_ROOT']
                ?? getenv('SENTINEL_PROJECT_ROOT')
                ?: getcwd();

            $dossierDir = $ctx['SENTINEL_DOSSIER_DIR']
                ?? getenv('SENTINEL_DOSSIER_DIR')
                ?: dirname(__DIR__, 2) . '/personas';

            $debounce = (float) ($ctx['SENTINEL_DEBOUNCE']
                ?? getenv('SENTINEL_DEBOUNCE')
                ?: 0.5);

            return new SentinelConfig(
                projectRoot: rtrim($projectRoot, '/'),
                dossierDir: rtrim($dossierDir, '/'),
                debounce: $debounce,
            );
        });

        $services->singleton(ConsoleRenderer::class);
    }
}
