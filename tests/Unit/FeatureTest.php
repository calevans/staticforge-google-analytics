<?php

namespace EICC\StaticForge\Tests\Unit\Features;

use Calevans\StaticForgeGoogleAnalytics\Feature;
use Calevans\StaticForgeGoogleAnalytics\Tests\TestCase;
use EICC\StaticForge\Core\EventManager;

class GoogleAnalyticsFeatureTest extends TestCase
{
    private Feature $feature;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventManager = new EventManager($this->container);
        $this->feature = new Feature();
        $this->feature->register($this->eventManager, $this->container);
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

        $parameters = [
            'output_path' => 'index.html',
            'rendered_content' => '<html><body></body></html>'
        ];

        $result = $this->feature->handlePostRender($this->container, $parameters);
        
        $this->assertEquals($parameters['rendered_content'], $result['rendered_content']);
    }

    public function testHandlePostRenderSkipsIfNoId(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        unset($_ENV['GOOGLE_ANALYTICS_ID']);

        $parameters = [
            'output_path' => 'index.html',
            'rendered_content' => '<html><body></body></html>'
        ];

        $result = $this->feature->handlePostRender($this->container, $parameters);
        
        $this->assertEquals($parameters['rendered_content'], $result['rendered_content']);
    }

    public function testHandlePostRenderSkipsIfNotHtml(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-TEST';

        $parameters = [
            'output_path' => 'style.css',
            'rendered_content' => 'body { color: red; }'
        ];

        $result = $this->feature->handlePostRender($this->container, $parameters);
        
        $this->assertEquals($parameters['rendered_content'], $result['rendered_content']);
    }

    public function testHandlePostRenderInjectsCode(): void
    {
        $this->setContainerVariable('site_config', [
            'google_analytics' => ['enabled' => true]
        ]);
        $_ENV['GOOGLE_ANALYTICS_ID'] = 'G-TEST';

        $content = '<html><body>Content</body></html>';
        $parameters = [
            'output_path' => 'index.html',
            'rendered_content' => $content
        ];

        $result = $this->feature->handlePostRender($this->container, $parameters);
        
        $this->assertStringContainsString('G-TEST', $result['rendered_content']);
        $this->assertStringContainsString('googletagmanager.com', $result['rendered_content']);
        $this->assertStringContainsString('</body>', $result['rendered_content']);
    }
}
