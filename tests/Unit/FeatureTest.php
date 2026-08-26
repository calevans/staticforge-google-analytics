<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Tests\Unit;

use Calevans\StaticForgeGoogleAnalytics\Feature;
use Calevans\StaticForgeGoogleAnalytics\Tests\TestCase;
use EICC\StaticForge\Core\Events\RenderEvent;
use EICC\StaticForge\Core\EventManager;
use EICC\StaticForge\Core\FeatureFactory;

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
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-TEST';

        $event = $this->makeEvent('index.html', '<html><body></body></html>');
        $this->feature->handlePostRender($event);

        $this->assertEquals('<html><body></body></html>', $event->renderedContent);
    }

    public function testHandlePostRenderSkipsIfNoId(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        unset($_ENV['GOOGLE_ANALYTICS_ID']);

        $event = $this->makeEvent('index.html', '<html><body></body></html>');
        $this->feature->handlePostRender($event);

        $this->assertEquals('<html><body></body></html>', $event->renderedContent);
    }

    public function testHandlePostRenderSkipsIfNotHtml(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-TEST';

        $event = $this->makeEvent('style.css', 'body { color: red; }');
        $this->feature->handlePostRender($event);

        $this->assertEquals('body { color: red; }', $event->renderedContent);
    }

    public function testHandlePostRenderInjectsCode(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-TEST';

        $event = $this->makeEvent('index.html', '<html><body>Content</body></html>');
        $this->feature->handlePostRender($event);

        $this->assertNotNull($event->renderedContent);
        $this->assertStringContainsString('G-TEST', $event->renderedContent);
        $this->assertStringContainsString('googletagmanager.com', $event->renderedContent);
        $this->assertStringContainsString('</body>', $event->renderedContent);
    }
}
