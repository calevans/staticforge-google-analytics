<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\ConfigurableFeatureInterface;
use EICC\StaticForge\Core\Events\EventListener;
use EICC\StaticForge\Core\Events\RenderEvent;
use Calevans\StaticForgeGoogleAnalytics\Services\GoogleAnalyticsService;
use EICC\Utils\Container;
use EICC\Utils\Log;

class Feature extends BaseFeature implements FeatureInterface, ConfigurableFeatureInterface
{
    protected string $name = 'GoogleAnalytics';
    protected Log $logger;
    private GoogleAnalyticsService $service;

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
        return [
            'GOOGLE_ANALYTICS_ID',
        ];
    }

    #[EventListener('POST_RENDER', priority: 500)]
    public function handlePostRender(RenderEvent $event): void
    {
        $siteConfig = $this->container->getVariable('site_config');

        // Check if enabled in site config
        if (empty($siteConfig['google_analytics']['enabled'])) {
            return;
        }

        // Get tracking ID from environment
        $trackingId = $_ENV['GOOGLE_ANALYTICS_ID'] ?? null;

        if (empty($trackingId)) {
            $this->logger->log('WARNING', 'Google Analytics enabled but GOOGLE_ANALYTICS_ID not set in environment');
            return;
        }

        // Only process HTML files
        $outputPath = $event->outputPath ?? '';
        if (pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return;
        }

        $content = $event->renderedContent ?? '';
        if (empty($content)) {
            return;
        }

        $event->renderedContent = $this->service->injectAnalytics($content, $trackingId);

        $this->logger->log('DEBUG', "Injected Google Analytics code into {$outputPath}");
    }
}
