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
        // Create container and logger (mock or real)
        $this->container = new Container();

        // Since we are mocking/stubbing, we can use a class that implements Log or mock it
        // Check if EICC\Utils\Log exists and can be substantiated
        if (class_exists(Log::class)) {
            $this->logger = new Log();
        } else {
             // Fallback mock if Utils not present (should be present via composer)
             $this->logger = $this->createMock(Log::class);
        }

        // Register logger in container using stuff to match bootstrap.php behavior
        // passing a closure that returns the logger instance
        $loggerInstance = $this->logger;
        $this->container->stuff('logger', function() use ($loggerInstance) {
            return $loggerInstance;
        });
    }
}