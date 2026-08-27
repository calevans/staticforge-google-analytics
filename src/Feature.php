<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use Calevans\StaticForgeGoogleAnalytics\Models\AnalyticsSettings;
use Calevans\StaticForgeGoogleAnalytics\Services\GoogleAnalyticsService;
use EICC\Utils\Container;
use EICC\Utils\Log;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'GoogleAnalytics';
    protected Log $logger;
    private GoogleAnalyticsService $service;
    private bool $settingsResolved = false;
    private ?AnalyticsSettings $settings = null;

    public function __construct(Container $container, Log $logger, GoogleAnalyticsService $service)
    {
        $this->container = $container;
        $this->logger = $logger;
        $this->service = $service;
    }

    public function getRequiredConfig(): array
    {
        return [
            'google_analytics.enabled',
        ];
    }

    public function getRequiredEnv(): array
    {
        return [];
    }

    public function getConfigHelp(string $key): ?string
    {
        if ($key === 'google_analytics.enabled') {
            return <<<YAML
google_analytics:
  enabled: true                  # bool.   Default false (absent == off)
  tracking_id: G-XXXXXXXXXX      # string. Default null. Overridden by GOOGLE_ANALYTICS_ID
  debug: false                   # bool.   Default false
  exclude:                       # list of globs. Default []
    - drafts/*
YAML;
        }

        return null;
    }

    public function register(EventManager $eventManager): void
    {
        parent::register($eventManager);
        $this->logger->log('INFO', 'GoogleAnalytics Feature registered');
    }

    #[EventListener('POST_RENDER', priority: 500)]
    public function handlePostRender(RenderEvent $event): void
    {
        if ($event->renderedContent === null || $event->renderedContent === '') {
            return;
        }

        $settings = $this->resolveSettings();
        if ($settings === null) {
            return;
        }

        $outputPath = $event->outputPath ?? '';
        if (strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'html') {
            return;
        }

        $outputDir = $this->container->getVariable('OUTPUT_DIR');
        if ($this->service->isExcluded($outputPath, is_string($outputDir) ? $outputDir : null, $settings)) {
            $this->logger->log('DEBUG', "GoogleAnalytics: excluded {$outputPath}");
            return;
        }

        $event->renderedContent = $this->service->injectAnalytics($event->renderedContent, $settings);
        $this->logger->log('DEBUG', "GoogleAnalytics: injected into {$outputPath}");
    }

    private function resolveSettings(): ?AnalyticsSettings
    {
        if ($this->settingsResolved) {
            return $this->settings;
        }

        $siteConfig = $this->container->getVariable('site_config');
        $gaConfig = is_array($siteConfig) && is_array($siteConfig['google_analytics'] ?? null)
            ? $siteConfig['google_analytics']
            : [];

        $envId = $this->container->getVariable('GOOGLE_ANALYTICS_ID');
        $envEnabled = $this->container->getVariable('GOOGLE_ANALYTICS_ENABLED');

        $this->settings = $this->service->resolveSettings(
            $gaConfig,
            is_string($envId) ? $envId : null,
            is_string($envEnabled) ? $envEnabled : null,
        );
        $this->settingsResolved = true;

        return $this->settings;
    }
}
