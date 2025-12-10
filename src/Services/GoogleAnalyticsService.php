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
        $jsTemplatePath = __DIR__ . '/../assets/analytics.js';

        if (!file_exists($jsTemplatePath)) {
            $this->logger->log('ERROR', "Google Analytics JS template not found at {$jsTemplatePath}");
            return $content;
        }

        $jsContent = file_get_contents($jsTemplatePath);
        $jsContent = str_replace('{{TRACKING_ID}}', $trackingId, $jsContent);

        $scriptTag = "<script>\n" . $jsContent . "\n</script>";

        // Inject before </body>
        if (strpos($content, '</body>') !== false) {
            return str_replace('</body>', $scriptTag . "\n</body>", $content);
        }

        // Fallback: append to end if no body tag
        return $content . "\n" . $scriptTag;
    }
}
