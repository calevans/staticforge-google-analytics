<?php

namespace Calevans\StaticForgeGoogleAnalytics;

use EICC\StaticForge\Core\BaseFeature;
use EICC\StaticForge\Core\FeatureInterface;
use EICC\StaticForge\Core\EventManager;
use Calevans\StaticForgeGoogleAnalytics\Services\GoogleAnalyticsService;
use EICC\Utils\Container;
use EICC\Utils\Log;

class Feature extends BaseFeature implements FeatureInterface
{
    protected string $name = 'GoogleAnalytics';
    protected Log $logger;
    private GoogleAnalyticsService $service;

    /**
     * @var array<string, array{method: string, priority: int}>
     */
    protected array $eventListeners = [
        'POST_RENDER' => ['method' => 'handlePostRender', 'priority' => 500]
    ];

    public function register(EventManager $eventManager, Container $container): void
    {
        parent::register($eventManager, $container);
        $this->logger = $container->get('logger');
        $this->service = new GoogleAnalyticsService($this->logger);

        $this->logger->log('INFO', 'Google Analytics Feature registered');
    }

    /**
     * Handle POST_RENDER event
     *
     * @param Container $container
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function handlePostRender(Container $container, array $parameters): array
    {
        $siteConfig = $container->getVariable('site_config');

        // Check if enabled in site config
        if (empty($siteConfig['google_analytics']['enabled'])) {
            return $parameters;
        }

        // Get tracking ID from environment
        $trackingId = $_ENV['GOOGLE_ANALYTICS_ID'] ?? null;

        if (empty($trackingId)) {
            $this->logger->log('WARNING', 'Google Analytics enabled but GOOGLE_ANALYTICS_ID not set in environment');
            return $parameters;
        }

        // Only process HTML files
        $outputPath = $parameters['output_path'] ?? '';
        if (pathinfo($outputPath, PATHINFO_EXTENSION) !== 'html') {
            return $parameters;
        }

        $content = $parameters['rendered_content'] ?? '';
        if (empty($content)) {
            return $parameters;
        }

        $parameters['rendered_content'] = $this->service->injectAnalytics($content, $trackingId);

        $this->logger->log('DEBUG', "Injected Google Analytics code into {$outputPath}");

        return $parameters;
    }
}
