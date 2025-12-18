<?php

namespace Calevans\StaticForgeGoogleAnalytics\Services;

use EICC\Utils\Log;

class GoogleAnalyticsService
{
    private Log $logger;

    public function __construct(Log $logger)
    {
        $this->logger = $logger;
    }

    public function injectAnalytics(string $content, string $trackingId): string
    {
        $snippet = file_get_contents(__DIR__ . '/../assets/analytics.html');
        $snippet = str_replace('{{TRACKING_ID}}', $trackingId, $snippet);

        // Try to inject before </head> as recommended by Google
        if (strpos($content, '</head>') !== false) {
            return str_replace('</head>', $snippet . "\n</head>", $content);
        }

        // Fallback to before </body>
        if (strpos($content, '</body>') !== false) {
            return str_replace('</body>', $snippet . "\n</body>", $content);
        }

        // Fallback: append to end if no body tag
        return $content . "\n" . $snippet;
    }
}
