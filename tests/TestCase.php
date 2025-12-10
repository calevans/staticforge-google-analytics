<?php

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
        $this->logger = new Log();
        $this->container->setVariable('logger', $this->logger);
    }
}