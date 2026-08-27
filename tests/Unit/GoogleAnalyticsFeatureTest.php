<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Tests\Unit;

use Calevans\StaticForgeGoogleAnalytics\Feature;
use Calevans\StaticForgeGoogleAnalytics\Tests\TestCase;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureFactory;
use EICC\Utils\Container;
use EICC\Utils\Log;

class GoogleAnalyticsFeatureTest extends TestCase
{
    private Feature $feature;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventManager = new EventManager();

        $feature = (new FeatureFactory($this->container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);
        $this->feature = $feature;

        $this->feature->register($this->eventManager);
    }

    private function makeEvent(string $outputPath, string $renderedContent): RenderEvent
    {
        return new RenderEvent(
            name: 'POST_RENDER',
            filePath: '',
            fileUrl: '',
            metadata: [],
            renderedContent: $renderedContent,
            outputPath: $outputPath,
        );
    }

    public function testRegisterRegistersEvent(): void
    {
        $listeners = $this->eventManager->getListeners('POST_RENDER');
        $this->assertNotEmpty($listeners);
        // There might be other listeners, so we check if ours is in the list
        $found = false;
        foreach ($listeners as $listener) {
            if ($listener['callback'] == [$this->feature, 'handlePostRender']) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'GoogleAnalytics listener not found for POST_RENDER');
    }

    public function testHandlePostRenderSkipsIfDisabled(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => false]
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('index.html', '<html><body></body></html>');
        $this->feature->handlePostRender($event);

        $this->assertEquals('<html><body></body></html>', $event->renderedContent);
    }

    public function testHandlePostRenderSkipsIfNoId(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);

        $event = $this->makeEvent('index.html', '<html><body></body></html>');
        $this->feature->handlePostRender($event);

        $this->assertEquals('<html><body></body></html>', $event->renderedContent);
    }

    public function testHandlePostRenderSkipsIfNotHtml(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('style.css', 'body { color: red; }');
        $this->feature->handlePostRender($event);

        $this->assertEquals('body { color: red; }', $event->renderedContent);
    }

    public function testHandlePostRenderInjectsCode(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('index.html', '<html><body>Content</body></html>');
        $this->feature->handlePostRender($event);

        $this->assertNotNull($event->renderedContent);
        $this->assertStringContainsString('G-TEST', $event->renderedContent);
        $this->assertStringContainsString('googletagmanager.com', $event->renderedContent);
        $this->assertStringContainsString('</body>', $event->renderedContent);
    }

    /**
     * BC GUARANTEE: the pre-upgrade configuration shape — siteconfig
     * google_analytics.enabled true plus the container GOOGLE_ANALYTICS_ID,
     * nothing else set (no debug, no exclude, no kill switch) — must still
     * inject on every HTML page exactly as it did before this upgrade.
     */
    public function testBackwardCompatibilityPreUpgradeConfigStillInjectsOnEveryHtmlPage(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-LEGACY123');

        $first = $this->makeEvent('index.html', '<html><head></head><body>A</body></html>');
        $this->feature->handlePostRender($first);
        $second = $this->makeEvent('about.html', '<html><head></head><body>B</body></html>');
        $this->feature->handlePostRender($second);

        $this->assertStringContainsString('G-LEGACY123', (string) $first->renderedContent);
        $this->assertStringContainsString('googletagmanager.com', (string) $first->renderedContent);
        $this->assertStringContainsString('G-LEGACY123', (string) $second->renderedContent);
        $this->assertStringContainsString('googletagmanager.com', (string) $second->renderedContent);
    }

    public function testHandlePostRenderReturnsEarlyOnNullRenderedContent(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('index.html', '');
        $event->renderedContent = null;
        $this->feature->handlePostRender($event);

        $this->assertNull($event->renderedContent);
    }

    public function testHandlePostRenderReturnsEarlyOnEmptyStringRenderedContent(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('index.html', '');
        $this->feature->handlePostRender($event);

        $this->assertSame('', $event->renderedContent);
    }

    public function testHandlePostRenderProcessesUppercaseHtmlExtension(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);
        $this->setContainerVariable('GOOGLE_ANALYTICS_ID', 'G-TEST');

        $event = $this->makeEvent('INDEX.HTML', '<html><head></head><body>Content</body></html>');
        $this->feature->handlePostRender($event);

        $this->assertStringContainsString('googletagmanager.com', (string) $event->renderedContent);
    }

    /**
     * MEMOIZATION: resolveSettings() must run exactly once per build. The
     * no-tracking-id WARNING is the observable proxy for that — if
     * resolution ran per page, this WARNING would fire once per
     * handlePostRender() call instead of once for the whole test.
     */
    public function testSettingsAreResolvedOnceRegardlessOfPageCount(): void
    {
        $logger = $this->createMock(Log::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('WARNING', $this->stringContains('no tracking id configured'));

        $container = new Container();
        $container->stuff('logger', $logger);
        $container->setVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);

        // register() is intentionally skipped here: it emits its own INFO
        // log, which would collide with the once() expectation above. The
        // memoization guarantee under test is about resolveSettings(), not
        // about register(), so calling handlePostRender() directly is
        // sufficient and keeps the mock expectation single-purpose.
        $feature = (new FeatureFactory($container))->make(Feature::class);
        $this->assertInstanceOf(Feature::class, $feature);

        foreach (['a.html', 'b.html', 'c.html'] as $path) {
            $event = $this->makeEvent($path, '<html><head></head><body>x</body></html>');
            $feature->handlePostRender($event);
            $this->assertStringNotContainsString('googletagmanager.com', (string) $event->renderedContent);
        }
    }

    /**
     * REGRESSION: the feature must read GOOGLE_ANALYTICS_ID from the
     * Container, not the $_ENV superglobal — locking in the migration
     * away from $_ENV.
     */
    public function testTrackingIdIsReadFromContainerNotEnvSuperglobal(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true],
        ]);

        $previousEnvValue = $_ENV['GOOGLE_ANALYTICS_ID'] ?? null;
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-FROMENV12';

        try {
            $event = $this->makeEvent('index.html', '<html><head></head><body>x</body></html>');
            $this->feature->handlePostRender($event);

            $this->assertStringNotContainsString('G-FROMENV12', (string) $event->renderedContent);
            $this->assertStringNotContainsString('googletagmanager.com', (string) $event->renderedContent);
        } finally {
            if ($previousEnvValue === null) {
                unset($_ENV['GOOGLE_ANALYTICS_ID']);
            } else {
                $_ENV['GOOGLE_ANALYTICS_ID'] = $previousEnvValue;
            }
        }
    }

    public function testGetRequiredEnvReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->feature->getRequiredEnv());
    }

    public function testGetRequiredConfigReturnsEnabledKey(): void
    {
        $this->assertSame(['google_analytics.enabled'], $this->feature->getRequiredConfig());
    }

    public function testGetConfigHelpReturnsNonEmptyStringForKnownKey(): void
    {
        $help = $this->feature->getConfigHelp('google_analytics.enabled');

        $this->assertIsString($help);
        $this->assertNotSame('', $help);
    }

    public function testGetConfigHelpReturnsNullForUnknownKey(): void
    {
        $this->assertNull($this->feature->getConfigHelp('nonexistent.key'));
    }
}
