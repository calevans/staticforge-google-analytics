<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use EICC\Utils\Container;
use EICC\Utils\Log;

class TestCase extends BaseTestCase
{
    protected Container $container;
    protected Log $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container();
        $this->logger = $this->createMock(Log::class);
        $this->container->stuff('logger', $this->logger);
    }

    protected function setContainerVariable(string $key, mixed $value): void
    {
        if ($this->container->hasVariable($key)) {
            $this->container->updateVariable($key, $value);
        } else {
            $this->container->setVariable($key, $value);
        }
    }
}
