<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Tests\Integration;

use Calevans\StaticForgeGoogleAnalytics\Feature;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\FeatureFactory;
use EICC\Utils\Container;
use EICC\Utils\Log;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the feature through the REAL StaticForge wiring: a real
 * Container, a real Log writing to a real file, a real FeatureFactory doing
 * constructor autowiring, and a real EventManager dispatching a real
 * RenderEvent. Nothing in this file is mocked — the point is to prove the
 * production wiring path actually works end to end, not just the isolated
 * units.
 */
class BuildPipelineTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = tempnam(sys_get_temp_dir(), 'ga-integration-') . '.log';
        touch($this->logFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    public function testFeatureIsAutowirableByFeatureFactory(): void
    {
        $container = new Container();
        $container->stuff('logger', new Log('ga-test', $this->logFile, 'DEBUG'));
        $container->setVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $container->setVariable('GOOGLE_ANALYTICS_ID', 'G-INTEGRATION1');

        $feature = (new FeatureFactory($container))->make(Feature::class);

        $this->assertInstanceOf(Feature::class, $feature);
    }

    public function testEventListenerAttributeIsDiscoveredAtPriority500(): void
    {
        $container = new Container();
        $container->stuff('logger', new Log('ga-test', $this->logFile, 'DEBUG'));
        $container->setVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $container->setVariable('GOOGLE_ANALYTICS_ID', 'G-INTEGRATION1');

        $feature = (new FeatureFactory($container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);

        $eventManager = new EventManager();
        $feature->register($eventManager);

        $listeners = $eventManager->getListeners('POST_RENDER');
        $found = null;
        foreach ($listeners as $listener) {
            if ($listener['callback'] === [$feature, 'handlePostRender']) {
                $found = $listener;
                break;
            }
        }

        $this->assertNotNull($found, 'GoogleAnalytics handlePostRender was not registered for POST_RENDER');
        $this->assertSame(500, $found['priority']);
    }

    public function testRealPostRenderEventInjectsAnalyticsTag(): void
    {
        $container = new Container();
        $container->stuff('logger', new Log('ga-test', $this->logFile, 'DEBUG'));
        $container->setVariable('site_config', [
            'google_analytics' => [
                'enabled' => true,
                'debug' => false,
            ],
        ]);
        $container->setVariable('GOOGLE_ANALYTICS_ID', 'G-INTEGRATION1');
        $container->setVariable('OUTPUT_DIR', '/srv/site/public');

        $feature = (new FeatureFactory($container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);

        $eventManager = new EventManager();
        $feature->register($eventManager);

        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: '/src/pages/index.md',
            fileUrl: '/index.html',
            metadata: [],
            renderedContent: '<html><head><title>Home</title></head><body>Welcome</body></html>',
            outputPath: '/srv/site/public/index.html',
        );

        $returned = $eventManager->fire('POST_RENDER', $event);

        $this->assertSame($event, $returned);
        $this->assertNotNull($event->renderedContent);
        $this->assertStringContainsString('G-INTEGRATION1', $event->renderedContent);
        $this->assertStringContainsString('googletagmanager.com', $event->renderedContent);
        $this->assertStringContainsString('</head>', $event->renderedContent);
    }

    public function testRealPostRenderEventSkipsExcludedPage(): void
    {
        $container = new Container();
        $container->stuff('logger', new Log('ga-test', $this->logFile, 'DEBUG'));
        $container->setVariable('site_config', [
            'google_analytics' => [
                'enabled' => true,
                'exclude' => ['drafts/*'],
            ],
        ]);
        $container->setVariable('GOOGLE_ANALYTICS_ID', 'G-INTEGRATION1');
        $container->setVariable('OUTPUT_DIR', '/srv/site/public');

        $feature = (new FeatureFactory($container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);

        $eventManager = new EventManager();
        $feature->register($eventManager);

        $original = '<html><head><title>Draft</title></head><body>Draft content</body></html>';
        $event = new RenderEvent(
            name: 'POST_RENDER',
            filePath: '/src/pages/drafts/upcoming.md',
            fileUrl: '/drafts/upcoming.html',
            metadata: [],
            renderedContent: $original,
            outputPath: '/srv/site/public/drafts/upcoming.html',
        );

        $eventManager->fire('POST_RENDER', $event);

        $this->assertSame($original, $event->renderedContent);
    }
}
