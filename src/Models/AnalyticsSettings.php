<?php

declare(strict_types=1);

namespace Calevans\StaticForgeGoogleAnalytics\Models;

class AnalyticsSettings
{
    /**
     * @param list<string> $exclude
     */
    public function __construct(
        public readonly string $trackingId,
        public readonly bool $debug,
        public readonly array $exclude,
    ) {
    }
}
